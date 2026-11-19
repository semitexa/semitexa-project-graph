<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphFindingsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `ai:review-graph:findings --kind=ui` for a reader: "No findings." over a
 * graph that could not read everything is not a clean result, so the text
 * and the markdown say what was left unread, as the other kinds do
 * (project-graph#38 review).
 */
final class UiFlowFindingsOutputTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'text' => ['text'];
        yield 'markdown' => ['markdown'];
    }

    #[Test]
    #[DataProvider('formats')]
    public function no_findings_still_says_what_the_graph_could_not_read(string $format): void
    {
        $report = [
            'findings' => [],
            'accepted' => [],
            'summary' => ['pages' => 2, 'ways' => 3, 'by_confidence' => ['exact' => 3, 'pattern' => 0, 'unresolved' => 0], 'post_payloads' => 0, 'reached' => 0, 'allowed' => [], 'by_rule' => [], 'test_code_skipped' => 0],
        ];
        $coverage = ['complete' => false, 'gaps' => ['parse_error' => 2], 'unresolved_references' => 0, 'exclusions' => []];

        $output = new BufferedOutput();
        $uiFlow = new \ReflectionMethod(ReviewGraphFindingsCommand::class, 'uiFlow');
        $uiFlow->invoke(new ReviewGraphFindingsCommand(), $output, new SymfonyStyle(new ArrayInput([]), $output), $format, $report, $coverage);
        $text = $output->fetch();

        self::assertStringContainsString('No findings.', $text);
        self::assertStringContainsString('Coverage: 2 parse error.', $text);
    }
}
