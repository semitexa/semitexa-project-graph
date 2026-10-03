<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

use Semitexa\ProjectGraph\Application\Service\Coverage\UnreadCode;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Extractor\ConfigReferenceExtractor;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileStatus;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

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

    /** How many rounds of take-over re-reads one batch may need. */
    private const REREAD_ROUNDS = 5;

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
        return $this->exclusively($projectRoot, fn (): UpdateResult => $this->rebuild($projectRoot));
    }

    /** The full build itself; the caller holds the lock. */
    private function rebuild(string $projectRoot): UpdateResult
    {
        $result = $this->storage->transaction(function () use ($projectRoot): UpdateResult {
            $this->storage->truncate();

            // Every edge is "added" on a full build; keeping ~67k of them in
            // the result would cost memory and say nothing.
            return $this->doUpdate($projectRoot, keepEdgeLists: false);
        });
        \assert($result instanceof UpdateResult);

        return $result;
    }

    public function update(string $projectRoot, bool $keepEdgeLists = true): UpdateResult
    {
        // One transaction for the whole refresh. The main batch, the passes
        // that re-read dependents and the meta committed separately, so a
        // reader saw states that were neither the old graph nor the new one,
        // and a process killed between them left a route stale for good —
        // its holder was already indexed (round 2 fuzzing, 2026-10-02).
        return $this->exclusively($projectRoot, fn (): UpdateResult => $this->storage->transaction(fn (): UpdateResult => $this->doUpdate($projectRoot, $keepEdgeLists)));
    }

    private bool $locking = true;

    /** For a throwaway in-memory graph (the diff's two sides): nothing to serialise. */
    public function withoutLocking(): self
    {
        $engine = clone $this;
        $engine->locking = false;

        return $engine;
    }

    /**
     * One writer at a time. SQLite's deferred BEGIN made a second refresh fail
     * at its first write with "database is locked" instead of waiting — and a
     * command's auto-refresh then answered from the stale graph (measured
     * 2026-10-02 with two parallel refreshes). The lock is a file beside the
     * project's own var/tmp, so the app container and a host CLI share it;
     * whoever comes second waits, then finds nothing left to do.
     *
     * Taken once, at the public entry points; what runs under it calls the
     * unlocked rebuild()/doUpdate(). A static depth counter used to stand for
     * "the lock is already ours" — shared by every coroutine of a worker, so
     * a second refresh in the same process skipped the lock while the first
     * held it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function exclusively(string $projectRoot, callable $work): mixed
    {
        if (!$this->locking) {
            return $work();
        }
        $dir = rtrim($projectRoot, '/') . '/var/tmp';
        $path = $dir . '/.project-graph.lock';
        $handle = null;
        // Only where the project already keeps var/tmp: creating it would drop
        // an untracked directory into a package repository the diff reads.
        if (is_dir($dir) && is_writable($dir) || is_file($path)) {
            $handle = @fopen($path, 'c') ?: (@fopen($path, 'r') ?: null);
            if ($handle !== null && @fileowner($path) === getmyuid()) {
                @chmod($path, 0666);
            }
        }
        // No lock file possible (read-only tree): run unlocked, as before.
        if ($handle !== null && !flock($handle, LOCK_EX)) {
            fclose($handle);
            $handle = null;
        }

        try {
            return $work();
        } finally {
            if ($handle !== null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    private function doUpdate(string $projectRoot, bool $keepEdgeLists): UpdateResult
    {
        $timer = microtime(true);

        $indexedFiles = $this->storage->fileIndex->getAll();
        // A graph the current extractors did not build is re-derived whole: a
        // refresh re-reads only changed files, so a fix to an extractor (or a
        // graph built before a table existed) never reached the files that did
        // not change. Measured 2026-10-02: a graph without the gaps table
        // stayed "no gaps" through every refresh; a full build found 368.
        if ($indexedFiles !== [] && $this->storage->getMeta('build_version') !== self::buildVersion()) {
            return $this->rebuild($projectRoot);
        }
        // Findings judge "is this test code" relative to it (see TestCode).
        $this->storage->setMeta('project_root', rtrim($projectRoot, '/'));
        $changes = $this->scanner->scan($projectRoot, $indexedFiles);
        $this->storage->setMeta('coverage_exclusions', (string) json_encode($this->scanner->lastExclusions()));

        if (empty($changes)) {
            // A scan that found nothing to change still proves the graph current
            // as of now. Without this, a file touched but not edited (a checkout,
            // a reverted edit) stayed newer than last_update forever, and the
            // viewer's "stale" could not be cleared by the command it names.
            $this->storage->setMeta('last_update', (string) time());

            return UpdateResult::noChanges();
        }

        $this->root = rtrim($projectRoot, '/');
        $fresh = $indexedFiles === [];
        $totals = self::emptyTotals();
        $errors = [];
        $batch = [];
        $indexUpdates = [];

        // What leaves the graph goes first. A moved file is one deletion and
        // one addition; applied addition-first, the class's new file was
        // refused as a duplicate of the old path, which still held it, and
        // the deletion then removed the class — for good, since the new file
        // was already indexed. Measured 2026-10-02: renaming one directory
        // left 6 of 19 nodes; one host refresh of a container-built graph
        // (every path "moved") left 10 of 7493.
        usort($changes, static fn ($a, $b): int => self::applyOrder($a->status) <=> self::applyOrder($b->status));

        $declaredInChanged = [];
        $processed = [];
        foreach ($changes as $change) {
            $processed[$change->path] = true;
            // What the file declared BEFORE counts as changed too: a holder
            // that stops declaring its class (emptied, or broken) still
            // changes every route that read its constant (round 2).
            if ($change->status !== FileStatus::Added && !$fresh) {
                foreach ($this->storage->nodes->getNodeIdsByFile($change->path) as $id) {
                    $declaredInChanged[$id] = true;
                }
            }
            if ($change->status === FileStatus::Deleted) {
                $batch[$change->path] = ExtractionResult::empty();
                $indexUpdates[$change->path] = null;
            } else {
                $batch[$change->path] = $this->extractFile($projectRoot, $change->path, $errors);
                $indexUpdates[$change->path] = $change->hash;
                foreach ($batch[$change->path]->declaredClasses as $fqcn) {
                    $declaredInChanged[NodeId::forClass($fqcn)] = true;
                }
            }

            if (count($batch) >= self::BATCH_SIZE) {
                $this->applyBatch($batch, $indexUpdates, $totals, $errors, $keepEdgeLists);
                $batch = [];
                $indexUpdates = [];
            }
        }

        $this->applyBatch($batch, $indexUpdates, $totals, $errors, $keepEdgeLists);
        // A full build reads every file already: looking for dependents there
        // materialised every inbound edge for nothing — +26 MB, the build
        // peaking at 113 MB of a 128 MB limit (round 2).
        if (!$fresh) {
            $this->reextractConstantDependents($projectRoot, array_keys($declaredInChanged), $processed, $totals, $errors, $keepEdgeLists);
        }
        $this->repairOrphanedDeclarations($projectRoot, $totals, $errors, $keepEdgeLists);
        $this->storage->nodes->reconcileResourceRoles();

        $this->storage->setMeta('last_update', (string)time());
        $this->storage->setMeta('build_version', self::buildVersion());
        $this->storage->setMeta('total_nodes', (string)$this->storage->nodes->countAll());
        $this->storage->setMeta('total_edges', (string)$this->storage->edges->countAll());
        // The module map findings and coverage read; stored now, while writing.
        UnreadCode::warm($this->storage);

        return new UpdateResult(
            filesScanned:   count($changes),
            filesErrored:   count($errors),
            nodesAdded:     count(array_filter($totals['nodes'], static fn (int $n): bool => $n > 0)),
            nodesRemoved:   count(array_filter($totals['nodes'], static fn (int $n): bool => $n < 0)),
            edgesAdded:     count(array_filter($totals['edges'], static fn (int $n): bool => $n > 0)),
            edgesRemoved:   count(array_filter($totals['edges'], static fn (int $n): bool => $n < 0)),
            duration:       (int)((microtime(true) - $timer) * 1000),
            errors:         $errors,
            addedEdges:     array_values(array_intersect_key($totals['addedEdges'], array_filter($totals['edges'], static fn (int $n): bool => $n > 0))),
            removedEdges:   array_values(array_intersect_key($totals['removedEdges'], array_filter($totals['edges'], static fn (int $n): bool => $n < 0))),
        );
    }

    private static ?string $buildVersion = null;

    /** What built the graph: the extraction code itself, so any change to it counts. */
    public static function buildVersion(): string
    {
        if (self::$buildVersion !== null) {
            return self::$buildVersion;
        }
        // The whole package source: the engine, the scanner and the storage
        // decide graph content as much as the extractors (round 2: the
        // src/modules mapping changed and old graphs kept module "App").
        $dirs = [dirname(__DIR__, 3)];
        $files = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        $context = hash_init('xxh3');
        foreach ($files as $file) {
            hash_update($context, substr($file, strlen(dirname(__DIR__, 3))) . "\0" . (string) hash_file('xxh3', $file));
        }

        return self::$buildVersion = hash_final($context);
    }

    private string $root = '';

    /** @return array{nodes: array<string, int>, edges: array<string, int>, addedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>, removedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>} */
    private static function emptyTotals(): array
    {
        return ['nodes' => [], 'edges' => [], 'addedEdges' => [], 'removedEdges' => []];
    }

    private function mergeDiff(GraphDiff $into, GraphDiff $from): void
    {
        foreach ($from->addedNodes() as $node) {
            $into->addNode($node);
        }
        foreach ($from->removedNodeIds() as $id) {
            $into->removeNode($id);
        }
        foreach ($from->addedEdges() as $edge) {
            $into->addEdge($edge);
        }
        foreach ($from->removedEdges() as $edge) {
            $into->removeEdge($edge);
        }
    }

    private static function applyOrder(FileStatus $status): int
    {
        return match ($status) {
            FileStatus::Deleted => 0,
            FileStatus::Modified => 1,
            default => 2,
        };
    }

    /**
     * @param list<array{file: string, message: string}> $errors
     */
    private function extractFile(string $projectRoot, string $path, array &$errors): ExtractionResult
    {
        if (ConfigReferenceExtractor::handles($path)) {
            return (new ConfigReferenceExtractor())->extract(
                $path,
                (string) file_get_contents($path),
                $this->resolveModule($projectRoot, $path),
            );
        }

        try {
            $parsed = $this->parser->parse($path, $this->resolveModule($projectRoot, $path));
            $extracted = $this->extractors->process($parsed);
            if ($extracted->failures !== []) {
                $errors[] = ['file' => $path, 'message' => implode('; ', $extracted->failures)];
            }

            return $extracted;
        } catch (\Throwable $e) {
            // The file stays in the index (so an unchanged broken file
            // is not re-parsed on every refresh) and what it used to
            // declare is removed: the graph shows what can be read now,
            // and the gap says why this file contributes nothing.
            $errors[] = ['file' => $path, 'message' => $e->getMessage()];
            $broken = ExtractionResult::empty();
            $broken->gaps[] = new CoverageGap(CoverageGapKind::ParseError, $path, $e->getMessage(), '', self::lineOf($e));

            return $broken;
        }
    }

    /** The line a parse error names ("... on line 3"); it was stored as 0. */
    private static function lineOf(\Throwable $e): int
    {
        if ($e instanceof \PhpParser\Error && $e->getStartLine() > 0) {
            return $e->getStartLine();
        }

        return preg_match('/on line (\d+)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * An attribute argument can be another class's constant —
     * `#[AsPublicPayload(path: Routes::ORDERS)]` — and its value is read at
     * extraction time. Change the constant and only Routes.php was re-read, so
     * the route kept its old path until something touched the payload's file
     * (measured 2026-10-02). Files whose classes read a constant of a class
     * declared in a changed file are re-read too.
     *
     * @param list<string>        $changedClassIds
     * @param array<string, true> $processed files already re-read in this run
     * @param array{nodes: array<string, int>, edges: array<string, int>, addedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>, removedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>} $totals
     * @param list<array{file: string, message: string}> $errors
     */
    private function reextractConstantDependents(string $projectRoot, array $changedClassIds, array $processed, array &$totals, array &$errors, bool $keepEdgeLists): void
    {
        // To a fixed point: Holder::K = Routes::ORDERS is two hops, and the
        // payload reading Holder::K changes when Routes does (round 2).
        for ($round = 0; $round < 10 && $changedClassIds !== []; $round++) {
            $files = [];
            foreach ($this->storage->edges->sourceFilesReadingConstantsOf($changedClassIds) as $file) {
                if (!isset($processed[$file]) && is_file($file)) {
                    $files[$file] = true;
                }
            }
            if ($files === []) {
                return;
            }

            $batch = [];
            $indexUpdates = [];
            $changedClassIds = [];
            foreach (array_keys($files) as $path) {
                $processed[$path] = true;
                $batch[$path] = $this->extractFile($projectRoot, $path, $errors);
                $hash = hash_file('xxh3', $path);
                $indexUpdates[$path] = $hash === false ? null : $hash;
                foreach ($batch[$path]->declaredClasses as $fqcn) {
                    $changedClassIds[] = NodeId::forClass($fqcn);
                }
            }
            $this->applyBatch($batch, $indexUpdates, $totals, $errors, $keepEdgeLists);
        }
    }

    /**
     * A class two files declare is stored from one of them; the other file
     * records a duplicate_class gap and contributes nothing for it. When the
     * owning file goes or stops declaring it, nothing re-read the other copy,
     * so the class stayed a placeholder that a full build would have filled.
     * Re-read every such file until no gap points at a class nobody holds.
     *
     * @param array{nodes: array<string, int>, edges: array<string, int>, addedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>, removedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>} $totals
     * @param list<array{file: string, message: string}> $errors
     */
    private function repairOrphanedDeclarations(string $projectRoot, array &$totals, array &$errors, bool $keepEdgeLists): void
    {
        $tried = [];
        for ($round = 0; $round < 3; $round++) {
            $files = [];
            foreach ($this->storage->gaps->findAll(CoverageGapKind::DuplicateClass) as $gap) {
                $node = $this->storage->nodes->findById(NodeId::forClass($gap->getSubject()));
                $held = $node !== null && !$node->getIsPlaceholder() && $node->getFile() !== '' && is_file($node->getFile());
                // The gap also goes stale when the class moved to a third
                // file: it named a deleted path as the holder (round 2).
                $namesHolder = $held && str_ends_with($gap->getDetail(), ' ' . $node->getFile());
                if ((!$held || !$namesHolder) && is_file($gap->getFile())) {
                    $files[$gap->getFile()] = true;
                }
            }
            // A node many files emit (a module's domain, a route two payloads
            // serve) is stored from the first; when that file goes, the node
            // is left a placeholder another file would fill. Re-read a file
            // still pointing at it (round 2: domain:Billing, a shared route).
            foreach ($this->storage->lookup->orphanedSharedNodes() as $id) {
                foreach ($this->storage->edges->sourceFilesPointingAt($id, 3) as $file) {
                    if (is_file($file) && !isset($tried[$id . "\0" . $file])) {
                        $tried[$id . "\0" . $file] = true;
                        $files[$file] = true;
                        break;
                    }
                }
            }
            if ($files === []) {
                return;
            }

            $batch = [];
            $indexUpdates = [];
            foreach (array_keys($files) as $path) {
                $batch[$path] = $this->extractFile($projectRoot, $path, $errors);
                $hash = hash_file('xxh3', $path);
                $indexUpdates[$path] = $hash === false ? null : $hash;
            }
            $this->applyBatch($batch, $indexUpdates, $totals, $errors, $keepEdgeLists);
        }
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
     * @param array{nodes: array<string, int>, edges: array<string, int>, addedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>, removedEdges: array<string, \Semitexa\ProjectGraph\Domain\Model\Edge>} $totals
     * @param list<array{file: string, message: string}> $errors the run's: a re-read that fails is reported like any other read
     */
    private function applyBatch(array $batch, array $indexUpdates, array &$totals, array &$errors, bool $keepEdgeLists): void
    {
        if ($batch === []) {
            return;
        }

        $diff = $this->storage->transaction(function () use ($batch, $indexUpdates, &$errors) {
            $diff = $this->builder->apply($batch);
            // Files that lost a class to a smaller path are read again now,
            // so they keep everything else they declare.
            for ($round = 0; $round < self::REREAD_ROUNDS && ($rereads = $this->builder->takeRereads()) !== []; $round++) {
                $again = [];
                foreach ($rereads as $path) {
                    // Even a file this batch already read: the take-over removed
                    // what it declared, and only reading it again restores the
                    // rest (round-3 fuzzing, seed 2132: a multi-class holder
                    // lost its other class).
                    if (is_file($path)) {
                        $failed = [];
                        $again[$path] = $this->extractFile($this->root, $path, $failed);
                        // Its errors were dropped, so filesErrored left the file out.
                        foreach ($failed as $error) {
                            if (!in_array($error, $errors, true)) {
                                $errors[] = $error;
                            }
                        }
                    }
                }
                if ($again !== []) {
                    $this->mergeDiff($diff, $this->builder->apply($again));
                }
            }
            // Each round needs a new take-over, so this is not expected to be
            // reached; if it is, a file is left without what it declares and
            // the graph is not what a full build gives. Said, not dropped.
            foreach ($this->builder->takeRereads() as $path) {
                $errors[] = ['file' => $path, 'message' => sprintf('Not re-read after losing a class: more than %d rounds of take-overs. Run a full build.', self::REREAD_ROUNDS)];
            }

            foreach ($indexUpdates as $path => $hash) {
                $hash === null
                    ? $this->storage->fileIndex->remove($path)
                    : $this->storage->fileIndex->upsert($path, $hash, $this->resolveModule($this->root, $path));
            }

            return $diff;
        });

        // Netted across every batch and pass: a file move reported +1/-1 node
        // when nothing changed, a split +1/+1/-1/-1 (round 2). What one pass
        // removed and a later one restored is no change.
        foreach ($diff->addedNodes() as $node) {
            $totals['nodes'][$node->getId()] = ($totals['nodes'][$node->getId()] ?? 0) + 1;
        }
        foreach ($diff->removedNodeIds() as $id) {
            $totals['nodes'][$id] = ($totals['nodes'][$id] ?? 0) - 1;
        }
        foreach ($diff->addedEdges() as $edge) {
            $key = GraphDiff::edgeKey($edge);
            $totals['edges'][$key] = ($totals['edges'][$key] ?? 0) + 1;
            if ($keepEdgeLists) {
                $totals['addedEdges'][$key] = $edge;
            }
        }
        foreach ($diff->removedEdges() as $edge) {
            $key = GraphDiff::edgeKey($edge);
            $totals['edges'][$key] = ($totals['edges'][$key] ?? 0) - 1;
            if ($keepEdgeLists) {
                $totals['removedEdges'][$key] = $edge;
            }
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
        /** @var list<\Semitexa\ProjectGraph\Domain\Model\Edge> edges this update added (empty on a full build) */
        public array $addedEdges = [],
        /** @var list<\Semitexa\ProjectGraph\Domain\Model\Edge> edges this update removed */
        public array $removedEdges = [],
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
        return ModuleOfPath::of($projectRoot, $filePath);
    }
}
