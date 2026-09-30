<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

final class TypeAndNameResolutionTest extends TestCase
{
    private function fixtureWithRepriceService(): GraphFixture
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/RepriceService.php', <<<'PHP'
            <?php

            namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle\Ping;
            use Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle\Pong;

            class RepriceBase
            {
            }

            final class RepriceService extends RepriceBase
            {
                public static function create(): static
                {
                    return new static();
                }

                public function copy(): self
                {
                    return new self();
                }

                public function base(): RepriceBase
                {
                    return new parent();
                }

                public function reprice(?OrderResource $resource, Ping|Pong $signal): OrderResource|OrderPlaced|null
                {
                    return $resource;
                }
            }
            PHP);
        $fixture->refresh();

        return $fixture;
    }

    #[Test]
    public function self_and_static_do_not_become_nodes(): void
    {
        $fixture = $this->fixtureWithRepriceService();

        self::assertFalse($fixture->storage->nodeExists('class:self'));
        self::assertFalse($fixture->storage->nodeExists('class:static'));
    }

    #[Test]
    public function parent_resolves_to_the_parent_class(): void
    {
        $fixture = $this->fixtureWithRepriceService();

        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, GraphFixture::classId('Orders\\RepriceService'), GraphFixture::classId('Orders\\RepriceBase')));
        self::assertFalse($fixture->storage->nodeExists('class:parent'));
    }

    #[Test]
    public function nullable_and_union_types_name_every_class_in_them(): void
    {
        $fixture = $this->fixtureWithRepriceService();
        $service = GraphFixture::classId('Orders\\RepriceService');

        self::assertTrue($fixture->hasEdge(EdgeType::Accepts, $service, GraphFixture::classId('Orders\\OrderResource')));
        self::assertTrue($fixture->hasEdge(EdgeType::Accepts, $service, GraphFixture::classId('Cycle\\Ping')));
        self::assertTrue($fixture->hasEdge(EdgeType::Accepts, $service, GraphFixture::classId('Cycle\\Pong')));
        self::assertTrue($fixture->hasEdge(EdgeType::Returns, $service, GraphFixture::classId('Orders\\OrderResource')));
        self::assertTrue($fixture->hasEdge(EdgeType::Returns, $service, GraphFixture::classId('Orders\\OrderPlaced')));
    }
}
