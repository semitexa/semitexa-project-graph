<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\GraphDiffCommand;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeDiffMarkdown;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeSetDiff;
use Semitexa\ProjectGraph\Application\Service\Diff\OrphanedRemovals;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regressions found by the 2026-10-02 test campaign against
 * `ai:review-graph:diff --base`, one per defect.
 */
final class RefDiffHardeningTest extends TestCase
{
    private const NS = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\';

    /** @return array<string, mixed> */
    private function diff(GitFixtureRepo $repo, string $ref = 'HEAD', string $path = ''): array
    {
        return (new RefGraphDiff())->diff($repo->root . ($path === '' ? '' : '/' . $path), $ref, GraphFixture::diffScratch());
    }

    #[Test]
    public function a_worktree_of_someone_else_survives_the_diff(): void
    {
        // The cleanup used to run a GLOBAL `git worktree prune`: every worktree
        // whose directory this process could not see was unregistered.
        $repo = GitFixtureRepo::create();
        $elsewhere = sys_get_temp_dir() . '/semitexa-graph-foreign-wt-' . bin2hex(random_bytes(4));
        $repo->git('worktree', 'add', '--quiet', '-b', 'hotfix', $elsewhere);
        exec('rm -rf ' . escapeshellarg($elsewhere)); // as seen from a container: the path does not exist here

        $this->diff($repo);

        self::assertCount(2, $repo->worktrees(), 'the diff unregistered a worktree it did not create');
        self::assertSame([], glob(GraphFixture::diffScratch() . '/graph-base-*') ?: []);
        $repo->git('worktree', 'prune');
    }

    #[Test]
    public function a_file_kept_out_of_the_dist_archive_is_still_in_the_base(): void
    {
        // `git archive` honours export-ignore: a committed, unchanged tooling
        // file existed only on the head side, its edges "added" on every PR.
        $repo = GitFixtureRepo::create();
        $repo->write('.gitattributes', "/tools export-ignore\n");
        $repo->write('tools/Probe.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Tools;\nfinal class Probe extends \\" . self::NS . "Orders\\OrderPlaced {}\n");
        $repo->commit('tooling');

        self::assertTrue($this->diff($repo)['diff']->isEmpty(), 'an export-ignored file was missing from the base');
    }

    #[Test]
    public function a_snapshot_another_run_still_holds_is_not_swept(): void
    {
        // Judged by /proc, a concurrent run in another PID namespace (a
        // one-off container, the host) looked dead and its export was deleted.
        $repo = GitFixtureRepo::create();
        $scratch = GraphFixture::diffScratch();
        @mkdir($scratch, 0777, true);
        $live = $scratch . '/graph-base-1-aaaaaaaaaaaa';
        $dead = $scratch . '/graph-base-2-bbbbbbbbbbbb';
        mkdir($live);
        mkdir($dead);
        touch($dead . '.lock');
        $lock = fopen($live . '.lock', 'c');
        self::assertNotFalse($lock);
        try {
            // Held through a separate open file description: flock is per description.
            $holder = proc_open([PHP_BINARY, '-r', 'flock($f = fopen($argv[1], "c"), LOCK_EX); echo "held\n"; fgets(STDIN);', $live . '.lock'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
            self::assertSame("held\n", fgets($pipes[1]));

            $this->diff($repo);

            self::assertDirectoryExists($live, 'a live export was swept');
            self::assertDirectoryDoesNotExist($dead, 'an export nobody holds stays behind');
            fwrite($pipes[0], "\n");
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($holder);
        } finally {
            fclose($lock);
            exec('rm -rf ' . escapeshellarg($live) . ' ' . escapeshellarg($live . '.lock') . ' ' . escapeshellarg($dead) . ' ' . escapeshellarg($dead . '.lock'));
        }
    }

    #[Test]
    public function a_ref_is_never_read_as_an_option(): void
    {
        $repo = GitFixtureRepo::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->diff($repo, '--output=/tmp/x');
    }

    #[Test]
    public function a_scope_the_base_never_had_is_an_empty_base_at_its_own_path(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('NewModule/Thing.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\NewModule;\nfinal class Thing extends \\" . self::NS . "Orders\\OrderPlaced {}\n");

        $result = $this->diff($repo, 'HEAD', 'NewModule');

        self::assertStringEndsWith('/NewModule', $result['base_root']);
        self::assertStringNotContainsString('/NewModule/NewModule', $result['base_root'], 'the scope was appended twice');
        self::assertNotEmpty($result['diff']->added);
    }

    #[Test]
    public function a_config_file_naming_a_class_is_not_a_change_against_head(): void
    {
        // file: ids carried the absolute path, so the base export and the
        // working tree held two "different" neon files.
        $repo = GitFixtureRepo::create();
        $repo->write('phpstan.neon', "rules:\n  - " . self::NS . "Orders\\SqlOrderRepository\n");
        $repo->commit('config');

        $result = $this->diff($repo);

        self::assertTrue($result['diff']->isEmpty(), 'a config file read from two roots compared as two files');
    }

    #[Test]
    public function a_gitignored_file_is_not_part_of_the_head(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('.gitignore', "/build/\n");
        $repo->commit('ignore build');
        $repo->write('build/LocalDebug.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Build;\nfinal class LocalDebug extends \\" . self::NS . "Orders\\OrderPlaced {}\n");

        $result = $this->diff($repo);

        self::assertTrue($result['diff']->isEmpty(), 'build output git ignores was read as part of the change');
    }

    #[Test]
    public function a_handler_moved_to_another_namespace_is_a_move_not_an_orphan(): void
    {
        $repo = GitFixtureRepo::create();
        $handler = str_replace(
            ['namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;', 'use Semitexa\\Core\\Attribute\\AsPayloadHandler;'],
            ['namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Billing;', "use Semitexa\\Core\\Attribute\\AsPayloadHandler;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\PlaceOrderPayload;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderResource;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderRepository;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderPlaced;"],
            $repo->read('Orders/PlaceOrderHandler.php'),
        );
        $repo->delete('Orders/PlaceOrderHandler.php');
        $repo->write('Billing/PlaceOrderHandler.php', $handler);

        $result = $this->diff($repo);
        $moves = $result['moves'];

        self::assertSame([], OrphanedRemovals::find($moves->remaining, $result['base'], $result['head']));
        self::assertCount(1, $moves->movedNodes);
        self::assertSame(GraphFixture::classId('Orders\\PlaceOrderHandler'), $moves->movedNodes[0]['from']);
        self::assertSame(GraphFixture::classId('Billing\\PlaceOrderHandler'), $moves->movedNodes[0]['to']);
        self::assertContains('handles', $moves->movedNodes[0]['kept']);
        foreach ($moves->remaining->removed as $edge) {
            self::assertNotSame(EdgeType::Handles, $edge->getType(), 'a moved handles edge was left as a removal');
        }

        $markdown = EdgeDiffMarkdown::render($moves->remaining, 'main', 'fixture', null, [], [], $moves);
        self::assertStringContainsString('- moved: `PlaceOrderHandler` (', $markdown);
        self::assertStringContainsString('handles', $markdown);
    }

    #[Test]
    public function a_renamed_class_with_the_same_body_is_paired_by_its_hash(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Mail/SendReceiptListener.php', str_replace('class SendReceiptListener', 'class MailReceiptListener', $repo->read('Mail/SendReceiptListener.php')));
        rename($repo->root . '/Mail/SendReceiptListener.php', $repo->root . '/Mail/MailReceiptListener.php');

        $moves = $this->diff($repo)['moves'];

        self::assertCount(1, $moves->movedNodes);
        self::assertSame(GraphFixture::classId('Mail\\MailReceiptListener'), $moves->movedNodes[0]['to']);
        self::assertContains('listens_to', $moves->movedNodes[0]['kept']);
    }

    #[Test]
    public function a_move_that_also_changes_the_far_end_is_a_warning_side_by_side(): void
    {
        // Handler moved AND re-pointed at another payload in one commit.
        $repo = GitFixtureRepo::create();
        $repo->write('Orders/OtherPayload.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Orders;\nuse Semitexa\\Core\\Attribute\\AsPublicPayload;\n#[AsPublicPayload(path: '/other', methods: ['POST'])]\nfinal class OtherPayload {}\n");
        $repo->commit('another payload');
        $handler = str_replace(
            ['namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;', 'payload: PlaceOrderPayload::class', 'use Semitexa\\Core\\Attribute\\AsPayloadHandler;'],
            ['namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Billing;', 'payload: OtherPayload::class', "use Semitexa\\Core\\Attribute\\AsPayloadHandler;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OtherPayload;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\PlaceOrderPayload;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderResource;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderRepository;\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders\\OrderPlaced;"],
            $repo->read('Orders/PlaceOrderHandler.php'),
        );
        $repo->delete('Orders/PlaceOrderHandler.php');
        $repo->write('Billing/PlaceOrderHandler.php', $handler);

        $result = $this->diff($repo);
        $moves = $result['moves'];

        $handles = array_values(array_filter($moves->farEndChanged, static fn (array $p): bool => $p['removed']->getType() === EdgeType::Handles));
        self::assertCount(1, $handles);
        self::assertSame(GraphFixture::classId('Orders\\PlaceOrderPayload'), $handles[0]['removed']->getTargetId());
        self::assertSame(GraphFixture::classId('Orders\\OtherPayload'), $handles[0]['added']->getTargetId());
        // Still unpaired: the payload that lost its handler is a real orphan.
        $kinds = array_column(OrphanedRemovals::find($moves->remaining, $result['base'], $result['head']), 'kind');
        self::assertContains('unhandled_payload', $kinds);
    }

    #[Test]
    public function the_comment_survives_backticks_pipes_and_html_in_names(): void
    {
        $edge = new Edge('class:App\\Orders\\Pay', 'route:GET:/orders|x/`id`/<b>bold</b>/{id:\\d+}', EdgeType::ServesRoute);

        $markdown = EdgeDiffMarkdown::render(new EdgeSetDiff([$edge], []), 'rel|`v2`<b>x</b>', 'scope');

        self::assertStringContainsString('``rel|`v2`<b>x</b>``', $markdown);
        $row = array_values(array_filter(explode("\n", $markdown), static fn (string $l): bool => str_starts_with($l, '| + |')))[0];
        self::assertSame(5, substr_count(str_replace('\\|', '', $row), '|'), 'a pipe in a route split the table row: ' . $row);
        self::assertStringContainsString('GET /orders\\|x/`id`/<b>bold</b>/{id:\\d+}', $row, 'only the method separator may become a space');
    }

    #[Test]
    public function a_file_already_unparseable_at_the_base_does_not_keep_the_gate_red(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Legacy/Broken.php', "<?php\nfinal class {\n");
        $repo->commit('a file that never parsed');

        $result = $this->diff($repo);

        $head = OrphanedRemovals::unreadableFiles($result['head']);
        self::assertCount(1, $head);
        self::assertCount(1, OrphanedRemovals::unreadableFiles($result['base']));
        // What the gate keys "already broken at the base" by: the same content there.
        self::assertSame([(string) hash_file('xxh3', $head[0]) => true], $result['base_unreadable_hashes']);
    }

    #[Test]
    public function the_gate_refuses_to_run_without_a_base(): void
    {
        // An empty CI variable fell through to the counts mode and exited 0 on a real orphan.
        foreach ([['--fail-on' => 'orphaned-removals'], ['--base' => '', '--fail-on' => 'orphaned-removals'], ['--path' => '.'], ['--format' => 'yaml']] as $options) {
            $tester = new CommandTester(new GraphDiffCommand());
            self::assertSame(1, $tester->execute($options), json_encode($options) . ' passed: ' . $tester->getDisplay());
        }

        $tester = new CommandTester(new GraphDiffCommand());
        self::assertSame(1, $tester->execute(['--fail-on' => 'orphaned-removals', '--format' => 'json']));
        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($decoded, 'a JSON caller got a text error');
        self::assertSame(['error'], array_keys($decoded), 'a refusal, not a counts payload');
        self::assertStringContainsString('--base', (string) $decoded['error']);
    }

    #[Test]
    public function a_handler_replaced_by_a_new_one_is_shown_not_folded(): void
    {
        // Round 2: a deleted handler plus an unrelated new one for the same
        // payload paired silently and reported "Unchanged: handlers".
        $repo = GitFixtureRepo::create();
        $repo->delete('Orders/PlaceOrderHandler.php');
        $repo->write('Orders/RefundOnlyHandler.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Orders;\nuse Semitexa\\Core\\Attribute\\AsPayloadHandler;\n#[AsPayloadHandler(payload: PlaceOrderPayload::class, resource: OrderResource::class)]\nfinal class RefundOnlyHandler\n{\n    public function handle(PlaceOrderPayload \$p): OrderResource { return new OrderResource(); }\n}\n");

        $moves = $this->diff($repo)['moves'];

        $handles = array_values(array_filter($moves->replaced, static fn (array $p): bool => $p['removed']->getType() === EdgeType::Handles));
        self::assertCount(1, $handles);
        self::assertSame(GraphFixture::classId('Orders\\RefundOnlyHandler'), $handles[0]['added']->getSourceId());
        $markdown = EdgeDiffMarkdown::render($moves->remaining, 'main', 'fixture', null, [], [], $moves, ['routes']);
        self::assertStringContainsString('**replaced** handles', $markdown);
    }

    #[Test]
    public function two_surviving_classes_swapping_wiring_stay_visible(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Mail/AuditLog.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Mail;\nfinal class AuditLog\n{\n    public function __invoke(): void {}\n}\n");
        $repo->commit('a plain class');
        $listener = $repo->read('Mail/SendReceiptListener.php');
        $repo->write('Mail/AuditLog.php', str_replace('SendReceiptListener', 'AuditLog', $listener));
        $repo->write('Mail/SendReceiptListener.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Mail;\nfinal class SendReceiptListener\n{\n    public function __invoke(): void {}\n}\n");

        $moves = $this->diff($repo)['moves'];

        self::assertSame([], $moves->replaced, 'both classes survive: a rewiring, not a replacement');
        self::assertContains('listens_to', array_map(static fn (Edge $e): string => $e->getType()->value, $moves->remaining->removed));
    }

    #[Test]
    public function a_path_the_base_does_not_have_is_an_empty_base(): void
    {
        $repo = GitFixtureRepo::create();
        $repo->write('Fresh/NewThing.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Fresh;\nfinal class NewThing extends \\" . self::NS . "Orders\\OrderPlaced {}\n");

        $result = $this->diff($repo, 'HEAD', 'Fresh');

        self::assertSame([], $result['diff']->removed);
        self::assertNotSame([], $result['diff']->added);
    }

    #[Test]
    public function a_class_naming_itself_still_pairs_after_a_rename(): void
    {
        $repo = GitFixtureRepo::create();
        $money = "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Orders;\nfinal class Money\n{\n    public function __construct(private int \$cents) {}\n    public static function zero(): Money { return new Money(0); }\n    public function plus(Money \$o): Money { return new Money(\$this->cents + 1); }\n}\n";
        $repo->write('Orders/Money.php', $money);
        $repo->commit('money');
        $repo->delete('Orders/Money.php');
        $repo->write('Orders/Amount.php', str_replace('Money', 'Amount', $money));

        $moves = $this->diff($repo)['moves'];

        self::assertSame([GraphFixture::classId('Orders\\Amount')], array_column($moves->movedNodes, 'to'));
    }

    #[Test]
    public function two_empty_classes_are_not_paired_by_their_hash(): void
    {
        $repo = GitFixtureRepo::create();
        foreach (['Alpha', 'Beta'] as $name) {
            $repo->write("Orders/$name.php", "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Orders;\nfinal class $name {}\n");
        }
        $repo->commit('two empty classes');
        $repo->delete('Orders/Alpha.php');
        $repo->delete('Orders/Beta.php');
        $repo->write('Orders/Gamma.php', "<?php\nnamespace " . rtrim(self::NS, '\\') . "\\Orders;\nfinal class Gamma {}\n");

        self::assertSame([], $this->diff($repo)['moves']->movedNodes);
    }

    #[Test]
    public function a_plain_move_raises_no_rewiring_warning(): void
    {
        $repo = GitFixtureRepo::create();
        $source = $repo->read('Orders/SqlOrderRepository.php');
        $repo->delete('Orders/SqlOrderRepository.php');
        $repo->write('Storage/SqlOrderRepository.php', str_replace(
            ['namespace ' . rtrim(self::NS, '\\') . '\\Orders;', 'implements OrderRepository'],
            ['namespace ' . rtrim(self::NS, '\\') . "\\Storage;\nuse " . self::NS . 'Orders\\OrderRepository;', 'implements OrderRepository'],
            $source,
        ));

        $moves = $this->diff($repo)['moves'];

        self::assertCount(1, $moves->movedNodes);
        self::assertSame([], $moves->farEndChanged, 'domain or doc edges are not a rewiring');
    }
}
