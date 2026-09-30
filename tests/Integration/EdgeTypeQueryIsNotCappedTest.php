<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Tests\Support\GraphTestStore;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';

/**
 * findByType() used to stop at 1000 rows unless told otherwise, and nothing
 * told the caller the answer was partial. The workspace graph has 1225
 * injects_readonly edges alone.
 */
final class EdgeTypeQueryIsNotCappedTest extends TestCase
{
    #[Test]
    public function every_edge_of_a_type_comes_back(): void
    {
        $storage = GraphTestStore::create()->storage;
        $storage->transaction(function () use ($storage): void {
            for ($i = 0; $i < 1001; $i++) {
                $storage->edges->upsert(new Edge('class:A' . $i, 'class:B', EdgeType::InjectsReadonly));
            }
        });

        self::assertCount(1001, $storage->edges->findByType(EdgeType::InjectsReadonly));
        self::assertCount(10, $storage->edges->findByType(EdgeType::InjectsReadonly, 10));
    }
}
