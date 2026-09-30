<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A refresh reports what actually changed, edge by edge.
 *
 * GraphBuilder::apply used to call recordRemoved($removed, 0) and count every
 * edge of a re-read file as added, so editing one line of a file reported
 * "+N/-0 edges" for all N edges it had — removed edges were always zero.
 */
final class PerFileEdgeDiffTest extends TestCase
{
    #[Test]
    public function touching_a_file_without_changing_its_code_changes_nothing(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/PlaceOrderHandler.php', $fixture->read('Orders/PlaceOrderHandler.php') . "\n// comment only\n");

        $result = $fixture->refresh();

        self::assertSame(1, $result->filesScanned);
        self::assertSame([0, 0, 0, 0], [$result->nodesAdded, $result->nodesRemoved, $result->edgesAdded, $result->edgesRemoved]);
    }

    #[Test]
    public function a_new_parameter_is_exactly_one_added_edge(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/PlaceOrderHandler.php', str_replace(
            'public function handle(PlaceOrderPayload $payload): OrderResource',
            'public function handle(PlaceOrderPayload $payload, ?OrderPlaced $previous = null): OrderResource',
            $fixture->read('Orders/PlaceOrderHandler.php'),
        ));

        $result = $fixture->refresh();

        self::assertSame(1, $result->edgesAdded);
        self::assertSame(0, $result->edgesRemoved);
        self::assertSame(['accepts'], array_map(static fn ($e) => $e->getType()->value, $result->addedEdges));
    }

    #[Test]
    public function a_dropped_instantiation_is_exactly_one_removed_edge(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Cycle/Pong.php', str_replace('return new Ping();', 'return $this->ping;', $fixture->read('Cycle/Pong.php')));

        $result = $fixture->refresh();

        self::assertSame(0, $result->edgesAdded);
        self::assertSame(1, $result->edgesRemoved);
        $removed = $result->removedEdges[0];
        self::assertSame(EdgeType::Instantiates, $removed->getType());
        self::assertSame(GraphFixture::classId('Cycle\\Pong'), $removed->getSourceId());
        self::assertSame(GraphFixture::classId('Cycle\\Ping'), $removed->getTargetId());
    }

    #[Test]
    public function a_deleted_file_reports_every_edge_it_owned_as_removed(): void
    {
        $fixture = GraphFixture::built();
        $owned = count($fixture->storage->edges->findBySource(GraphFixture::classId('Cycle\\Pong')));

        $fixture->delete('Cycle/Pong.php');
        $result = $fixture->refresh();

        self::assertGreaterThan(0, $owned);
        self::assertSame($owned, $result->edgesRemoved);
        self::assertSame(0, $result->edgesAdded);
    }
}
