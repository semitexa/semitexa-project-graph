<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Index;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Scanner\FileStatus;

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

    public function fullBuild(string $projectRoot): UpdateResult
    {
        $this->storage->truncate();

        return $this->update($projectRoot);
    }

    public function update(string $projectRoot): UpdateResult
    {
        $timer = microtime(true);

        $indexedFiles = $this->storage->fileIndex->getAll();
        $changes = $this->scanner->scan($projectRoot, $indexedFiles);

        if (empty($changes)) {
            return UpdateResult::noChanges();
        }

        $fileResults = [];
        $errors = [];

        foreach ($changes as $change) {
            if ($change->status === FileStatus::Deleted) {
                $fileResults[$change->path] = \Semitexa\ProjectGraph\Application\Extractor\ExtractionResult::empty();
                $this->storage->fileIndex->remove($change->path);
                continue;
            }

            try {
                $parsed = $this->parser->parse($change->path, $this->resolveModule($projectRoot, $change->path));
                $fileResults[$change->path] = $this->extractors->process($parsed);
                $this->storage->fileIndex->upsert($change->path, $change->hash);
            } catch (\Throwable $e) {
                $errors[] = ['file' => $change->path, 'message' => $e->getMessage()];
            }
        }

        $diff = $this->builder->apply($fileResults);

        $this->storage->setMeta('last_update', (string)time());
        $this->storage->setMeta('total_nodes', (string)$this->storage->nodes->countAll());
        $this->storage->setMeta('total_edges', (string)$this->storage->edges->countAll());

        return new UpdateResult(
            filesScanned:   count($changes),
            filesErrored:   count($errors),
            nodesAdded:     $diff->addedNodeCount(),
            nodesRemoved:   $diff->removedNodeCount(),
            edgesAdded:     $diff->addedEdgeCount(),
            edgesRemoved:   $diff->removedEdgeCount(),
            duration:       (int)((microtime(true) - $timer) * 1000),
            errors:         $errors,
        );
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
