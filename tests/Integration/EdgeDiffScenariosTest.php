<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Diff\OrphanedRemovals;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The review scenarios the edge diff exists for, end to end on a git
 * repository: what the diff shows and whether the gate passes.
 */
final class EdgeDiffScenariosTest extends TestCase
{
    /** @return array{added: list<string>, removed: list<string>, orphans: list<string>} */
    private function review(GitFixtureRepo $repo): array
    {
        $result = (new RefGraphDiff())->diff($repo->root, 'HEAD', sys_get_temp_dir() . '/semitexa-graph-diff-scratch');
        $keys = static fn (array $edges): array => array_map(static fn ($e): string => str_replace("\0", ' ', GraphDiff::edgeKey($e)), $edges);

        return [
            'added'   => $keys($result['diff']->added),
            'removed' => $keys($result['diff']->removed),
            'orphans' => array_map(
                static fn (array $o): string => $o['kind'] . ' ' . $o['subject'],
                OrphanedRemovals::find($result['diff'], $result['base'], $result['head']),
            ),
        ];
    }

    #[Test]
    public function a_new_handler_shows_its_wiring_and_passes(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Orders/CancelOrderPayload.php', "<?php\n\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;\n\nuse Semitexa\\Core\\Attribute\\AsPublicPayload;\n\n#[AsPublicPayload(path: '/orders/cancel', methods: ['POST'])]\nfinal class CancelOrderPayload\n{\n}\n");
        $repo->write('Orders/CancelOrderHandler.php', "<?php\n\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;\n\nuse Semitexa\\Core\\Attribute\\AsPayloadHandler;\n\n#[AsPayloadHandler(payload: CancelOrderPayload::class, resource: OrderResource::class)]\nfinal class CancelOrderHandler\n{\n}\n");

        $review = $this->review($repo);

        self::assertContains('handles ' . GraphFixture::classId('Orders\\CancelOrderHandler') . ' ' . GraphFixture::classId('Orders\\CancelOrderPayload'), $review['added']);
        self::assertContains('serves_route ' . GraphFixture::classId('Orders\\CancelOrderPayload') . ' route:POST:/orders/cancel', $review['added']);
        self::assertSame([], $review['removed']);
        self::assertSame([], $review['orphans']);
    }

    #[Test]
    public function a_rename_with_its_callers_updated_is_a_removal_and_an_addition_but_no_orphan(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->delete('Cycle/Pong.php');
        $repo->write('Cycle/Pang.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle;\n\nfinal class Pang\n{\n    public function next(): Ping\n    {\n        return new Ping();\n    }\n}\n");
        $repo->write('Cycle/Ping.php', str_replace('Pong', 'Pang', $repo->read('Cycle/Ping.php')));

        $review = $this->review($repo);

        self::assertContains('instantiates ' . GraphFixture::classId('Cycle\\Ping') . ' ' . GraphFixture::classId('Cycle\\Pong'), $review['removed']);
        self::assertContains('instantiates ' . GraphFixture::classId('Cycle\\Ping') . ' ' . GraphFixture::classId('Cycle\\Pang'), $review['added']);
        self::assertSame([], $review['orphans'], 'nothing refers to the old name any more');
    }

    #[Test]
    public function a_rename_that_misses_a_caller_is_caught(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Cycle/Pang.php', str_replace('class Pong', 'class Pang', $repo->read('Cycle/Pong.php')));
        $repo->delete('Cycle/Pong.php');

        self::assertSame(['deleted_class_still_referenced ' . GraphFixture::classId('Cycle\\Pong')], $this->review($repo)['orphans']);
    }

    #[Test]
    public function an_event_still_listened_to_that_nobody_emits_any_more_is_orphaned(): void
    {
        $repo = GitFixtureRepo::create();
        $emitter = "<?php\n\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;\n\nfinal class OrderEvents\n{\n    private object \$events;\n\n    public function placed(): void\n    {\n        \$this->events->dispatch(new OrderPlaced());\n    }\n}\n";
        $repo->write('Orders/OrderEvents.php', $emitter);
        $repo->commit('emit OrderPlaced');
        $repo->write('Orders/OrderEvents.php', str_replace('$this->events->dispatch(new OrderPlaced());', '', $emitter));

        self::assertSame(['unemitted_event ' . GraphFixture::classId('Orders\\OrderPlaced')], $this->review($repo)['orphans']);
    }
}
