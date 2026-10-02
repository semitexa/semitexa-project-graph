<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * Payload/handler wiring read from attributes: routes per HTTP method, the
 * resource declared beside its handler, and injected union types.
 */
final class PayloadHandlerWiringTest extends TestCase
{
    use ExtractorCampaign;

    private const FLOW = <<<'PHP'
        <?php
        namespace Campaign\Flow;

        use Semitexa\Core\Attribute\AsPayloadHandler;
        use Semitexa\Core\Attribute\AsPublicPayload;
        use Semitexa\Core\Attribute\InjectAsReadonly;

        #[AsPublicPayload(path: '/multi', methods: ['GET', 'POST'])]
        final class MultiPayload {}

        #[AsPayloadHandler(payload: MultiPayload::class, resource: MultiResource::class)]
        final class MultiHandler
        {
            #[InjectAsReadonly]
            protected \Campaign\Lib\Bus|\Campaign\Lib\OtherBus $events;

            #[InjectAsReadonly]
            protected $untyped;

            #[InjectAsReadonly]
            protected ?\Campaign\Lib\Clock $clock;
        }

        final class MultiResource
        {
            public int $x = 0;
        }
        PHP;

    private const OTHER = <<<'PHP'
        <?php
        namespace Campaign\Other;

        use Semitexa\Core\Attribute\InjectAsReadonly;

        final class OtherService
        {
            #[InjectAsReadonly]
            protected \Campaign\Lib\A|\Campaign\Lib\B $events;
        }
        PHP;

    /**
     * ExecutionFlowExtractor joined the methods into one id: 37 phantom
     * route:GET,POST:/x nodes on the workspace (measured 2026-10-02), each
     * a second, unreachable copy of routes PayloadExtractor already made.
     */
    #[Test]
    public function a_payload_serving_two_methods_is_two_routes_and_no_joined_one(): void
    {
        $fixture = self::graphOf(['Flow/Flow.php' => self::FLOW]);

        self::assertSame(
            ['route:GET:/multi', 'route:POST:/multi'],
            self::targetsFrom($fixture, 'class:Campaign\\Flow\\MultiPayload', EdgeType::ServesRoute),
        );
        foreach ($fixture->nodeLines() as $line) {
            self::assertStringNotContainsString('GET,POST', $line);
        }

        $flow = $fixture->storage->nodes->findById('flow:MultiFlow');
        self::assertNotNull($flow);
        self::assertNotNull($fixture->storage->nodes->findById((string) $flow->getMetadata()['entry_point']), 'the flow starts at a route that exists');
    }

    #[Test]
    public function a_resource_declared_beside_its_handler_keeps_its_own_lines(): void
    {
        $fixture = self::graphOf(['Flow/Flow.php' => self::FLOW]);

        $resource = $fixture->storage->nodes->findById('class:Campaign\\Flow\\MultiResource');
        self::assertNotNull($resource);
        self::assertSame(24, $resource->getLine());
        self::assertSame(27, $resource->getEndLine());
    }

    /**
     * A union-typed injection pointed at unknown:<property>, so two unrelated
     * classes injecting `A|B $events` became neighbours through one shared
     * unknown:events node.
     */
    #[Test]
    public function an_injected_union_points_at_each_member_and_nothing_is_shared_through_unknown(): void
    {
        $fixture = self::graphOf(['Flow/Flow.php' => self::FLOW, 'Other/OtherService.php' => self::OTHER]);

        self::assertSame(
            ['class:Campaign\\Lib\\Bus', 'class:Campaign\\Lib\\Clock', 'class:Campaign\\Lib\\OtherBus'],
            self::targetsFrom($fixture, 'class:Campaign\\Flow\\MultiHandler', EdgeType::InjectsReadonly),
        );
        self::assertSame(
            ['class:Campaign\\Lib\\A', 'class:Campaign\\Lib\\B'],
            self::targetsFrom($fixture, 'class:Campaign\\Other\\OtherService', EdgeType::InjectsReadonly),
        );
        foreach ($fixture->edgeLines() as $line) {
            self::assertStringNotContainsString('unknown:', $line);
        }
    }
}
