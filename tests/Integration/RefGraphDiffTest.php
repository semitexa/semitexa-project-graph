<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The working tree against a git ref, edge by edge, from two fresh graphs.
 * The old diff compared node and edge COUNTS with a baseline kept in /tmp,
 * lost with the container.
 */
final class RefGraphDiffTest extends TestCase
{
    private function diff(GitFixtureRepo $repo, string $ref = 'HEAD'): array
    {
        return (new RefGraphDiff())->diff($repo->root, $ref, sys_get_temp_dir() . '/semitexa-graph-diff-scratch');
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges @return list<string> */
    private static function keys(array $edges): array
    {
        return array_map(static fn ($e): string => str_replace("\0", ' ', GraphDiff::edgeKey($e)), $edges);
    }

    #[Test]
    public function an_unchanged_tree_has_no_structural_difference(): void
    {
        $repo = GitFixtureRepo::create();
        $result = $this->diff($repo);

        self::assertGreaterThan(0, $result['head']->edges->countAll(), 'precondition: the head graph is not empty');
        self::assertTrue($result['diff']->isEmpty());
    }

    #[Test]
    public function a_changed_route_attribute_shows_the_route_it_dropped_and_the_one_it_serves_now(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Orders/PlaceOrderPayload.php', str_replace("path: '/orders'", "path: '/orders/place'", $repo->read('Orders/PlaceOrderPayload.php')));

        $diff = $this->diff($repo)['diff'];
        $payload = GraphFixture::classId('Orders\\PlaceOrderPayload');

        // The base side reads the BASE file's attribute — not the version this
        // process may have loaded, which is the whole point of the diff.
        self::assertContains('serves_route ' . $payload . ' ' . NodeId::forRoute('POST', '/orders'), self::keys($diff->removed));
        self::assertContains('serves_route ' . $payload . ' ' . NodeId::forRoute('POST', '/orders/place'), self::keys($diff->added));
    }

    #[Test]
    public function a_committed_ref_is_compared_too_and_the_worktree_is_removed(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->delete('Mail/SendReceiptListener.php');
        $repo->commit('drop the receipt listener');

        $diff = $this->diff($repo, 'HEAD~1')['diff'];

        self::assertContains(
            'listens_to ' . GraphFixture::classId('Mail\\SendReceiptListener') . ' ' . GraphFixture::classId('Orders\\OrderPlaced'),
            self::keys($diff->removed),
        );
        self::assertSame([], $diff->added);
        self::assertCount(1, $repo->worktrees(), 'only the main worktree remains');
    }

    #[Test]
    public function a_directory_outside_git_is_refused_with_a_hint(): void
    {
        $dir = sys_get_temp_dir() . '/semitexa-not-a-repo-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('--path=');
            (new RefGraphDiff())->diff($dir, 'HEAD', sys_get_temp_dir());
        } finally {
            rmdir($dir);
        }
    }

    #[Test]
    public function an_unknown_ref_is_refused_and_leaves_no_worktree(): void
    {
        $repo = GitFixtureRepo::create();

        try {
            $this->diff($repo, 'no-such-ref');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no-such-ref', $e->getMessage());
        }
        self::assertCount(1, $repo->worktrees());
    }
}
