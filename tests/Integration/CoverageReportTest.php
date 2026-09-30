<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * An absence answer is proof only when nothing that could hide the missing
 * edge was left unread — and the report says which gaps those are.
 */
final class CoverageReportTest extends TestCase
{
    private function fixtureWithModules(): GraphFixture
    {
        $fixture = GraphFixture::create();
        $fixture->write('packages/semitexa-shop/src/Catalog.php', "<?php\nnamespace Semitexa\\Shop;\nfinal class Catalog\n{\n}\n");
        $fixture->write('packages/semitexa-shop/src/Loader.php', "<?php\nnamespace Semitexa\\Shop;\nfinal class Loader\n{\n    public function make(string \$c): object\n    {\n        return new \$c();\n    }\n}\n");
        $fixture->write('packages/semitexa-shop/src/Checkout.php', "<?php\nnamespace Semitexa\\Shop;\nfinal class Checkout\n{\n    public function go(): Missing\n    {\n        return new \\Psr\\Log\\NullLogger();\n    }\n}\n");
        $fixture->write('packages/semitexa-mail/src/Mailer.php', "<?php\nnamespace Semitexa\\Mail;\nfinal class Mailer\n{\n    public function make(string \$c): object\n    {\n        return new \$c();\n    }\n}\n");
        $fixture->write('packages/semitexa-quiet/src/Quiet.php', "<?php\nnamespace Semitexa\\Quiet;\nfinal class Quiet\n{\n}\n");
        $fixture->build();

        return $fixture;
    }

    #[Test]
    public function the_summary_counts_every_gap_and_is_not_complete(): void
    {
        $summary = (new CoverageReport($this->fixtureWithModules()->storage))->summary();

        self::assertFalse($summary['complete']);
        self::assertSame(['dynamic_reference' => 3], $summary['gaps']);
        self::assertSame(1, $summary['unresolved_references'], 'Semitexa\\Shop\\Missing; the vendor NullLogger is not one');
    }

    #[Test]
    public function a_runtime_class_name_in_the_same_module_makes_absence_unproven(): void
    {
        $report = (new CoverageReport($this->fixtureWithModules()->storage))->forNodes([NodeId::forClass('Semitexa\\Shop\\Catalog')]);

        self::assertFalse($report['absence_is_proof']);
        $subjects = array_column($report['relevant'], 'subject');
        self::assertContains('Semitexa\\Shop\\Loader', $subjects);
        self::assertContains('Semitexa\\Shop\\Missing', $subjects, 'an unresolved reference in the same namespace');
        self::assertNotContains('Semitexa\\Mail\\Mailer', $subjects, 'another module\'s runtime class names are not counted');
    }

    #[Test]
    public function with_nothing_relevant_unread_absence_is_proof(): void
    {
        $report = (new CoverageReport($this->fixtureWithModules()->storage))->forNodes([NodeId::forClass('Semitexa\\Quiet\\Quiet')]);

        self::assertTrue($report['absence_is_proof']);
        self::assertSame(0, $report['relevant_total']);
        self::assertFalse($report['complete'], 'the graph as a whole still has gaps');
    }

    #[Test]
    public function a_parse_error_anywhere_could_hide_anything(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $fixture->write('packages/semitexa-quiet/src/Quiet.php', "<?php\nnamespace Semitexa\\Quiet;\nfinal class Quiet\n{\n}\n");
        $fixture->build();

        $report = (new CoverageReport($fixture->storage))->forNodes([NodeId::forClass('Semitexa\\Quiet\\Quiet')]);

        self::assertFalse($report['absence_is_proof']);
        self::assertContains('parse_error', array_column($report['relevant'], 'kind'));
    }

    #[Test]
    public function the_description_names_the_gaps_that_matter_here(): void
    {
        $report = (new CoverageReport($this->fixtureWithModules()->storage))->forNodes([NodeId::forClass('Semitexa\\Shop\\Catalog')]);
        $text = implode("\n", CoverageReport::describe($report));

        self::assertStringContainsString('3 dynamic reference', $text);
        self::assertStringContainsString('could hide an edge here', $text);
        self::assertStringContainsString('Loader.php:7', $text);
    }
}
