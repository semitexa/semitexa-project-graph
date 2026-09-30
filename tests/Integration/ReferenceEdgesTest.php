<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

/**
 * A class used only as Foo::class, through a static call, a constant, an
 * instanceof or a catch used to look unused: none of those made an edge.
 */
final class ReferenceEdgesTest extends TestCase
{
    private function fixtureWithRegistry(): GraphFixture
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/OrderRegistry.php', <<<'PHP'
            <?php

            namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle\Ping;
            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle\Pong;
            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orphan\NobodyUsesMe;
            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Mail\SendReceiptListener;

            #[\Semitexa\Core\Attribute\AsService]
            final class OrderRegistry
            {
                public const LISTENERS = [SendReceiptListener::class];

                public function kinds(object $o, string $runtime): array
                {
                    try {
                        Ping::class;
                        $x = Pong::CONSTANT;
                        $o instanceof NobodyUsesMe;
                        $runtime::boot();
                    } catch (OrderPlaced $e) {
                    }

                    return self::LISTENERS;
                }
            }
            PHP);
        $fixture->refresh();

        return $fixture;
    }

    #[Test]
    public function each_kind_of_reference_is_an_edge_that_says_how(): void
    {
        $fixture = $this->fixtureWithRegistry();
        $registry = GraphFixture::classId('Orders\\OrderRegistry');

        $via = [];
        foreach ($fixture->storage->edges->findBySource($registry, EdgeType::References) as $edge) {
            $via[$edge->getTargetId()] = $edge->getMetadata()['via'] ?? null;
        }

        self::assertSame('class_name', $via[GraphFixture::classId('Cycle\\Ping')] ?? null);
        self::assertSame('constant', $via[GraphFixture::classId('Cycle\\Pong')] ?? null);
        self::assertSame('instanceof', $via[GraphFixture::classId('Orphan\\NobodyUsesMe')] ?? null);
        self::assertSame('catch', $via[GraphFixture::classId('Orders\\OrderPlaced')] ?? null);
        self::assertSame('class_name', $via[GraphFixture::classId('Mail\\SendReceiptListener')] ?? null, 'class constants count too');
        self::assertArrayNotHasKey($registry, $via, 'self:: is not a reference');
    }

    #[Test]
    public function a_static_call_on_a_runtime_class_is_a_dynamic_reference_gap(): void
    {
        $fixture = $this->fixtureWithRegistry();

        $gaps = $fixture->storage->gaps->findByFile($fixture->path('Orders/OrderRegistry.php'));
        self::assertCount(1, $gaps);
        self::assertSame(CoverageGapKind::DynamicReference, $gaps[0]->getKind());
        self::assertSame(21, $gaps[0]->getLine());
    }

    #[Test]
    public function attribute_arguments_are_left_to_the_attribute_extractors(): void
    {
        $fixture = GraphFixture::built();

        // #[AsPayloadHandler(payload: PlaceOrderPayload::class)] is a handles edge, not a reference.
        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');
        $payload = GraphFixture::classId('Orders\\PlaceOrderPayload');

        self::assertTrue($fixture->hasEdge(EdgeType::Handles, $handler, $payload));
        self::assertFalse($fixture->hasEdge(EdgeType::References, $handler, $payload));
    }
}
