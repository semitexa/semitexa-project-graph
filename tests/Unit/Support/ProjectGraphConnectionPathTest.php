<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Support\ProjectGraphConnection;

/**
 * Which graph file a process opens.
 *
 * The rule these pin down: every process that can use a graph opens the same
 * one, the most recently written. Picking the first writable directory made the
 * answer depend on the caller's uid — the root worker read a stale
 * `var/storage` graph while the host-user CLI rebuilt `var/tmp`.
 */
final class ProjectGraphConnectionPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pg-path-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/var/storage', 0777, true);
        mkdir($this->root . '/var/tmp', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['var/storage', 'var/tmp'] as $dir) {
            @unlink($this->root . '/' . $dir . '/project-graph.sqlite');
            @rmdir($this->root . '/' . $dir);
        }
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    #[Test]
    public function with_no_graph_built_yet_storage_comes_first(): void
    {
        self::assertSame(
            $this->root . '/var/storage/project-graph.sqlite',
            ProjectGraphConnection::resolveDefaultSqlitePath($this->root),
        );
    }

    #[Test]
    public function the_most_recently_written_graph_wins_over_order(): void
    {
        touch($this->root . '/var/storage/project-graph.sqlite', time() - 86400);
        touch($this->root . '/var/tmp/project-graph.sqlite', time());

        self::assertSame(
            $this->root . '/var/tmp/project-graph.sqlite',
            ProjectGraphConnection::resolveDefaultSqlitePath($this->root),
        );
    }

    #[Test]
    public function an_existing_graph_beats_an_empty_earlier_candidate(): void
    {
        touch($this->root . '/var/tmp/project-graph.sqlite');

        self::assertSame(
            $this->root . '/var/tmp/project-graph.sqlite',
            ProjectGraphConnection::resolveDefaultSqlitePath($this->root),
        );
    }
}
