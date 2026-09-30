<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\InboundIndex;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

final class InboundIndexTest extends TestCase
{
    #[Test]
    public function it_answers_inbound_and_outbound_edges_for_the_whole_graph_from_one_read(): void
    {
        $fixture = GraphFixture::built();
        $index = InboundIndex::of($fixture->storage);

        $into = $index->into(GraphFixture::classId('Orders\\OrderRepository'));
        $types = array_map(static fn (array $e): string => $e['type']->value, $into);
        sort($types);

        self::assertContains('implements', $types);
        self::assertContains('injects_readonly', $types);
        foreach ($into as $edge) {
            self::assertTrue($edge['sourceDeclared'], $edge['source'] . ' is a declared class');
            self::assertFalse($edge['sourceInTests'], 'the fixture is copied outside any tests/ directory');
        }

        self::assertSame([], $index->into(GraphFixture::classId('Orphan\\NobodyUsesMe')));
        self::assertContains(
            EdgeType::Handles,
            array_column($index->outOf(GraphFixture::classId('Orders\\PlaceOrderHandler')), 'type'),
        );
    }
}
