<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Tests\Support\GraphTestStore;

/**
 * A node known only because an edge points at it gets the type its id says.
 * Every placeholder used to be "class": on the workspace 104 tables, 60
 * config keys, 38 routes, 17 slots and 14 permissions counted as classes.
 */
final class PlaceholderTypeTest extends TestCase
{
    /** @return iterable<string, array{string, NodeType}> */
    public static function ids(): iterable
    {
        yield 'class'      => ['class:App\\Missing', NodeType::Class_];
        yield 'table'      => ['table:orders', NodeType::Table];
        yield 'config'     => ['config:DB_HOST', NodeType::ConfigKey];
        yield 'route'      => ['route:GET:/x', NodeType::Route];
        yield 'permission' => ['permission:orders.read', NodeType::Permission];
        yield 'slot'       => ['slot:sidebar', NodeType::Slot];
        yield 'unknown'    => ['unknown:orders', NodeType::Unresolved];
    }

    #[Test]
    #[DataProvider('ids')]
    public function a_placeholder_is_typed_by_its_id(string $id, NodeType $expected): void
    {
        $storage = GraphTestStore::create()->storage;
        $storage->upsertEdge(new Edge('class:App\\Source', $id, EdgeType::Imports));

        $node = $storage->nodes->findById($id);
        self::assertNotNull($node);
        self::assertTrue($node->getIsPlaceholder());
        self::assertSame($expected, $node->getType());
    }
}
