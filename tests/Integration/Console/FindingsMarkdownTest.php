<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Console\Command\ReviewGraphFindingsCommand;
use Semitexa\ProjectGraph\Application\Service\Findings\FindingsReport;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `ai:review-graph:findings --format=markdown`: the Class column names the
 * class. It printed the finding id, first letter cut (`nused:1a2b…`).
 */
final class FindingsMarkdownTest extends TestCase
{
    #[Test]
    public function the_class_column_shows_the_class_short_name(): void
    {
        $fixture = GraphFixture::built();
        $report = (new FindingsReport())->collect($fixture->storage, 'unused', UnusedClassFinder::LOW);
        $orphan = array_values(array_filter($report['unused'], static fn (array $f): bool => str_ends_with($f['fqcn'], '\\NobodyUsesMe')));
        self::assertCount(1, $orphan, 'precondition: the fixture has its unused class');

        $output = new BufferedOutput();
        $markdown = new \ReflectionMethod(ReviewGraphFindingsCommand::class, 'markdown');
        $markdown->invoke(new ReviewGraphFindingsCommand(), $output, $orphan, [], $report['coverage']);
        $text = $output->fetch();

        self::assertStringContainsString('| `NobodyUsesMe` |', $text);
        self::assertStringNotContainsString('nused:', $text);
    }
}
