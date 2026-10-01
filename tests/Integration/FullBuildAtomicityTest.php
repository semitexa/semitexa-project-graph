<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A full rebuild that dies halfway must leave the previous graph, not a
 * truncated one.
 *
 * fullBuild() empties the graph and then scans. It used to commit the empty
 * graph first, so a rebuild that failed — measured 2026-09-30: the workspace
 * build runs out of memory at PHP's default 128M — left a partial graph, and
 * every later incremental refresh quietly built on top of it.
 */
final class FullBuildAtomicityTest extends TestCase
{
    #[Test]
    public function a_rebuild_that_fails_midway_leaves_the_previous_graph(): void
    {
        $fixture = GraphFixture::built();
        $edges = $fixture->edgeLines();
        $nodes = $fixture->nodeLines();
        self::assertNotSame([], $edges);

        try {
            // A root that does not exist makes the scan throw after the
            // truncate — standing in for any failure mid-build.
            $fixture->buildFrom($fixture->root . '/does-not-exist');
            self::fail('The rebuild was expected to fail');
        } catch (\UnexpectedValueException) {
        }

        self::assertSame($edges, $fixture->edgeLines());
        self::assertSame($nodes, $fixture->nodeLines());
    }

    #[Test]
    public function a_refresh_after_a_failed_rebuild_still_sees_every_file_as_indexed(): void
    {
        $fixture = GraphFixture::built();

        try {
            $fixture->buildFrom($fixture->root . '/does-not-exist');
        } catch (\UnexpectedValueException) {
        }

        self::assertSame(0, $fixture->refresh()->filesScanned);
    }
}
