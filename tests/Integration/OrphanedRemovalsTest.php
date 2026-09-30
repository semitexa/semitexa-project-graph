<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Diff\OrphanedRemovals;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A removal fails the gate only when it leaves something pointing at nothing.
 */
final class OrphanedRemovalsTest extends TestCase
{
    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    private function gate(GitFixtureRepo $repo): array
    {
        $result = (new RefGraphDiff())->diff($repo->root, 'HEAD', sys_get_temp_dir() . '/semitexa-graph-diff-scratch');

        return [OrphanedRemovals::find($result['diff'], $result['base'], $result['head']), OrphanedRemovals::unreadableFiles($result['head'])];
    }

    /** @param list<array<string, mixed>> $orphans @return list<string> */
    private static function kinds(array $orphans): array
    {
        return array_map(static fn (array $o): string => $o['kind'] . ' ' . $o['subject'], $orphans);
    }

    #[Test]
    public function deleting_a_listener_nothing_depends_on_is_clean(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->delete('Mail/SendReceiptListener.php');

        self::assertSame([[], []], $this->gate($repo));
    }

    #[Test]
    public function a_payload_that_still_serves_a_route_but_lost_its_handler_is_orphaned(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->delete('Orders/PlaceOrderHandler.php');

        [$orphans] = $this->gate($repo);

        self::assertSame(['unhandled_payload ' . GraphFixture::classId('Orders\\PlaceOrderPayload')], self::kinds($orphans));
        self::assertSame([NodeId::forRoute('POST', '/orders')], $orphans[0]['still_used_by']);
    }

    #[Test]
    public function a_deleted_class_other_classes_still_reference_is_orphaned(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->delete('Cycle/Pong.php');

        [$orphans] = $this->gate($repo);

        self::assertSame(['deleted_class_still_referenced ' . GraphFixture::classId('Cycle\\Pong')], self::kinds($orphans));
        self::assertSame([GraphFixture::classId('Cycle\\Ping')], $orphans[0]['still_used_by']);
    }

    #[Test]
    public function an_injected_contract_that_lost_its_only_implementation_is_orphaned(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Orders/SqlOrderRepository.php', str_replace(
            'final class SqlOrderRepository',
            "#[\\Semitexa\\Core\\Attribute\\SatisfiesServiceContract(of: OrderRepository::class)]\nfinal class SqlOrderRepository",
            $repo->read('Orders/SqlOrderRepository.php'),
        ));
        $repo->commit('wire the repository contract');
        $repo->write('Orders/SqlOrderRepository.php', str_replace("#[\\Semitexa\\Core\\Attribute\\SatisfiesServiceContract(of: OrderRepository::class)]\n", '', $repo->read('Orders/SqlOrderRepository.php')));

        [$orphans] = $this->gate($repo);

        self::assertSame(['unsatisfied_contract ' . GraphFixture::classId('Orders\\OrderRepository')], self::kinds($orphans));
        self::assertSame([GraphFixture::classId('Orders\\PlaceOrderHandler')], $orphans[0]['still_used_by']);
    }

    #[Test]
    public function a_head_file_the_graph_cannot_parse_makes_the_gate_unable_to_pass(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Orders/Half.php', "<?php\nfinal class Half {\n");

        [, $unreadable] = $this->gate($repo);

        self::assertCount(1, $unreadable);
        self::assertStringEndsWith('/Orders/Half.php', $unreadable[0]);
    }
}
