<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The query surface, against a real store.
 *
 * This package had three test files for 108 source files, and the two
 * heaviest readers of its domain models — GraphQueryService at 56 property
 * reads, IntelligenceLayer at 50 — had none. That is affordable while nobody
 * touches them and expensive the moment somebody does: the analyser this
 * project runs per change is level 0, which does NOT report a call to an
 * undefined method, so a refactor of Node or Edge can pass every gate and
 * fail at runtime.
 *
 * So these tests are deliberately about the SHAPE of the models rather than
 * about clever queries: every one of them reads properties off a Node or an
 * Edge that came back out of the store, which is the path that breaks.
 *
 * A real SQLite store, not a double. A double would hold whatever shape the
 * test invented, and the mapper — which is where a model change actually
 * lands — would never run.
 */
final class GraphQueryServiceTest extends TestCase
{
    private OrmManager $orm;
    private GraphStorage $storage;
    private GraphQueryService $query;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));

        // The tables are written out rather than collected. The schema
        // collector reads EVERY resource model in the workspace, not this
        // package's four, and one of them asks SQLite for an AUTOINCREMENT on a
        // non-integer key — so collecting here fails for a reason that has
        // nothing to do with the graph.
        $db = $this->orm->getAdapter();
        $db->execute(
            'CREATE TABLE graph_nodes (
                id TEXT PRIMARY KEY, type TEXT NOT NULL, fqcn TEXT NOT NULL, name TEXT NOT NULL,
                file TEXT NOT NULL, line INTEGER NOT NULL, end_line INTEGER NOT NULL DEFAULT 0,
                module TEXT NOT NULL DEFAULT \'\', metadata TEXT NOT NULL DEFAULT \'{}\',
                is_placeholder INTEGER NOT NULL DEFAULT 0
            )',
        );
        $db->execute(
            'CREATE TABLE graph_edges (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source_id TEXT NOT NULL, target_id TEXT NOT NULL,
                type TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT \'{}\'
            )',
        );
        $db->execute(
            'CREATE TABLE graph_file_index (
                path TEXT PRIMARY KEY, content_hash TEXT NOT NULL, indexed_at INTEGER NOT NULL,
                module TEXT NOT NULL, line_count INTEGER NOT NULL, is_dirty INTEGER NOT NULL DEFAULT 0
            )',
        );
        $db->execute('CREATE TABLE graph_meta (meta_key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $this->storage = new GraphStorage(
            $this->orm->getAdapter(),
            $this->orm->getTransactionManager(),
            $this->orm->getMapperRegistry(),
            $this->orm->getResourceModelHydrator(),
            $this->orm->getResourceModelMetadataRegistry(),
            $this->orm->getResourceModelRelationLoader(),
            $this->orm->getAggregateWriteEngine(),
        );

        $this->query = new GraphQueryService($this->storage);
        $this->seed();
    }

    #[Test]
    public function a_node_comes_back_with_every_field_it_was_stored_with(): void
    {
        $node = $this->query->getNode('handler:CreateOrder');

        self::assertNotNull($node);
        self::assertSame('handler:CreateOrder', $node->id);
        self::assertSame(NodeType::Handler, $node->type);
        self::assertSame('App\\Orders\\CreateOrderHandler', $node->fqcn);
        self::assertSame('src/Orders/CreateOrderHandler.php', $node->file);
        self::assertSame(12, $node->line);
        self::assertSame(48, $node->endLine);
        self::assertSame('Orders', $node->module);
        self::assertSame(['route' => '/orders'], $node->metadata);
        self::assertFalse($node->isPlaceholder);
    }

    #[Test]
    public function a_node_is_found_by_fqcn_as_well_as_by_id(): void
    {
        self::assertSame(
            'handler:CreateOrder',
            $this->query->getNode('App\\Orders\\CreateOrderHandler')?->id,
        );
    }

    #[Test]
    public function name_is_the_last_segment_of_the_fqcn(): void
    {
        self::assertSame('CreateOrderHandler', $this->query->getNode('handler:CreateOrder')?->name());
        // A class in no namespace is its own name — the branch strrpos misses.
        self::assertSame('Bare', $this->query->getNode('service:Bare')?->name());
    }

    #[Test]
    public function nodes_filter_by_type_and_by_module(): void
    {
        self::assertSame(
            ['handler:CreateOrder'],
            array_map(static fn (Node $n): string => $n->id, $this->query->findNodes(type: 'handler')),
        );

        $orders = array_map(static fn (Node $n): string => $n->id, $this->query->findNodes(module: 'Orders'));
        sort($orders);
        self::assertSame(['handler:CreateOrder', 'service:Bare', 'service:OrderRepo'], $orders);

        // Nothing asked for is nothing returned — not everything.
        self::assertSame([], $this->query->findNodes());
    }

    #[Test]
    public function an_edge_comes_back_with_its_endpoints_and_type(): void
    {
        $edges = $this->query->getEdges('handler:CreateOrder', direction: Direction::Outgoing);

        self::assertCount(1, $edges);
        self::assertSame('handler:CreateOrder', $edges[0]->sourceId);
        self::assertSame('service:OrderRepo', $edges[0]->targetId);
        self::assertSame(EdgeType::Uses, $edges[0]->type);
    }

    #[Test]
    public function direction_decides_which_end_of_the_edge_is_returned(): void
    {
        // Two things use the repo; both arrive as incoming, neither as outgoing.
        $incoming = array_map(
            static fn (Edge $e): string => $e->sourceId,
            $this->query->getEdges('service:OrderRepo', direction: Direction::Incoming),
        );
        sort($incoming);
        self::assertSame(['handler:CreateOrder', 'service:Bare'], $incoming);

        self::assertSame([], $this->query->getEdges('service:OrderRepo', direction: Direction::Outgoing));
    }

    #[Test]
    public function dependencies_and_usages_walk_opposite_ways_along_the_same_edge(): void
    {
        // Both return EDGES, walked in opposite directions — the far end is
        // the target going out and the source coming back.
        $deps = array_map(static fn (Edge $e): string => $e->targetId, $this->query->getDependencies('handler:CreateOrder'));
        self::assertContains('service:OrderRepo', $deps);

        $usages = array_map(static fn (Edge $e): string => $e->sourceId, $this->query->getUsages('service:OrderRepo'));
        self::assertContains('handler:CreateOrder', $usages);
    }

    #[Test]
    public function impact_reaches_past_the_first_hop(): void
    {
        // OrderRepo is used by CreateOrder, which is used by nothing else —
        // so the blast radius of the repo includes the handler above it.
        $impact = $this->query->getImpact(['service:OrderRepo'], maxDepth: 5);

        self::assertArrayHasKey('handler:CreateOrder', $impact->impacted);
        self::assertSame('handler:CreateOrder', $impact->impacted['handler:CreateOrder']->node->id);
        self::assertSame(1, $impact->impacted['handler:CreateOrder']->distance);
        // Invoicer uses the handler, so it is reached on the second hop.
        self::assertArrayHasKey('service:Invoicer', $impact->impacted);
        self::assertSame(2, $impact->impacted['service:Invoicer']->distance);
    }

    #[Test]
    public function a_cross_module_edge_is_reported_as_one_and_a_same_module_edge_is_not(): void
    {
        // It returns EDGES, and only for the types it scans — `uses` is not
        // among them, which is why the seed links Billing to Orders with
        // `calls` and the two same-module `uses` edges stay invisible here.
        $cross = $this->query->getCrossModuleEdges();

        self::assertCount(1, $cross);
        self::assertSame('service:Invoicer', $cross[0]->sourceId);
        self::assertSame('handler:CreateOrder', $cross[0]->targetId);

        self::assertSame([], $this->query->getCrossModuleEdges(moduleA: 'Orders'));
    }

    #[Test]
    public function search_matches_on_the_name_and_returns_whole_nodes(): void
    {
        $hits = $this->query->search('CreateOrder', limit: 10);

        self::assertNotSame([], $hits);
        self::assertContains('handler:CreateOrder', array_map(static fn (Node $n): string => $n->id, $hits));
    }

    /**
     * Four nodes across two modules, three edges — one of them crossing a
     * module boundary, which is the only shape getCrossModuleEdges() reports.
     */
    private function seed(): void
    {
        foreach ([
            new Node('handler:CreateOrder', NodeType::Handler, 'App\\Orders\\CreateOrderHandler', 'src/Orders/CreateOrderHandler.php', 12, 48, 'Orders', ['route' => '/orders']),
            new Node('service:OrderRepo', NodeType::Service, 'App\\Orders\\OrderRepository', 'src/Orders/OrderRepository.php', 9, 120, 'Orders', []),
            new Node('service:Invoicer', NodeType::Service, 'App\\Billing\\Invoicer', 'src/Billing/Invoicer.php', 7, 60, 'Billing', []),
            new Node('service:Bare', NodeType::Service, 'Bare', 'src/Bare.php', 1, 3, 'Orders', []),
        ] as $node) {
            $this->storage->nodes->upsert($node);
        }

        foreach ([
            new Edge('handler:CreateOrder', 'service:OrderRepo', EdgeType::Uses),
            new Edge('service:Invoicer', 'handler:CreateOrder', EdgeType::Calls),
            new Edge('service:Bare', 'service:OrderRepo', EdgeType::Uses),
        ] as $edge) {
            $this->storage->edges->upsert($edge);
        }
    }
}
