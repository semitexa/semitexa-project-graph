<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;
use Semitexa\ProjectGraph\Tests\Support\UiFlowRead;

/**
 * A refresh re-reads only the files that changed, and which file a template
 * name stands for depends on files that did not: the partial, the module
 * that owns the namespace. Each case is an edit after which the refreshed
 * graph, and the UI flow read from it, must be what a full build of the same
 * tree gives (review of tk-ui-flow-pr1-projection, 2026-11-19: resolved at
 * extract time, all four kept the answer from before the edit).
 */
final class UiFlowRefreshConvergenceTest extends TestCase
{
    use ExtractorCampaign;

    private const BLOG_TEASER = 'src/modules/Blog/Application/View/templates/teaser.html.twig';

    /** @return iterable<string, array{array<string, string>, list<string>, \Closure(GraphFixture): void, \Closure(GraphFixture, UiFlowRead, UiFlowRead): void}> */
    public static function edits(): iterable
    {
        $orders = UiFlowProject::payload('OrdersPayload');
        $t = UiFlowProject::TEMPLATES;

        yield 'a resource first, its template created later' => [
            [],
            [$t . 'pages/orders.html.twig'],
            static function (GraphFixture $f) use ($t): void {
                $f->write($t . 'pages/orders.html.twig', UiFlowProject::pages()[$t . 'pages/orders.html.twig']);
            },
            static function (GraphFixture $f, UiFlowRead $before, UiFlowRead $after) use ($orders): void {
                // verify:accept-test-change an absence after an edit: the same case asserts a present entry, and the test then compares the whole refreshed read with a full build
                self::assertArrayNotHasKey($orders, $before->pages, 'no template, no page');
                self::assertContains(UiFlowProject::template($f, 'pages/orders.html.twig'), $after->pages[$orders] ?? []);
            },
        ];
        yield 'an include first, the partial added later' => [
            [],
            [$t . 'partials/footer.html.twig'],
            static function (GraphFixture $f) use ($t): void {
                $f->write($t . 'partials/footer.html.twig', UiFlowProject::pages()[$t . 'partials/footer.html.twig']);
            },
            static function (GraphFixture $f, UiFlowRead $before, UiFlowRead $after) use ($orders): void {
                self::assertContains('@Shop/partials/footer.html.twig', $before->unresolved);
                // verify:accept-test-change an absence after an edit: the same case asserts a present entry, and the test then compares the whole refreshed read with a full build
                self::assertNotContains('@Shop/partials/footer.html.twig', $after->unresolved, 'the stale unresolved entry is gone');
                self::assertContains(UiFlowProject::template($f, 'partials/footer.html.twig'), $after->pages[$orders] ?? []);
            },
        ];
        yield 'a partial deleted' => [
            [],
            [],
            static function (GraphFixture $f) use ($t): void {
                $f->delete($t . 'partials/footer.html.twig');
            },
            static function (GraphFixture $f, UiFlowRead $before, UiFlowRead $after) use ($orders): void {
                self::assertContains(UiFlowProject::template($f, 'partials/footer.html.twig'), $before->pages[$orders] ?? []);
                // verify:accept-test-change an absence after an edit: the same case asserts a present entry, and the test then compares the whole refreshed read with a full build
                // The page itself survives, with its own template: only the partial is gone.
                self::assertArrayHasKey($orders, $after->pages);
                self::assertContains(UiFlowProject::template($f, 'pages/orders.html.twig'), $after->pages[$orders]);
                self::assertNotContains(UiFlowProject::template($f, 'partials/footer.html.twig'), $after->pages[$orders]);
                self::assertContains('@Shop/partials/footer.html.twig', $after->unresolved);
            },
        ];
        yield 'a new module, already included from an existing template' => [
            [$t . 'card.html.twig' => '<a href="/orders">All orders</a>' . "\n" . "{% include '@Blog/teaser.html.twig' %}\n"],
            [],
            static function (GraphFixture $f): void {
                $f->write(self::BLOG_TEASER, '<a href="/users/7">Author</a>' . "\n");
            },
            static function (GraphFixture $f, UiFlowRead $before, UiFlowRead $after) use ($orders): void {
                self::assertContains('@Blog/teaser.html.twig', $before->unresolved);
                // verify:accept-test-change an absence after an edit: the same case asserts a present entry, and the test then compares the whole refreshed read with a full build
                self::assertNotContains('@Blog/teaser.html.twig', $after->unresolved);
                self::assertContains('file:' . $f->path(self::BLOG_TEASER), $after->pages[$orders] ?? []);
            },
        ];
    }

    /**
     * @param array<string, string> $changed files written differently at the start
     * @param list<string> $absent files not there at the start
     * @param \Closure(GraphFixture): void $edit
     * @param \Closure(GraphFixture, UiFlowRead, UiFlowRead): void $expect
     */
    #[Test]
    #[DataProvider('edits')]
    public function a_refresh_after_the_edit_leaves_what_a_full_build_would(array $changed, array $absent, \Closure $edit, \Closure $expect): void
    {
        $fixture = self::graphOf(array_diff_key($changed + UiFlowProject::pages(), array_flip($absent)));
        $before = UiFlowRead::of($fixture);

        $edit($fixture);
        $fixture->refresh();
        $refreshed = UiFlowRead::of($fixture);
        $expect($fixture, $before, $refreshed);
        $refreshedGraph = [$fixture->nodeLines(), $fixture->edgeLines()];

        $fixture->build();
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshedGraph, 'the refreshed graph is the full build');
        self::assertSame(UiFlowRead::of($fixture)->toArray(), $refreshed->toArray(), 'and so is the UI flow read from it');
    }
}
