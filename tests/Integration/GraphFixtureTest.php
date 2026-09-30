<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// The workspace autoload map carries no Semitexa\ProjectGraph\Tests\ entry
// (it was dumped before this package had test support classes), so load them
// by path, as core, ssr and dev tests already do.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

/**
 * The fixture project produces the graph later tests will lean on.
 *
 * If one of these fails, the tests built on the fixture are testing
 * something other than what they claim — fix this first.
 */
final class GraphFixtureTest extends TestCase
{
    #[Test]
    public function the_fixture_builds_without_errors(): void
    {
        $fixture = GraphFixture::create();
        $result = $fixture->build();

        self::assertSame(11, $result->filesScanned);
        self::assertSame([], $result->errors);
    }

    #[Test]
    public function the_framework_wiring_in_the_fixture_becomes_edges(): void
    {
        $fixture = GraphFixture::built();

        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');
        $payload = GraphFixture::classId('Orders\\PlaceOrderPayload');

        self::assertTrue($fixture->hasEdge(EdgeType::Handles, $handler, $payload));
        self::assertTrue($fixture->hasEdge(EdgeType::Produces, $handler, GraphFixture::classId('Orders\\OrderResource')));
        self::assertTrue($fixture->hasEdge(EdgeType::InjectsReadonly, $handler, GraphFixture::classId('Orders\\OrderRepository')));
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, $payload, NodeId::forRoute('POST', '/orders')));
        self::assertTrue($fixture->hasEdge(
            EdgeType::ListensTo,
            GraphFixture::classId('Mail\\SendReceiptListener'),
            GraphFixture::classId('Orders\\OrderPlaced'),
        ));
    }

    #[Test]
    public function the_fixture_code_references_become_edges(): void
    {
        $fixture = GraphFixture::built();

        self::assertTrue($fixture->hasEdge(
            EdgeType::Implements,
            GraphFixture::classId('Orders\\SqlOrderRepository'),
            GraphFixture::classId('Orders\\OrderRepository'),
        ));
        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, GraphFixture::classId('Cycle\\Ping'), GraphFixture::classId('Cycle\\Pong')));
        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, GraphFixture::classId('Cycle\\Pong'), GraphFixture::classId('Cycle\\Ping')));
    }

    /**
     * One assertion per edge type the AST extractors emit. The php-parser 5
     * upgrade silently turned two of them off for the whole graph — accepts
     * (Param::getType() became the node kind) and imports (UseItem likewise)
     * — and nothing noticed, because no test asked for either.
     */
    #[Test]
    public function every_ast_edge_type_is_produced(): void
    {
        $fixture = GraphFixture::built();
        $handler = GraphFixture::classId('Orders\\PlaceOrderHandler');

        self::assertTrue($fixture->hasEdge(EdgeType::Accepts, $handler, GraphFixture::classId('Orders\\PlaceOrderPayload')), 'accepts');
        self::assertTrue($fixture->hasEdge(EdgeType::Returns, $handler, GraphFixture::classId('Orders\\OrderResource')), 'returns');
        self::assertTrue($fixture->hasEdge(EdgeType::Instantiates, $handler, GraphFixture::classId('Orders\\OrderPlaced')), 'instantiates');
        self::assertTrue($fixture->hasEdge(EdgeType::Implements, GraphFixture::classId('Orders\\SqlOrderRepository'), GraphFixture::classId('Orders\\OrderRepository')), 'implements');
        self::assertTrue($fixture->hasEdge(EdgeType::Imports, GraphFixture::classId('Mail\\SendReceiptListener'), GraphFixture::classId('Orders\\OrderPlaced')), 'imports');
    }

    #[Test]
    public function nothing_points_at_the_planted_unused_class(): void
    {
        $fixture = GraphFixture::built();

        self::assertTrue($fixture->storage->nodeExists(GraphFixture::classId('Orphan\\NobodyUsesMe')));
        self::assertSame([], $fixture->storage->edges->findByTarget(GraphFixture::classId('Orphan\\NobodyUsesMe')));
    }

    #[Test]
    public function the_broken_file_is_reported_and_the_rest_still_builds(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $result = $fixture->build();

        self::assertSame(1, $result->filesErrored);
        self::assertStringEndsWith('/Broken.php', $result->errors[0]['file']);
        self::assertTrue($fixture->storage->nodeExists(GraphFixture::classId('Cycle\\Ping')));
    }

    #[Test]
    public function a_refresh_sees_an_edited_file(): void
    {
        $fixture = GraphFixture::built();

        $fixture->write('Orphan/NobodyUsesMe.php', str_replace(
            "final class NobodyUsesMe\n{\n}",
            "final class NobodyUsesMe\n{\n    public function make(): object\n    {\n        return new \\" . GraphFixture::NS . "Cycle\\Ping();\n    }\n}",
            $fixture->read('Orphan/NobodyUsesMe.php'),
        ));
        $result = $fixture->refresh();

        self::assertSame(1, $result->filesScanned);
        self::assertTrue($fixture->hasEdge(
            EdgeType::Instantiates,
            GraphFixture::classId('Orphan\\NobodyUsesMe'),
            GraphFixture::classId('Cycle\\Ping'),
        ));
    }
}
