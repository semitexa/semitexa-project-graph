<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

// No Semitexa\ProjectGraph\Tests\ entry in the workspace autoload map; see GraphFixtureTest.
require_once __DIR__ . '/../Support/GraphTestStore.php';
require_once __DIR__ . '/../Support/GraphFixture.php';

/**
 * One extractor failing on one attribute must not erase the rest of the file.
 *
 * An attribute argument naming a constant of a class the process cannot load
 * cannot be evaluated, and an extractor that instantiates that attribute
 * throws. That used to drop every node and edge of the file — its extends,
 * its new-expressions, everything — and report the file as failed. Measured
 * on the workspace: three files, all of them test or example classes whose
 * attributes name an enum in a non-autoloaded namespace.
 */
final class ExtractorFailureContainmentTest extends TestCase
{
    #[Test]
    public function the_file_keeps_its_other_edges_and_the_failure_is_reported(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/AuditedOrderService.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

            use Semitexa\Core\Attribute\SatisfiesServiceContract;

            #[SatisfiesServiceContract(of: OrderRepository::class, factoryKey: NotLoadable\Kind::Audit)]
            final class AuditedOrderService
            {
                public function make(): OrderResource
                {
                    return new OrderResource();
                }
            }
            PHP);

        $result = $fixture->refresh();

        self::assertTrue($fixture->hasEdge(
            EdgeType::Instantiates,
            GraphFixture::classId('Orders\\AuditedOrderService'),
            GraphFixture::classId('Orders\\OrderResource'),
        ));
        self::assertSame(1, $result->filesErrored);
        self::assertStringContainsString('SatisfiesServiceContract', $result->errors[0]['message']);
    }
}
