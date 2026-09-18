<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The layer that reads meaning off the graph.
 *
 * Second-heaviest reader of the domain models after GraphQueryService — 50
 * property reads — and it had no test. Everything it returns is assembled
 * from a Node's `metadata` and from an Edge's endpoints, so it is exactly the
 * code that a change to either model breaks, and exactly the code that the
 * per-change analyser cannot vouch for: at level 0 PHPStan does not report a
 * call to an undefined method.
 *
 * It composes over QueryInterface, so it is seeded through the real store
 * rather than a double — the mapper runs, as it does in production.
 */
final class IntelligenceLayerTest extends TestCase
{
    private OrmManager $orm;
    private GraphStorage $storage;
    private IntelligenceLayer $intelligence;

    protected function setUp(): void
    {
        $this->orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));

        // Written out rather than collected: the schema collector reads every
        // resource model in the workspace, not this package's four.
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

        $this->intelligence = new IntelligenceLayer(new GraphQueryService($this->storage));
        $this->seed();
    }

    #[Test]
    public function domain_context_follows_the_edge_and_reads_the_node_it_lands_on(): void
    {
        $context = $this->intelligence->getDomainContext('handler:CreateOrder');

        self::assertNotNull($context);
        self::assertSame('domain:Orders', $context->id);
        self::assertSame('Orders', $context->name);
    }

    #[Test]
    public function a_node_that_belongs_to_no_domain_has_no_context(): void
    {
        self::assertNull($this->intelligence->getDomainContext('service:Orphan'));
    }

    #[Test]
    public function intent_is_assembled_out_of_the_metadata_of_the_node_the_edge_points_at(): void
    {
        $intent = $this->intelligence->getIntent('handler:CreateOrder');

        self::assertNotNull($intent);
        self::assertSame('handler:CreateOrder', $intent->nodeId);
        self::assertSame('Places an order', $intent->purpose);
        self::assertSame(['validate', 'persist'], $intent->responsibilities);
        self::assertSame(0.9, $intent->confidence);
    }

    #[Test]
    public function a_node_with_no_intent_edge_infers_nothing(): void
    {
        self::assertNull($this->intelligence->getIntent('service:Orphan'));
    }

    #[Test]
    public function hotspots_come_back_worst_first_and_carry_their_metadata(): void
    {
        $hotspots = $this->intelligence->getHotspots();

        self::assertCount(2, $hotspots);
        // Sorted by risk, descending — the ordering is the whole value of the list.
        self::assertSame('handler:CreateOrder', $hotspots[0]->nodeId);
        self::assertSame(9.5, $hotspots[0]->riskScore);
        self::assertSame(12, $hotspots[0]->incomingEdges);
        self::assertTrue($hotspots[0]->isCriticalPath);
        self::assertSame('service:Orphan', $hotspots[1]->nodeId);
        self::assertSame(1.0, $hotspots[1]->riskScore);
    }

    #[Test]
    public function the_limit_trims_the_list_after_it_has_been_ranked(): void
    {
        $hotspots = $this->intelligence->getHotspots(limit: 1);

        self::assertCount(1, $hotspots);
        self::assertSame('handler:CreateOrder', $hotspots[0]->nodeId, 'the limit must keep the worst, not the first stored');
    }

    private function seed(): void
    {
        foreach ([
            new Node('handler:CreateOrder', NodeType::Handler, 'App\\Orders\\CreateOrderHandler', 'src/Orders/CreateOrderHandler.php', 12, 48, 'Orders', []),
            new Node('service:Orphan', NodeType::Service, 'App\\Loose\\Orphan', 'src/Loose/Orphan.php', 3, 20, 'Loose', []),
            new Node('domain:Orders', NodeType::DomainContext, 'Orders', 'src/Orders', 0, 0, 'Orders', ['name' => 'Orders']),
            // getIntent() reads metadata off whatever the IntentFor edge points
            // at and never checks the type, so this node's kind is incidental —
            // there is no Documentation case in NodeType.
            new Node('doc:CreateOrder', NodeType::DomainContext, 'App\\Orders\\CreateOrderHandler', 'docs/orders.md', 1, 40, 'Orders', [
                'purpose'          => 'Places an order',
                'responsibilities' => ['validate', 'persist'],
                'inferred_from'    => ['docblock'],
                'confidence'       => 0.9,
            ]),
            // Stored least-risky first, so a list that came back in insertion
            // order would pass the count assertion and fail the ordering one.
            new Node('hotspot:Orphan', NodeType::Hotspot, 'hotspot:Orphan', 'src/Loose/Orphan.php', 0, 0, 'Loose', [
                'target_node_id' => 'service:Orphan',
                'risk_score'     => 1.0,
            ]),
            new Node('hotspot:CreateOrder', NodeType::Hotspot, 'hotspot:CreateOrder', 'src/Orders/CreateOrderHandler.php', 0, 0, 'Orders', [
                'target_node_id'   => 'handler:CreateOrder',
                'risk_score'       => 9.5,
                'incoming_edges'   => 12,
                'is_critical_path' => true,
            ]),
        ] as $node) {
            $this->storage->nodes->upsert($node);
        }

        foreach ([
            new Edge('handler:CreateOrder', 'domain:Orders', EdgeType::BelongsToDomain),
            new Edge('handler:CreateOrder', 'doc:CreateOrder', EdgeType::IntentFor),
        ] as $edge) {
            $this->storage->edges->upsert($edge);
        }
    }
}
