<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileStatus;

final class IncrementalEngine
{
    use IncrementalEngineModuleResolver;

    public function __construct(
        private readonly FileScanner $scanner,
        private readonly PhpParserAdapter $parser,
        private readonly ExtractorPipeline $extractors,
        private readonly GraphBuilder $builder,
        private readonly GraphStorage $storage,
    ) {}

    /** Files applied per transaction: bounds how many extraction results are held at once. */
    private const BATCH_SIZE = 200;

    /**
     * Rebuild the graph from nothing — or not at all.
     *
     * The truncate and the whole rebuild run in one transaction, so a build
     * that dies halfway (measured 2026-09-30: the workspace build exhausted
     * PHP's default 128M) rolls back to the previous graph instead of
     * committing an empty or partial one that later refreshes build on.
     */
    public function fullBuild(string $projectRoot): UpdateResult
    {
        self::raiseMemoryCeilingForFullBuild();

        return $this->storage->transaction(function () use ($projectRoot): UpdateResult {
            $this->storage->truncate();

            return $this->update($projectRoot);
        });
    }

    public function update(string $projectRoot): UpdateResult
    {
        $timer = microtime(true);

        $indexedFiles = $this->storage->fileIndex->getAll();
        $changes = $this->scanner->scan($projectRoot, $indexedFiles);

        if (empty($changes)) {
            return UpdateResult::noChanges();
        }

        $totals = ['nodesAdded' => 0, 'nodesRemoved' => 0, 'edgesAdded' => 0, 'edgesRemoved' => 0];
        $errors = [];
        $batch = [];
        $indexUpdates = [];

        foreach ($changes as $change) {
            if ($change->status === FileStatus::Deleted) {
                $batch[$change->path] = ExtractionResult::empty();
                $indexUpdates[$change->path] = null;
            } else {
                try {
                    $parsed = $this->parser->parse($change->path, $this->resolveModule($projectRoot, $change->path));
                    $batch[$change->path] = $this->extractors->process($parsed);
                    $indexUpdates[$change->path] = $change->hash;
                } catch (\Throwable $e) {
                    $errors[] = ['file' => $change->path, 'message' => $e->getMessage()];
                }
            }

            if (count($batch) >= self::BATCH_SIZE) {
                $this->applyBatch($batch, $indexUpdates, $totals);
                $batch = [];
                $indexUpdates = [];
            }
        }

        $this->applyBatch($batch, $indexUpdates, $totals);

        $this->storage->setMeta('last_update', (string)time());
        $this->storage->setMeta('total_nodes', (string)$this->storage->nodes->countAll());
        $this->storage->setMeta('total_edges', (string)$this->storage->edges->countAll());

        return new UpdateResult(
            filesScanned:   count($changes),
            filesErrored:   count($errors),
            nodesAdded:     $totals['nodesAdded'],
            nodesRemoved:   $totals['nodesRemoved'],
            edgesAdded:     $totals['edgesAdded'],
            edgesRemoved:   $totals['edgesRemoved'],
            duration:       (int)((microtime(true) - $timer) * 1000),
            errors:         $errors,
        );
    }

    /**
     * Apply a batch and record its files as indexed in the SAME transaction.
     *
     * The file index used to be written as each file was extracted and the
     * graph only at the very end, so a run that died in between left files
     * marked indexed whose nodes were never stored — and no later refresh
     * would look at them again.
     *
     * @param array<string, ExtractionResult> $batch
     * @param array<string, ?string> $indexUpdates path => content hash, or null for a deleted file
     * @param array{nodesAdded: int, nodesRemoved: int, edgesAdded: int, edgesRemoved: int} $totals
     */
    private function applyBatch(array $batch, array $indexUpdates, array &$totals): void
    {
        if ($batch === []) {
            return;
        }

        $diff = $this->storage->transaction(function () use ($batch, $indexUpdates) {
            $diff = $this->builder->apply($batch);

            foreach ($indexUpdates as $path => $hash) {
                $hash === null
                    ? $this->storage->fileIndex->remove($path)
                    : $this->storage->fileIndex->upsert($path, $hash);
            }

            return $diff;
        });

        $totals['nodesAdded'] += $diff->addedNodeCount();
        $totals['nodesRemoved'] += $diff->removedNodeCount();
        $totals['edgesAdded'] += $diff->addedEdgeCount();
        $totals['edgesRemoved'] += $diff->removedEdgeCount();
    }

    /**
     * A full build needs more than PHP's default 128M: the parser reflects every
     * class it reads, and a class once loaded stays loaded (measured 2026-09-30:
     * ~110M for the workspace's 4899 classes, before the framework's own boot).
     * Only a ceiling below 1G is raised; an explicit larger one or -1 is kept.
     * Reading attributes from the AST instead (graph-integrity-ast-attributes)
     * removes the cause.
     */
    private static function raiseMemoryCeilingForFullBuild(): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') {
            return;
        }

        $bytes = (int) $limit;
        $unit = strtolower(substr($limit, -1));
        $bytes *= match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        if ($bytes < 1024 ** 3) {
            ini_set('memory_limit', '1G');
        }
    }
}

final readonly class UpdateResult
{
    public function __construct(
        public int   $filesScanned,
        public int   $filesErrored,
        public int   $nodesAdded,
        public int   $nodesRemoved,
        public int   $edgesAdded,
        public int   $edgesRemoved,
        public int   $duration,
        /** @var list<array{file: string, message: string}> */
        public array $errors,
    ) {}

    public function isNoChanges(): bool
    {
        return $this->filesScanned === 0;
    }

    public static function noChanges(): self
    {
        return new self(0, 0, 0, 0, 0, 0, 0, []);
    }

    public function toArray(): array
    {
        return [
            'files_scanned'  => $this->filesScanned,
            'files_errored'  => $this->filesErrored,
            'nodes_added'    => $this->nodesAdded,
            'nodes_removed'  => $this->nodesRemoved,
            'edges_added'    => $this->edgesAdded,
            'edges_removed'  => $this->edgesRemoved,
            'duration_ms'    => $this->duration,
            'errors'         => $this->errors,
        ];
    }
}

trait IncrementalEngineModuleResolver
{
    private function resolveModule(string $projectRoot, string $filePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $filePath);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/packages/')) {
            $relative = substr($normalizedPath, strlen($normalizedRoot . '/packages/'));
            $package = explode('/', $relative, 2)[0] ?? '';

            if ($package !== '') {
                $package = preg_replace('/^semitexa-/', '', $package) ?? $package;
                return $this->studly($package);
            }
        }

        if (str_starts_with($normalizedPath, $normalizedRoot . '/src/')
            || str_starts_with($normalizedPath, $normalizedRoot . '/tests/')
        ) {
            return 'App';
        }

        return '';
    }

    private function studly(string $value): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $value) ?: [];
        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        return implode('', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            $parts,
        ));
    }
}
