<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Findings\UiFlowGapReport;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;

/**
 * The UI flow warnings (tk-ui-flow-pr1-projection): each rule fires once on
 * the gap planted for it, and says nothing where nobody expects a form.
 */
final class UiFlowGapReportTest extends TestCase
{
    use ExtractorCampaign;

    /**
     * @template T of array<string, mixed>
     * @param list<T> $findings
     * @return list<T>
     */
    private static function ofRule(array $findings, string $rule): array
    {
        return array_values(array_filter($findings, static fn (array $finding): bool => $finding['rule'] === $rule));
    }

    #[Test]
    public function a_literal_link_to_no_route_is_one_finding_per_template_and_target(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts());
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        // The layout writes /nowhere twice and two pages render it: one place, one finding.
        $missing = self::ofRule($report['findings'], UiFlowGapReport::FORM_TO_MISSING_ROUTE);
        self::assertCount(1, $missing);
        self::assertSame(
            ['ui_flow', UiFlowProject::TEMPLATES . 'layout.html.twig:5', 'Shop', 'GET /nowhere', [5, 6]],
            [$missing[0]['kind'], $missing[0]['at'], $missing[0]['module'], $missing[0]['subject'], $missing[0]['lines'] ?? null],
        );
        self::assertStringContainsString('no route answers GET /nowhere', $missing[0]['detail']);
        self::assertSame(1, $report['summary']['by_rule'][UiFlowGapReport::FORM_TO_MISSING_ROUTE]);
    }

    #[Test]
    public function a_file_a_computed_target_and_a_matched_path_are_not_missing_routes(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts());
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        $subjects = array_column(self::ofRule($report['findings'], UiFlowGapReport::FORM_TO_MISSING_ROUTE), 'subject');
        self::assertSame(['GET /nowhere'], $subjects, 'the .pdf, {{ row.href }}, /users/7 and /uk/orders are not reported');
        // …while each of them was read: 10 ways, 5 of them to a route.
        self::assertSame(10, $report['summary']['ways']);
        self::assertSame(['exact' => 3, 'pattern' => 2, 'unresolved' => 5], $report['summary']['by_confidence']);
    }

    #[Test]
    public function a_page_post_no_form_reaches_is_one_finding(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts());
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        $orphans = self::ofRule($report['findings'], UiFlowGapReport::ORPHAN_POST);
        self::assertCount(1, $orphans);
        self::assertSame(
            ['ui_flow', 'src/modules/Shop/Application/Posts.php:9', 'Shop', 'POST /orders/archive'],
            [$orphans[0]['kind'], $orphans[0]['at'], $orphans[0]['module'], $orphans[0]['subject']],
        );
        self::assertStringContainsString('ArchiveOrdersPayload', $orphans[0]['detail']);
        self::assertSame(1, $report['summary']['by_rule'][UiFlowGapReport::ORPHAN_POST]);
    }

    #[Test]
    public function a_post_a_form_reaches_a_webhook_a_json_payload_a_signed_url_and_an_api_stay_silent(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts());
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        // Eight POST payloads: two declaring the form's own route (the shop's
        // and the base fixture's PlaceOrderPayload), the planted orphan, the
        // accepted one, and four nobody expects a form for, each for its own reason.
        self::assertSame(8, $report['summary']['post_payloads']);
        self::assertSame(2, $report['summary']['reached'], 'POST /orders is the target of the orders page form, whoever declares it');
        self::assertSame(['api' => 1, 'json' => 1, 'service' => 1, 'signed-url' => 1], $report['summary']['allowed']);
        self::assertSame(['POST /orders/archive'], array_column(self::ofRule($report['findings'], UiFlowGapReport::ORPHAN_POST), 'subject'));
    }

    #[Test]
    public function a_ui_entry_point_is_accepted_with_its_reason_not_warned_about(): void
    {
        $fixture = self::graphOf(UiFlowProject::withPosts());
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        self::assertSame(
            [['POST /orders/import', UiFlowGapReport::ORPHAN_POST, 'Posted by the import script on the orders page.']],
            array_map(static fn (array $row): array => [$row['subject'], $row['rule'], $row['reason']], $report['accepted']),
        );
        // The fixture's planted orphan is still reported, so the findings list is
        // read and holds something: the accepted payload is absent from it.
        $subjects = array_column($report['findings'], 'subject');
        self::assertContains('POST /orders/archive', $subjects);
        self::assertNotContains('POST /orders/import', $subjects);
    }

    /**
     * A form post is as often answered with a redirect or an acknowledgement
     * as with a page, so the answer does not make a payload an API; and a
     * payload that takes a form's own encoding is a form target even when it
     * takes JSON too.
     */
    #[Test]
    public function a_form_encoding_wins_over_json_and_an_answer_that_is_no_page_is_still_a_form_target(): void
    {
        $more = <<<'PHP'
            <?php
            namespace Campaign\Shop;

            use Semitexa\Core\Attribute\AsPublicPayload;

            final class AckResource {}

            #[AsPublicPayload(path: '/orders/upload', methods: ['POST'], consumes: ['application/json', 'multipart/form-data'], responseWith: AckResource::class)]
            final class UploadOrdersPayload {}

            #[AsPublicPayload(path: '/orders/subscribe', methods: ['POST'], responseWith: AckResource::class)]
            final class SubscribePayload {}

            #[AsPublicPayload(path: '/api/orders/bulk', methods: ['POST'], responseWith: AckResource::class)]
            final class BulkApiPayload {}
            PHP;
        $fixture = self::graphOf(UiFlowProject::withPosts() + ['src/modules/Shop/Application/More.php' => $more]);
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        $orphans = array_column(self::ofRule($report['findings'], UiFlowGapReport::ORPHAN_POST), 'subject');
        sort($orphans);
        self::assertSame(['POST /orders/archive', 'POST /orders/subscribe', 'POST /orders/upload'], $orphans);
        self::assertSame(['api' => 2, 'json' => 1, 'service' => 1, 'signed-url' => 1], $report['summary']['allowed'], 'a path under /api/ is an API route');
    }

    #[Test]
    public function a_finding_keeps_its_id_in_another_directory_and_a_module_filter_keeps_only_its_own(): void
    {
        $blog = ['src/modules/Blog/Application/View/templates/post.html.twig' => '<a href="/blog/gone">Gone</a>' . "\n"];
        $elsewhere = self::graphOf(UiFlowProject::withPosts() + $blog);
        $fixture = self::graphOf(UiFlowProject::withPosts() + $blog);
        self::assertNotSame($elsewhere->root, $fixture->root);
        $first = (new UiFlowGapReport())->collect($elsewhere->storage);
        $second = (new UiFlowGapReport())->collect($fixture->storage, null, TestCode::of($fixture->storage));

        // Two copies of the project in two directories, as the container and
        // the host see one checkout: every finding of both rules keeps its id.
        $ids = array_column($second['findings'], 'id', 'subject');
        self::assertSame(array_column($first['findings'], 'id', 'subject'), $ids);
        self::assertSame(['GET /blog/gone', 'GET /nowhere', 'POST /orders/archive'], array_keys($ids));
        self::assertMatchesRegularExpression('/\Aui-orphan-post:[0-9a-f]{12}\z/', $ids['POST /orders/archive'] ?? '');
        self::assertMatchesRegularExpression('/\Aui-form-to-missing-route:[0-9a-f]{12}\z/', $ids['GET /nowhere'] ?? '');
        self::assertCount(3, array_unique($ids));

        $shop = (new UiFlowGapReport())->collect($fixture->storage, 'Shop');
        self::assertSame(['GET /nowhere', 'POST /orders/archive'], array_column($shop['findings'], 'subject'));
        $other = (new UiFlowGapReport())->collect($fixture->storage, 'Blog');
        self::assertSame([['GET /blog/gone'], 1, 0], [array_column($other['findings'], 'subject'), $other['summary']['ways'], $other['summary']['post_payloads']]);
    }
}
