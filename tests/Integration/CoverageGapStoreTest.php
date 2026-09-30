<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The graph records what it could not see, per file, with the file's
 * lifecycle: written when the file is indexed, replaced when it is
 * re-indexed, gone when the file is.
 */
final class CoverageGapStoreTest extends TestCase
{
    #[Test]
    public function the_fixtures_only_gap_is_its_planted_dynamic_instantiation(): void
    {
        $fixture = GraphFixture::built();

        self::assertSame(['dynamic_reference' => 1], $fixture->storage->gaps->countByKind());
        $gap = $fixture->storage->gaps->findByFile($fixture->path('Dynamic/PluginLoader.php'))[0];
        self::assertSame(GraphFixture::NS . 'Dynamic\\PluginLoader', $gap->getSubject());
        self::assertSame(12, $gap->getLine());
    }

    #[Test]
    public function an_unparseable_file_is_a_parse_error_gap_and_is_not_reparsed_while_unchanged(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $fixture->build();

        $gaps = $fixture->storage->gaps->findByFile($fixture->path('Broken.php'));
        self::assertCount(1, $gaps);
        self::assertSame(CoverageGapKind::ParseError, $gaps[0]->getKind());
        self::assertSame(0, $fixture->refresh()->filesScanned, 'an unchanged broken file must not be re-read on every refresh');
    }

    #[Test]
    public function fixing_the_file_clears_its_gap_and_brings_its_class_in(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $fixture->build();

        $fixture->write('Broken.php', "<?php\n\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject;\n\nfinal class Broken\n{\n}\n");
        $fixture->refresh();

        self::assertSame([], $fixture->storage->gaps->findByFile($fixture->path('Broken.php')));
        self::assertTrue($fixture->storage->nodeExists(GraphFixture::classId('Broken')));
    }

    #[Test]
    public function a_file_that_stops_parsing_loses_what_it_declared_and_says_why(): void
    {
        $fixture = GraphFixture::built();
        $pong = GraphFixture::classId('Cycle\\Pong');

        $fixture->write('Cycle/Pong.php', "<?php\nfinal class Pong {\n");
        $fixture->refresh();

        $node = $fixture->storage->nodes->findById($pong);
        self::assertNotNull($node, 'Ping still references Pong, so it remains as a placeholder');
        self::assertTrue($node->getIsPlaceholder());
        self::assertSame(CoverageGapKind::ParseError, $fixture->storage->gaps->findByFile($fixture->path('Cycle/Pong.php'))[0]->getKind());
    }

    #[Test]
    public function an_extractor_failure_is_an_extraction_failed_gap_naming_the_extractor(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/AuditedOrderService.php', <<<'PHP'
            <?php

            namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Orders;

            use Semitexa\Core\Attribute\SatisfiesServiceContract;

            #[SatisfiesServiceContract(of: OrderRepository::class, factoryKey: NotLoadable\Kind::Audit)]
            final class AuditedOrderService
            {
            }
            PHP);
        $fixture->refresh();

        $gaps = $fixture->storage->gaps->findByFile($fixture->path('Orders/AuditedOrderService.php'));
        self::assertNotSame([], $gaps);
        self::assertSame(CoverageGapKind::ExtractionFailed, $gaps[0]->getKind());
        self::assertNotSame('', $gaps[0]->getSubject());
    }

    #[Test]
    public function a_second_declaration_of_a_class_is_a_duplicate_class_gap(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Copies/Ping.php', str_replace(
            'namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle;',
            "namespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle;\n\nuse Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Cycle\\Pong;",
            $fixture->read('Cycle/Ping.php'),
        ));
        $fixture->refresh();

        $gaps = $fixture->storage->gaps->findByFile($fixture->path('Copies/Ping.php'));
        self::assertCount(1, $gaps);
        self::assertSame(CoverageGapKind::DuplicateClass, $gaps[0]->getKind());
        self::assertSame(GraphFixture::NS . 'Cycle\\Ping', $gaps[0]->getSubject());
        self::assertStringContainsString('Cycle/Ping.php', $gaps[0]->getDetail());
    }

    #[Test]
    public function deleting_a_file_deletes_its_gaps(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $fixture->build();

        $fixture->delete('Broken.php');
        $fixture->refresh();

        self::assertSame([], $fixture->storage->gaps->findByFile($fixture->path('Broken.php')));
    }

    #[Test]
    public function gaps_are_counted_by_kind(): void
    {
        $fixture = GraphFixture::create(withBrokenFile: true);
        $fixture->build();

        self::assertSame(['dynamic_reference' => 1, 'parse_error' => 1], $fixture->storage->gaps->countByKind());
        self::assertContainsOnlyInstancesOf(CoverageGap::class, $fixture->storage->gaps->findAll(CoverageGapKind::ParseError));
    }
}
