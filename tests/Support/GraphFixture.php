<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Index\UpdateResult;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

/**
 * The fixture project in tests/Fixture/GraphProject, scanned for real.
 *
 * Seeded rows only prove what the test author believed the extractors emit.
 * This runs the actual scanner, parser, extractors and builder over real PHP,
 * on a throwaway copy, so a test can edit or delete a file and refresh the
 * graph the way `ai:review-graph:watch` does.
 *
 * The parser reads every attribute from the copied file itself, so nothing
 * here depends on the process being able to load a fixture class (the
 * originals happen to be autoloadable; the copies never are).
 */
final class GraphFixture
{
    public const NS = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\';

    private const SOURCE = __DIR__ . '/../Fixture/GraphProject';

    public readonly string $root;
    public readonly GraphStorage $storage;
    private readonly GraphTestStore $store;
    private readonly IncrementalEngine $engine;

    private function __construct(bool $withBrokenFile)
    {
        $this->root = sys_get_temp_dir() . '/semitexa-graph-fixture-' . bin2hex(random_bytes(6));
        self::copyTree(self::SOURCE, $this->root);

        if ($withBrokenFile) {
            copy($this->root . '/Broken.php.txt', $this->root . '/Broken.php');
        }

        $this->store = GraphTestStore::create();
        $this->storage = $this->store->storage;
        $this->engine = new IncrementalEngine(
            new FileScanner(new IgnorePatternLoader()),
            new PhpParserAdapter(),
            new ExtractorPipeline(ExtractorPipeline::default()),
            new GraphBuilder($this->storage),
            $this->storage,
        );
    }

    /** A fresh copy of the fixture project, not yet scanned. */
    public static function create(bool $withBrokenFile = false): self
    {
        return new self($withBrokenFile);
    }

    /** Copy and scan in one step. */
    public static function built(bool $withBrokenFile = false): self
    {
        $fixture = new self($withBrokenFile);
        $fixture->build();

        return $fixture;
    }

    public function build(): UpdateResult
    {
        return $this->engine->fullBuild($this->root);
    }

    /** A full rebuild of some other root — lets a test make a rebuild fail midway. */
    public function buildFrom(string $root): UpdateResult
    {
        return $this->engine->fullBuild($root);
    }

    /** Re-index only what changed on disk since the last build or refresh. */
    public function refresh(): UpdateResult
    {
        // The scanner compares content hashes, but a file rewritten within the
        // same second must still be seen as changed: clear the stat cache.
        clearstatcache();

        return $this->engine->update($this->root);
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    public function delete(string $relative): void
    {
        unlink($this->path($relative));
    }

    /** Node id of a fixture class, from its name relative to the fixture namespace ('Cycle\\Ping'). */
    public static function classId(string $relativeClass): string
    {
        return NodeId::forClass(self::NS . $relativeClass);
    }

    /**
     * Every stored edge as a sortable "type source -> target" line, so a whole
     * graph can be compared before and after an operation.
     *
     * @return list<string>
     */
    public function edgeLines(): array
    {
        $rows = $this->store->adapter
            ->execute('SELECT type, source_id, target_id FROM graph_edges')
            ->fetchAll();

        $lines = array_map(
            static fn (array $row): string => $row['type'] . ' ' . $row['source_id'] . ' -> ' . $row['target_id'],
            $rows,
        );
        sort($lines);

        return $lines;
    }

    /**
     * Every stored node as a sortable "id type file [placeholder]" line, with
     * the throwaway root stripped so two fixtures compare equal.
     *
     * @return list<string>
     */
    public function nodeLines(): array
    {
        $rows = $this->store->adapter
            ->execute('SELECT id, type, file, is_placeholder FROM graph_nodes')
            ->fetchAll();

        $lines = array_map(
            fn (array $row): string => $row['id'] . ' ' . $row['type'] . ' '
                . str_replace($this->root . '/', '', (string) $row['file'])
                . ((int) $row['is_placeholder'] === 1 ? ' [placeholder]' : ''),
            $rows,
        );
        sort($lines);

        return $lines;
    }

    public function hasEdge(EdgeType $type, string $sourceId, string $targetId): bool
    {
        foreach ($this->storage->edges->findBySource($sourceId, $type) as $edge) {
            if ($edge->getTargetId() === $targetId) {
                return true;
            }
        }

        return false;
    }

    public function __destruct()
    {
        self::removeTree($this->root);
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            $item->isDir() ? mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
