<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

/**
 * Attributes come from the file on disk, not from the class the process has
 * loaded.
 *
 * Reflection answered for whatever version of a class was loaded: after an
 * edit in watch mode that is the old one, in a worktree of another git ref it
 * is HEAD's, and a class that could not be loaded had no attributes at all.
 */
final class AttributesFromTheParsedFileTest extends TestCase
{
    #[Test]
    public function an_attribute_edited_on_disk_is_seen_by_a_refresh_in_the_same_process(): void
    {
        $fixture = GraphFixture::built();
        $payload = GraphFixture::classId('Orders\\PlaceOrderPayload');
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, $payload, NodeId::forRoute('POST', '/orders')));

        $fixture->write('Orders/PlaceOrderPayload.php', str_replace(
            "path: '/orders'",
            "path: '/orders/place'",
            $fixture->read('Orders/PlaceOrderPayload.php'),
        ));
        $fixture->refresh();

        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, $payload, NodeId::forRoute('POST', '/orders/place')));
        self::assertFalse($fixture->hasEdge(EdgeType::ServesRoute, $payload, NodeId::forRoute('POST', '/orders')));
    }

    #[Test]
    public function a_class_the_process_cannot_load_still_has_its_wiring(): void
    {
        $fixture = GraphFixture::built();
        $listener = GraphFixture::NS . 'Mail\\SendReceiptListener';

        self::assertFalse(class_exists($listener), 'precondition: the fixture classes are not autoloadable');
        self::assertTrue($fixture->hasEdge(
            EdgeType::ListensTo,
            NodeId::forClass($listener),
            GraphFixture::classId('Orders\\OrderPlaced'),
        ));
    }
}
