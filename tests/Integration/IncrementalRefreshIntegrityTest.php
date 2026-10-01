<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * An incremental refresh must leave the same graph a full build would.
 *
 * A file owns the edges its own extraction emitted — the ones leaving its
 * classes. Edges other, unchanged files have INTO it are not its to delete:
 * those files are not re-read on this refresh, so nothing would put the edges
 * back. Before this was fixed, touching one file silently dropped every edge
 * pointing at it, and every later "unused" or "zero impact" answer about that
 * file was wrong until the whole graph was rebuilt.
 */
final class IncrementalRefreshIntegrityTest extends TestCase
{
    #[Test]
    public function touching_a_file_keeps_the_edges_other_files_have_into_it(): void
    {
        $fixture = GraphFixture::built();
        $ping = GraphFixture::classId('Cycle\\Ping');
        $pong = GraphFixture::classId('Cycle\\Pong');

        $fixture->write('Cycle/Pong.php', $fixture->read('Cycle/Pong.php') . "\n// touched\n");
        $fixture->refresh();

        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, $ping, $pong), 'Ping.php was not re-read, so its edge into Pong must survive');
        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, $pong, $ping), 'Pong.php was re-read and must have re-emitted its own edge');
    }

    #[Test]
    public function a_refresh_after_touching_every_file_one_by_one_equals_a_full_build(): void
    {
        $fixture = GraphFixture::built();
        $expectedEdges = $fixture->edgeLines();
        $expectedNodes = $fixture->nodeLines();

        foreach (['Orders/OrderRepository.php', 'Orders/PlaceOrderPayload.php', 'Orders/OrderPlaced.php', 'Orders/OrderResource.php', 'Cycle/Ping.php'] as $file) {
            $fixture->write($file, $fixture->read($file) . "\n// touched\n");
            $fixture->refresh();
        }

        self::assertSame($expectedEdges, $fixture->edgeLines());
        self::assertSame($expectedNodes, $fixture->nodeLines());
    }

    #[Test]
    public function a_deleted_class_that_is_still_referenced_stays_visible_as_a_placeholder(): void
    {
        $fixture = GraphFixture::built();
        $ping = GraphFixture::classId('Cycle\\Ping');
        $pong = GraphFixture::classId('Cycle\\Pong');

        $fixture->delete('Cycle/Pong.php');
        $fixture->refresh();

        // Ping still says `new Pong()`. The edge is real and now dangling —
        // exactly what an orphan check has to be able to see.
        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, $ping, $pong));
        $node = $fixture->storage->nodes->findById($pong);
        self::assertNotNull($node);
        self::assertTrue($node->getIsPlaceholder());
        self::assertSame('', $node->getFile());

        // And Pong's own outgoing edge went with its file.
        self::assertFalse($fixture->hasEdge(EdgeType::Instantiates, $pong, $ping));
    }

    #[Test]
    public function a_deleted_class_nobody_references_leaves_nothing_behind(): void
    {
        $fixture = GraphFixture::built();
        $orphan = GraphFixture::classId('Orphan\\NobodyUsesMe');

        $fixture->delete('Orphan/NobodyUsesMe.php');
        $fixture->refresh();

        self::assertFalse($fixture->storage->nodeExists($orphan));
    }

    #[Test]
    public function a_placeholder_nothing_points_at_any_more_is_swept(): void
    {
        $fixture = GraphFixture::built();
        $ping = GraphFixture::classId('Cycle\\Ping');
        $pong = GraphFixture::classId('Cycle\\Pong');

        $fixture->delete('Cycle/Pong.php');
        $fixture->refresh();
        self::assertTrue($fixture->storage->nodeExists($pong), 'precondition: Pong is a placeholder while Ping references it');

        $fixture->delete('Cycle/Ping.php');
        $fixture->refresh();

        self::assertFalse($fixture->storage->nodeExists($pong));
        self::assertFalse($fixture->storage->nodeExists($ping));
    }
}
