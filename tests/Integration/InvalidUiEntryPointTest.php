<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\UiFlowGapReport;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\UiFlowProject;

/**
 * Declarations of #[UiEntryPoint] built to be refused. They live in a file
 * named Invalid* so the reference generator never offers one as the example
 * (review, docs#166).
 */
final class InvalidUiEntryPointTest extends TestCase
{
    use ExtractorCampaign;

    /**
     * @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private static function ofRule(array $findings, string $rule): array
    {
        return array_values(array_filter($findings, static fn (array $finding): bool => $finding['rule'] === $rule));
    }

    /** The attribute is an answer only with its reason: bare or empty, the payload is still a finding. */
    #[Test]
    public function a_ui_entry_point_without_a_reason_accepts_nothing(): void
    {
        $bare = <<<'PHP'
            <?php
            namespace Campaign\Shop;

            use Semitexa\Core\Attribute\AsPublicPayload;
            use Semitexa\ProjectGraph\Attribute\UiEntryPoint;

            #[AsPublicPayload(path: '/orders/bare', methods: ['POST'])]
            #[UiEntryPoint]
            final class BareEntryPayload {}

            #[AsPublicPayload(path: '/orders/blank', methods: ['POST'])]
            #[UiEntryPoint(reason: '  ')]
            final class BlankEntryPayload {}
            PHP;
        $fixture = self::graphOf(UiFlowProject::withPosts() + ['src/modules/Shop/Application/Bare.php' => $bare]);
        $report = (new UiFlowGapReport())->collect($fixture->storage);

        $orphans = array_column(self::ofRule($report['findings'], UiFlowGapReport::ORPHAN_POST), 'subject');
        sort($orphans);
        self::assertSame(['POST /orders/archive', 'POST /orders/bare', 'POST /orders/blank'], $orphans);
        self::assertSame(['POST /orders/import'], array_column($report['accepted'], 'subject'));
    }
}
