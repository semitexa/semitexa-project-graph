<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

/**
 * A class node belongs to the file that declares it.
 *
 * HandlerExtractor used to create the node of a handler's resource class with
 * the HANDLER's file. When the handler was indexed first, the resource class
 * was stored as part of the handler file: its own file's declaration was then
 * skipped, and re-indexing the handler removed it. Found on the workspace by
 * the duplicate_class gap: core's ResourceResponse was held as part of a music
 * handler.
 */
final class NodeOwnershipTest extends TestCase
{
    #[Test]
    public function a_handlers_resource_class_is_owned_by_its_own_file_whatever_the_indexing_order(): void
    {
        $fixture = GraphFixture::create();
        // Index the handler while the resource's own file is absent, so the
        // handler's extraction is the first to mention the resource class...
        $resourceSource = $fixture->read('Orders/OrderResource.php');
        $fixture->delete('Orders/OrderResource.php');
        $fixture->build();
        // ...then let the file that declares it appear.
        $fixture->write('Orders/OrderResource.php', $resourceSource);
        $fixture->refresh();

        $resource = $fixture->storage->nodes->findById(GraphFixture::classId('Orders\\OrderResource'));
        self::assertNotNull($resource);
        self::assertStringEndsWith('/Orders/OrderResource.php', $resource->getFile());
        self::assertSame(NodeType::Resource, $resource->getType(), 'the role the handler gave it survives');
        self::assertFalse($resource->getIsPlaceholder());
        self::assertSame([], $fixture->storage->gaps->countByKind(), 'no duplicate_class gap: nothing was declared twice');
        self::assertTrue($fixture->hasEdge(EdgeType::Produces, GraphFixture::classId('Orders\\PlaceOrderHandler'), GraphFixture::classId('Orders\\OrderResource')));
    }
}
