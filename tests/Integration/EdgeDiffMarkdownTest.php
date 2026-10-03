<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeDiffMarkdown;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeSetDiff;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Tests\Support\GitFixtureRepo;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The pull-request comment, against a golden file. To accept a deliberate
 * change of format: SEMITEXA_UPDATE_GOLDEN=1 and re-run, then review the diff.
 */
final class EdgeDiffMarkdownTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../Fixture/Golden/edge-diff-comment.md';

    #[Test]
    public function the_comment_leads_with_wiring_and_folds_the_noise(): void
    {
        $repo = GitFixtureRepo::create();
        // Wiring: a route changes path, a listener goes away.
        $repo->write('Orders/PlaceOrderPayload.php', str_replace("path: '/orders'", "path: '/orders/place'", $repo->read('Orders/PlaceOrderPayload.php')));
        $repo->delete('Mail/SendReceiptListener.php');
        // Code: the handler takes one more argument.
        $repo->write('Orders/PlaceOrderHandler.php', str_replace(
            'public function handle(PlaceOrderPayload $payload): OrderResource',
            'public function handle(PlaceOrderPayload $payload, ?OrderPlaced $previous = null): OrderResource',
            $repo->read('Orders/PlaceOrderHandler.php'),
        ));

        $result = (new RefGraphDiff())->diff($repo->root, 'HEAD', GraphFixture::diffScratch());
        $markdown = EdgeDiffMarkdown::render($result['diff'], 'main', 'fixture', (new CoverageReport($result['head']))->summary());

        if (getenv('SEMITEXA_UPDATE_GOLDEN') === '1') {
            file_put_contents(self::GOLDEN, $markdown);
            // A rewrite is never a pass: the comparison below would hold for any output.
            self::fail('Golden file rewritten; review the diff and re-run without SEMITEXA_UPDATE_GOLDEN.');
        }
        self::assertStringEqualsFile(self::GOLDEN, $markdown);
    }

    #[Test]
    public function no_change_says_so(): void
    {
        self::assertSame(
            "### Graph diff against `main` — `.`\n\nNo structural change.\n",
            EdgeDiffMarkdown::render(new EdgeSetDiff([], []), 'main', '.'),
        );
    }
}
