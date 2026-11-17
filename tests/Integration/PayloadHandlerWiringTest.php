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

        $flow = $fixture->storage->nodes->findById('flow:Campaign\\Flow\\MultiPayload');
        self::assertNotNull($flow);
        self::assertNotNull($fixture->storage->nodes->findById((string) $flow->getMetadata()['entry_point']), 'the flow starts at a route that exists');
    }

    /**
     * A payload addressed by name over HUG has no path. It made route:GET:,
     * an empty route no request can match, and the payload looked routed.
     */
    /**
     * tk-project-graph-shows-every-feed-as-public: the graph mapped every
     * #[AsCollectionFeed] to 'public', but a feed is Protected unless it says
     * Public (tk-collection-feed-always-public). The attribute's own access wins.
     */
    #[Test]
    public function a_collection_feed_has_the_access_its_attribute_says(): void
    {
        $fixture = self::graphOf(['Feeds/Feeds.php' => <<<'PHP'
            <?php
            namespace Campaign\Feeds;

            use Semitexa\Core\Auth\PayloadAccessType;
            use Semitexa\Crud\Attribute\AsCollectionFeed;

            #[AsCollectionFeed(path: '/team/directory', name: 'team.directory', source: \stdClass::class)]
            final class DirectoryFeed {}

            #[AsCollectionFeed(path: '/prices', name: 'prices', source: \stdClass::class, access: PayloadAccessType::Public, reason: 'the public price list')]
            final class PricesFeed {}
            PHP]);

        self::assertSame('protected', $fixture->storage->nodes->findById('class:Campaign\\Feeds\\DirectoryFeed')?->getMetadata()['accessType']);
        self::assertSame('public', $fixture->storage->nodes->findById('class:Campaign\\Feeds\\PricesFeed')?->getMetadata()['accessType']);
    }

    /**
     * Since crud#13 a feed says who may read it in its attribute's name, and gives its
     * paging whole: `paging: new FeedPaging(...)`. The parser records that `new` and
     * never runs it, so the attribute's constructor refused the record and the feed
     * vanished from the graph; #[AsPublicFeed] was not read at all.
     */
    #[Test]
    public function a_feed_named_protected_or_public_is_a_payload_with_that_access(): void
    {
        $fixture = self::graphOf(['Feeds/Named.php' => <<<'PHP'
            <?php
            namespace Campaign\Named;

            use Semitexa\Crud\Attribute\AsProtectedFeed;
            use Semitexa\Crud\Attribute\AsPublicFeed;
            use Semitexa\Crud\Domain\Model\FeedPaging;

            #[AsProtectedFeed(path: '/team/members', name: 'team.members', model: \stdClass::class, paging: new FeedPaging(defaultPerPage: 50))]
            final class MembersFeed {}

            #[AsPublicFeed(path: '/prices/all', name: 'prices.all', reason: 'the public price list', model: \stdClass::class)]
            final class AllPricesFeed {}
            PHP]);

        $members = $fixture->storage->nodes->findById('class:Campaign\\Named\\MembersFeed');
        $prices = $fixture->storage->nodes->findById('class:Campaign\\Named\\AllPricesFeed');
        self::assertSame(
            ['members' => ['protected', 'team.members'], 'prices' => ['public', 'prices.all']],
            [
                'members' => [$members?->getMetadata()['accessType'] ?? null, $members?->getMetadata()['name'] ?? null],
                'prices' => [$prices?->getMetadata()['accessType'] ?? null, $prices?->getMetadata()['name'] ?? null],
            ],
        );
        self::assertSame(['route:GET:/team/members'], self::targetsFrom($fixture, 'class:Campaign\\Named\\MembersFeed', EdgeType::ServesRoute));
        self::assertSame(['route:GET:/prices/all'], self::targetsFrom($fixture, 'class:Campaign\\Named\\AllPricesFeed', EdgeType::ServesRoute));
    }

    #[Test]
    public function a_payload_addressed_by_name_serves_no_route_and_keeps_its_name(): void
    {
        $fixture = self::graphOf(['Flow/Feed.php' => <<<'PHP'
            <?php
            namespace Campaign\Feed;

            use Semitexa\Core\Attribute\AsPublicPayload;

            #[AsPublicPayload(name: 'campaign.feed', methods: ['GET'])]
            final class FeedPayload {}
            PHP]);

        self::assertSame([], self::targetsFrom($fixture, 'class:Campaign\\Feed\\FeedPayload', EdgeType::ServesRoute));
        self::assertNull($fixture->storage->nodes->findById('route:GET:'));
        $payload = $fixture->storage->nodes->findById('class:Campaign\\Feed\\FeedPayload');
        self::assertNotNull($payload);
        self::assertSame('campaign.feed', $payload->getMetadata()['name']);
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
     * The graph kept `execution` as written, so 'queue' (which the runtime
     * reads as queued) was neither queued nor sync, and FlowTracer drew any
     * value but the exact string 'sync' as an async boundary
     * (tk-graph-handler-execution-normalized).
     */
    #[Test]
    public function a_handler_execution_is_stored_as_the_runtime_reads_it(): void
    {
        $fixture = self::graphOf(['Exec/Exec.php' => <<<'PHP'
            <?php
            namespace Campaign\Exec;

            use Semitexa\Core\Attribute\AsPayloadHandler;
            use Semitexa\Core\Queue\HandlerExecution;

            final class P {}
            final class R {}

            #[AsPayloadHandler(payload: P::class, resource: R::class, execution: 'queue')]
            final class QueueWord {}

            #[AsPayloadHandler(payload: P::class, resource: R::class, execution: 'Async')]
            final class CapitalAsync {}

            #[AsPayloadHandler(payload: P::class, resource: R::class, execution: HandlerExecution::Queued)]
            final class EnumQueued {}

            #[AsPayloadHandler(payload: P::class, resource: R::class, execution: '')]
            final class EmptyWord {}

            #[AsPayloadHandler(payload: P::class, resource: R::class)]
            final class Unset_ {}
            PHP]);

        $expected = ['QueueWord' => 'queued', 'CapitalAsync' => 'async', 'EnumQueued' => 'queued', 'EmptyWord' => 'sync', 'Unset_' => 'sync'];
        foreach ($expected as $handler => $execution) {
            $id = 'class:Campaign\\Exec\\' . $handler;
            $node = $fixture->storage->nodes->findById($id);
            self::assertNotNull($node, $handler);
            self::assertSame($execution, $node->getMetadata()['execution'] ?? null, "{$handler} node");

            $flow = self::edgeBetween($fixture, EdgeType::ParticipatesInFlow, $id, 'flow:Campaign\\Exec\\P');
            self::assertNotNull($flow, $handler);
            self::assertSame($execution, $flow->getMetadata()['execution'] ?? null, "{$handler} flow edge");
        }
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
