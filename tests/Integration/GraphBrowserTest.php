<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Query\GraphBrowser;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The slices the Graph view and the HTML export are drawn from.
 *
 * The rule these pin down: walking from an entry point reaches what it runs —
 * route, then payload, then handler, then what the handler depends on — and a
 * request-supplied id is only ever a lookup key, never a class to load.
 */
final class GraphBrowserTest extends TestCase
{
    #[Test]
    public function a_route_expands_to_its_payload_then_its_handler(): void
    {
        $fixture = GraphFixture::built();
        $browser = new GraphBrowser($fixture->storage, $fixture->root);

        $route = NodeId::forRoute('POST', '/orders');
        $payload = GraphFixture::classId('Orders\\PlaceOrderPayload');
        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');

        $one = $browser->subgraph($route, 1);
        self::assertNotNull($one);
        self::assertSame([$route, $payload], array_column($one['nodes'], 'id'));

        $three = $browser->subgraph($route, 3);
        self::assertNotNull($three);
        $ids = array_column($three['nodes'], 'id');
        self::assertContains($handler, $ids);
        self::assertContains(GraphFixture::classId('Orders\\OrderRepository'), $ids);
        self::assertContains(['s' => $payload, 't' => $handler, 'k' => 'handles', 'c' => 'wiring'], $three['edges']);
    }

    #[Test]
    public function every_edge_in_a_slice_has_both_ends_in_it(): void
    {
        $fixture = GraphFixture::built();
        $slice = (new GraphBrowser($fixture->storage, $fixture->root))
            ->subgraph(GraphFixture::classId('Orders\\PlaceOrderHandler'), 4);
        self::assertNotNull($slice);

        $ids = array_flip(array_column($slice['nodes'], 'id'));
        foreach ($slice['edges'] as $edge) {
            self::assertArrayHasKey($edge['s'], $ids);
            self::assertArrayHasKey($edge['t'], $ids);
            self::assertNotContains($edge['k'], GraphBrowser::NOISE_EDGES);
        }
    }

    #[Test]
    public function describe_lists_both_directions_with_project_relative_paths(): void
    {
        $fixture = GraphFixture::built();
        $node = (new GraphBrowser($fixture->storage, $fixture->root))
            ->describe(GraphFixture::classId('Orders\\OrderRepository'));
        self::assertNotNull($node);

        self::assertStringStartsNotWith('/', $node['node']['file']);
        self::assertContains('injects_readonly', array_column($node['in'], 'kind'));
        self::assertGreaterThan(0, $node['fanIn']);
    }

    #[Test]
    public function the_summary_roots_the_tree_at_routes_commands_and_handlers(): void
    {
        $fixture = GraphFixture::built();
        $summary = (new GraphBrowser($fixture->storage, $fixture->root))->summary();

        self::assertContains(NodeId::forRoute('POST', '/orders'), array_column($summary['entries']['route'], 'id'));
        self::assertContains(GraphFixture::classId('Orders\\PlaceOrderHandler'), array_column($summary['entries']['handler'], 'id'));
        self::assertArrayNotHasKey('doc_node', $summary['counts']['byType']);
    }

    #[Test]
    public function search_puts_a_name_prefix_first(): void
    {
        $fixture = GraphFixture::built();
        $hits = (new GraphBrowser($fixture->storage, $fixture->root))->search('Order');

        self::assertNotSame([], $hits);
        self::assertStringStartsWith('Order', $hits[0]['name']);
        self::assertSame([], (new GraphBrowser($fixture->storage, $fixture->root))->search('O'));
    }

    #[Test]
    public function the_path_to_a_dependency_starts_at_an_entry_point(): void
    {
        $fixture = GraphFixture::built();
        $browser = new GraphBrowser($fixture->storage, $fixture->root);

        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');
        self::assertSame(
            [$handler, GraphFixture::classId('Orders\\OrderRepository')],
            $browser->pathToEntry(GraphFixture::classId('Orders\\OrderRepository')),
        );
        self::assertSame([$handler], $browser->pathToEntry($handler));
        self::assertNull($browser->pathToEntry('class:Nope'));
    }

    #[Test]
    public function findings_carry_their_nodes_so_a_click_can_focus_one(): void
    {
        $fixture = GraphFixture::built();
        $findings = (new GraphBrowser($fixture->storage, $fixture->root))->findings();

        $loops = array_map(static fn (array $c): array => array_column($c['members'], 'id'), $findings['cycles']);
        self::assertContains(
            [GraphFixture::classId('Cycle\\Ping'), GraphFixture::classId('Cycle\\Pong')],
            array_map(static function (array $ids): array { sort($ids); return $ids; }, $loops),
        );
        $unusedIds = array_map(static fn (array $u): string => $u['node']['id'], $findings['unused']);
        sort($unusedIds);
        self::assertSame([
            GraphFixture::classId('Dynamic\\PluginLoader'),
            GraphFixture::classId('Mail\\SendReceiptListener'),
            GraphFixture::classId('Orders\\PlaceOrderHandler'),
            GraphFixture::classId('Orders\\SqlOrderRepository'),
            GraphFixture::classId('Orphan\\NobodyUsesMe'),
        ], $unusedIds, 'precondition: the fixture\'s unused classes are reported');
        foreach ($findings['unused'] as $u) {
            self::assertStringStartsWith('unused:', $u['finding']);
            self::assertNotSame('', $u['node']['id']);
        }
        self::assertArrayHasKey('complete', $findings['coverage']);
    }

    #[Test]
    public function search_is_literal_so_wildcards_match_only_themselves(): void
    {
        $fixture = GraphFixture::built();
        $browser = new GraphBrowser($fixture->storage, $fixture->root);

        self::assertSame([], $browser->search('__'));
        self::assertSame([], $browser->search('%%'));
        self::assertNotSame([], $browser->search('Order'));
    }

    #[Test]
    public function a_scan_that_finds_no_change_still_dates_the_graph_current(): void
    {
        $fixture = GraphFixture::built();
        $fixture->storage->setMeta('last_update', '1');

        $fixture->refresh();

        self::assertGreaterThan(1, (int) $fixture->storage->getMeta('last_update'));
    }

    #[Test]
    public function a_hostile_id_is_looked_up_never_autoloaded(): void
    {
        $fixture = GraphFixture::built();
        $browser = new GraphBrowser($fixture->storage, $fixture->root);

        $asked = [];
        $spy = static function (string $class) use (&$asked): void {
            $asked[] = $class;
        };
        spl_autoload_register($spy, prepend: true);
        try {
            self::assertNull($browser->describe('class:Hostile\\Autoload\\Me'));
            self::assertNull($browser->subgraph('Hostile\\Autoload\\Me', 2));
            self::assertSame([], $browser->search('Hostile\\Autoload\\Me'));
            self::assertNull($browser->pathToEntry('class:Hostile\\Autoload\\Me'));
        } finally {
            spl_autoload_unregister($spy);
        }

        self::assertSame([], array_filter($asked, static fn (string $c): bool => str_starts_with($c, 'Hostile')));
    }
}
