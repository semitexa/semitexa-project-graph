<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\FlowGapReport;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Intelligence\ExecutionFlow;
use Semitexa\ProjectGraph\Application\Service\Intelligence\FlowTracer;
use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Service\Query\GraphBrowser;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;

/**
 * A flow walked out of real extraction: payload → handler → services →
 * storage, and on through the events it emits to the listeners of each —
 * and every place the walk cannot vouch for, recorded as a gap.
 *
 * `flow-trace` printed one step — the payload — for every flow on the
 * workspace: the flow node was written by the payload's file, which cannot
 * know its handler, its tables or its events (measured 2026-10-16).
 */
final class FlowTracerTest extends TestCase
{
    use ExtractorCampaign;

    /** Spliced in after each file's namespace line, where a `use` may stand. */
    private const HEADER = <<<'PHP'
        use Semitexa\Core\Attribute\AsEvent;
        use Semitexa\Core\Attribute\AsEventListener;
        use Semitexa\Core\Attribute\AsPayloadHandler;
        use Semitexa\Core\Attribute\AsPublicPayload;
        use Semitexa\Core\Attribute\AsService;
        use Semitexa\Core\Attribute\InjectAsReadonly;
        use Semitexa\Core\Attribute\SatisfiesServiceContract;
        use Semitexa\Orm\Attribute\FromTable;

        PHP;

    private const ORDERS = <<<'PHP'
        namespace Campaign\Orders;

        #[AsPublicPayload(path: '/orders/place', methods: ['POST'])]
        final class PlaceOrderPayload {}

        #[AsPayloadHandler(payload: PlaceOrderPayload::class, resource: OrderResponse::class)]
        final class PlaceOrderHandler
        {
            #[InjectAsReadonly]
            protected OrderPlacer $placer;
        }

        final class OrderResponse {}

        #[AsService]
        final class OrderPlacer
        {
            #[InjectAsReadonly]
            protected OrderStoreInterface $store;

            #[InjectAsReadonly]
            protected \Campaign\Bus\Writer $writer;

            #[InjectAsReadonly]
            protected \Campaign\Pricing\PriceSource $prices;

            private $events;

            public function place(): void
            {
                $this->events->dispatch(new OrderPlaced());
                $this->events->dispatch(new OrderAudited());
            }
        }

        interface OrderStoreInterface {}

        #[AsService]
        #[SatisfiesServiceContract(of: OrderStoreInterface::class)]
        final class DbOrderStore implements OrderStoreInterface
        {
            public function save(): void { OrderRow::class; }
        }

        #[FromTable(name: 'orders')]
        final class OrderRow {}

        #[AsEvent]
        final class OrderPlaced {}

        #[AsEvent]
        final class OrderAudited {}

        #[AsEventListener(event: OrderPlaced::class, execution: 'async')]
        final class SendReceiptListener
        {
            #[InjectAsReadonly]
            protected ReceiptLog $log;
        }

        #[AsService]
        final class ReceiptLog
        {
            public function add(): void { ReceiptRow::class; }
        }

        #[FromTable(name: 'receipts')]
        final class ReceiptRow {}

        #[AsPublicPayload(path: '/orders/orphan', methods: ['GET'])]
        final class OrphanPayload {}

        #[AsPublicPayload(path: '/orders/help', methods: ['GET'], responseWith: HelpPage::class)]
        final class HelpPayload {}

        #[AsPublicPayload(name: 'orders.feed', methods: ['GET'], responseWith: HelpPage::class)]
        final class OrdersFeedPayload {}

        abstract class FeedBase {}

        #[AsPayloadHandler(payload: FeedBase::class, resource: OrderResponse::class)]
        final class FeedBaseHandler {}

        final class HelpPage {}
        PHP;

    /** Framework-shaped: a writer every module imports, firing an event of its own. */
    private const BUS = <<<'PHP'
        namespace Campaign\Bus;

        #[AsService]
        final class Writer
        {
            private $events;

            public function write(): void
            {
                WriteRow::class;
                $this->events->dispatch(new RowWritten());
            }
        }

        #[FromTable(name: 'bus_journal')]
        final class WriteRow {}

        #[AsEvent]
        final class RowWritten {}
        PHP;

    private const SEARCH = <<<'PHP'
        namespace Campaign\Search;

        #[AsEventListener(event: \Campaign\Bus\RowWritten::class, execution: 'sync')]
        final class ReindexListener
        {
            public function handle(): void { IndexRow::class; }
        }

        #[FromTable(name: 'search_index')]
        final class IndexRow {}
        PHP;

    /** One interface, a Core default, a module override, and a second module's override. */
    private const PRICING = <<<'PHP'
        namespace Campaign\Pricing;

        interface PriceSource {}
        PHP;

    private const CORE_PRICES = <<<'PHP'
        namespace Campaign\CorePricing;

        #[AsService]
        #[SatisfiesServiceContract(of: \Campaign\Pricing\PriceSource::class)]
        final class DefaultPriceSource implements \Campaign\Pricing\PriceSource {}
        PHP;

    private const SHOP_PRICES = <<<'PHP'
        namespace Campaign\ShopPricing;

        #[AsService]
        #[SatisfiesServiceContract(of: \Campaign\Pricing\PriceSource::class)]
        final class ShopPriceSource implements \Campaign\Pricing\PriceSource
        {
            public function price(): void { PriceRow::class; }
        }

        #[FromTable(name: 'prices')]
        final class PriceRow {}
        PHP;

    private const B2B_PRICES = <<<'PHP'
        namespace Campaign\B2bPricing;

        #[AsService]
        #[SatisfiesServiceContract(of: \Campaign\Pricing\PriceSource::class)]
        final class B2bPriceSource implements \Campaign\Pricing\PriceSource {}
        PHP;

    private const SHADOW = <<<'PHP'
        namespace Campaign\Shop;

        #[AsPublicPayload(path: '/shop/place', methods: ['POST'])]
        final class PlaceOrderPayload {}

        #[AsPayloadHandler(payload: PlaceOrderPayload::class, resource: ShopResponse::class)]
        final class ShopPlaceOrderHandler {}

        final class ShopResponse {}
        PHP;

    #[Test]
    public function the_flow_reaches_the_handler_its_storage_and_the_listener_of_its_event(): void
    {
        $flow = $this->orders();

        self::assertSame('PlaceOrderFlow', $flow->name);
        self::assertSame('route:POST:/orders/place', $flow->entryPoint);
        self::assertSame(
            [
                ['payload', 'Campaign\\Orders\\PlaceOrderPayload'],
                ['handler', 'Campaign\\Orders\\PlaceOrderHandler'],
                ['listener', 'Campaign\\Orders\\SendReceiptListener'],
                ['listener', 'Campaign\\Search\\ReindexListener'],
            ],
            array_map(static fn (array $s): array => [$s['role'], $s['node']], $flow->steps),
        );
        self::assertSame('Campaign\\Orders\\OrderPlaced', $flow->steps[2]['event']);
        self::assertTrue($flow->steps[2]['certain'], 'the flow\'s own module emits it');
        // `orders` sits behind an interface the container is told to fill with DbOrderStore;
        // `prices` behind one a module binds over the Core default.
        self::assertSame(['orders', 'prices', 'receipts'], $flow->storageTouches);
        self::assertSame(2, $flow->syncBoundary, 'the async listener is where the request stops waiting');
    }

    #[Test]
    public function a_class_five_modules_import_is_walked_for_its_events_only(): void
    {
        $flow = $this->orders();

        self::assertSame(['Campaign\\Bus\\Writer'], $flow->infrastructure);
        self::assertNotContains('bus_journal', $flow->storageTouches, 'the framework\'s own table is not the flow\'s');
        self::assertContains('Campaign\\Bus\\RowWritten', $flow->eventsEmitted, 'but what it fires is a consequence of the flow');
    }

    #[Test]
    public function a_listener_of_another_module_s_event_may_act_and_is_not_entered(): void
    {
        $flow = $this->orders();

        self::assertFalse($flow->steps[3]['certain']);
        self::assertNotContains('search_index', $flow->storageTouches);
        self::assertSame(['Campaign\\Search\\ReindexListener'], $this->gapsAt($flow, FlowTracer::GAP_CONDITIONAL_LISTENER));
    }

    #[Test]
    public function every_place_the_walk_cannot_see_past_is_a_gap(): void
    {
        $flow = $this->orders();

        self::assertSame(['Campaign\\Orders\\OrderAudited'], $this->gapsAt($flow, FlowTracer::GAP_EVENT_WITHOUT_LISTENER));
        self::assertFalse($flow->isComplete());

        $intelligence = $this->intelligence(['src/modules/Orders/Orders.php' => self::ORDERS]);
        $orphan = $intelligence->getExecutionFlow('flow:Campaign\\Orders\\OrphanPayload');
        self::assertNotNull($orphan);
        self::assertSame([FlowTracer::GAP_NO_HANDLER], array_column($orphan->gaps, 'kind'));

        $page = $intelligence->getExecutionFlow('flow:Campaign\\Orders\\HelpPayload');
        self::assertNotNull($page);
        self::assertTrue($page->isComplete(), 'a page its response renders needs no handler');
        self::assertSame(['payload', 'response'], array_column($page->steps, 'role'));
    }

    #[Test]
    public function two_modules_overriding_one_contract_is_ambiguous_and_names_both(): void
    {
        $files = $this->ordersFiles() + ['src/modules/B2bPricing/Prices.php' => self::B2B_PRICES];
        $flow = $this->intelligence($files)->getExecutionFlow('flow:Campaign\\Orders\\PlaceOrderPayload');

        self::assertNotNull($flow);
        $gap = array_values(array_filter($flow->gaps, static fn (array $g): bool => $g['kind'] === FlowTracer::GAP_AMBIGUOUS_CONTRACT))[0] ?? null;
        self::assertNotNull($gap);
        self::assertSame('class:Campaign\\Pricing\\PriceSource', $gap['at']);
        self::assertSame(['Campaign\\B2bPricing\\B2bPriceSource', 'Campaign\\ShopPricing\\ShopPriceSource'], $gap['candidates'], 'the Core default loses to both, as in the container');
        self::assertNotContains('prices', $flow->storageTouches);
    }

    #[Test]
    public function the_report_groups_a_gap_by_where_it_sits_and_counts_complete_flows(): void
    {
        $fixture = self::graphOf(array_map(self::php(...), $this->ordersFiles() + [
            'src/modules/Shop/Shop.php' => self::SHADOW,
            'src/modules/Orders/tests/PingFixture.php' => "namespace Campaign\\Orders\\Tests;\n\n#[AsPublicPayload(path: '/ping', methods: ['GET'])]\nfinal class PingFixture {}\n",
        ]));
        $query = new GraphQueryService($fixture->storage);
        $orders = (new FlowGapReport())->collect($query, 'Orders', TestCode::of($fixture->storage));
        $shop = (new FlowGapReport())->collect($query, 'Shop');

        self::assertSame(['total' => 4, 'complete' => 2, 'incomplete' => 2, 'test_flows_skipped' => 1], array_intersect_key($orders['flows'], ['total' => 0, 'complete' => 0, 'incomplete' => 0, 'test_flows_skipped' => 0]), 'a payload fixture is no business flow');
        self::assertSame(['total' => 1, 'complete' => 1, 'incomplete' => 0], array_intersect_key($shop['flows'], ['total' => 0, 'complete' => 0, 'incomplete' => 0]));
        self::assertSame([], $shop['findings']);
        $gaps = array_map(static fn (array $f): string => $f['gap'] . ' ' . $f['at'] . ' ' . $f['flow_count'], $orders['findings']);
        sort($gaps);
        self::assertSame([
            'conditional_listener class:Campaign\\Search\\ReindexListener 1',
            'event_without_listener class:Campaign\\Orders\\OrderAudited 1',
            'no_handler flow:Campaign\\Orders\\OrphanPayload 1',
        ], $gaps);
    }

    #[Test]
    public function two_modules_with_the_same_payload_name_keep_two_flows(): void
    {
        $intelligence = $this->intelligence($this->ordersFiles() + ['src/modules/Shop/Shop.php' => self::SHADOW]);

        $orders = $intelligence->getExecutionFlow('flow:Campaign\\Orders\\PlaceOrderPayload');
        $shop = $intelligence->getExecutionFlow('flow:Campaign\\Shop\\PlaceOrderPayload');

        self::assertNotNull($orders);
        self::assertNotNull($shop);
        self::assertSame('route:POST:/orders/place', $orders->entryPoint);
        self::assertSame('route:POST:/shop/place', $shop->entryPoint);
        self::assertSame('Campaign\\Shop\\ShopPlaceOrderHandler', $shop->steps[1]['node']);
        self::assertCount(2, $shop->steps, 'the other module\'s handler does not join this flow');
        self::assertTrue($shop->isComplete());
        self::assertNull($intelligence->getExecutionFlow('PlaceOrderFlow'), 'a name both flows carry picks neither');
    }

    #[Test]
    public function the_view_lists_flows_untraced_and_traces_the_opened_one_with_its_nodes(): void
    {
        $fixture = self::graphOf(array_map(self::php(...), $this->ordersFiles() + [
            'src/modules/Orders/tests/PingFixture.php' => "namespace Campaign\\Orders\\Tests;\n\n#[AsPublicPayload(path: '/ping', methods: ['GET'])]\nfinal class PingFixture {}\n",
        ]));
        $browser = new GraphBrowser($fixture->storage, $fixture->root);

        $listed = array_column(array_filter($browser->flows(), static fn (array $f): bool => $f['module'] === 'Orders'), 'test', 'name');
        self::assertSame(['HelpFlow' => false, 'OrdersFeedFlow' => false, 'OrphanFlow' => false, 'PlaceOrderFlow' => false, 'PingFixtureFlow' => true], $listed, 'business flows first, test fixtures flagged and last; the abstract base\'s handler opens no flow');
        $feed = $browser->flow('flow:Campaign\\Orders\\OrdersFeedPayload');
        self::assertSame('hug:orders.feed', $feed['entry_point'] ?? null, 'a payload addressed by name is an entry point too');

        $flow = $browser->flow('flow:Campaign\\Orders\\PlaceOrderPayload');
        self::assertNotNull($flow);
        self::assertSame('class:Campaign\\Orders\\PlaceOrderHandler', $flow['steps'][1]['ref']['id'] ?? null, 'a step carries the node the view focuses');
        self::assertSame('Orders', $flow['steps'][1]['ref']['module']);
        self::assertFalse($flow['complete']);
        $conditional = array_values(array_filter($flow['gaps'], static fn (array $g): bool => $g['kind'] === FlowTracer::GAP_CONDITIONAL_LISTENER))[0];
        self::assertSame('class:Campaign\\Search\\ReindexListener', $conditional['ref']['id']);

        self::assertNull($browser->flow('class:Campaign\\Orders\\PlaceOrderPayload'), 'only a flow id opens a flow');
    }

    private function orders(): ExecutionFlow
    {
        $flow = $this->intelligence($this->ordersFiles())->getExecutionFlow('flow:Campaign\\Orders\\PlaceOrderPayload');
        self::assertNotNull($flow);

        return $flow;
    }

    /** @return array<string, string> */
    private function ordersFiles(): array
    {
        $files = [
            'src/modules/Orders/Orders.php' => self::ORDERS,
            'src/modules/Bus/Bus.php' => self::BUS,
            'src/modules/Search/Search.php' => self::SEARCH,
            'src/modules/Pricing/Pricing.php' => self::PRICING,
            'packages/semitexa-core/src/Pricing.php' => self::CORE_PRICES,
            'src/modules/ShopPricing/Prices.php' => self::SHOP_PRICES,
        ];
        // Five modules besides Bus import its Writer: the framework-shaped class.
        foreach (['Orders', 'Search', 'Pricing', 'ShopPricing', 'Mail'] as $module) {
            $files["src/modules/{$module}/UsesWriter.php"] = "namespace Campaign\\{$module}\\Wiring;\n\nuse Campaign\\Bus\\Writer;\n\nfinal class UsesWriter { public function w(Writer \$w): void {} }\n";
        }

        return array_map(self::php(...), $files);
    }

    private static function php(string $body): string
    {
        if (str_starts_with($body, '<?php')) {
            return $body;
        }
        [$namespace, $rest] = explode("\n", $body, 2);

        return "<?php\n{$namespace}\n\n" . self::HEADER . $rest;
    }

    /** @param array<string, string> $files */
    private function intelligence(array $files): IntelligenceLayer
    {
        $files = array_map(self::php(...), $files);

        return new IntelligenceLayer(new GraphQueryService(self::graphOf($files)->storage));
    }

    /** @return list<string> */
    private function gapsAt(ExecutionFlow $flow, string $kind): array
    {
        $at = [];
        foreach ($flow->gaps as $gap) {
            if ($gap['kind'] === $kind) {
                $at[] = substr($gap['at'], strlen('class:'));
            }
        }

        return $at;
    }
}
