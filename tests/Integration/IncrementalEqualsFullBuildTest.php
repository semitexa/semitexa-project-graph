<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * The invariant a refresh exists for: after any edit, refreshing must leave
 * the graph a full build of the same tree would. Each case is an edit the
 * 2026-10-02 campaign found breaking it.
 */
final class IncrementalEqualsFullBuildTest extends TestCase
{
    private const NS = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\';

    /** @return iterable<string, array{\Closure(GraphFixture): void}> */
    public static function edits(): iterable
    {
        yield 'a file moved to another directory' => [static function (GraphFixture $f): void {
            mkdir($f->path('Moved'));
            rename($f->path('Orders/SqlOrderRepository.php'), $f->path('Moved/SqlOrderRepository.php'));
        }];
        yield 'a directory renamed' => [static function (GraphFixture $f): void {
            rename($f->path('Orders'), $f->path('Ordering'));
        }];
        yield 'a class split out into a file scanned earlier' => [static function (GraphFixture $f): void {
            // prepare() put Extra in Ping.php; it moves to AHelper.php, which the scanner may read first.
            $f->write('Cycle/Ping.php', str_replace("\nfinal class Extra\n{\n}\n", '', $f->read('Cycle/Ping.php')));
            $f->write('Cycle/AHelper.php', "<?php\n\nnamespace " . self::NS . "Cycle;\n\nfinal class Extra\n{\n}\n");
        }];
        yield 'the owner of a twice-declared class deleted' => [static function (GraphFixture $f): void {
            // Set up in prepare(): Orphan/NobodyUsesMe.php declared again in Twin/Copy.php.
            $f->delete('Orphan/NobodyUsesMe.php');
        }];
        yield 'a smaller file takes over one class of a two-class file' => [static function (GraphFixture $f): void {
            // prepare() put Extra beside Ping in Cycle/Ping.php; AAA/ sorts first.
            // Round-3 fuzzing, seed 2132: the holder lost its OTHER class.
            $f->write('AAA/Extra.php', "<?php\n\nnamespace " . self::NS . "Cycle;\n\nfinal class Extra\n{\n}\n");
        }];
        yield 'a smaller payload starts serving a shared route' => [static function (GraphFixture $f): void {
            $f->write('AAA/AlsoPlaces.php', "<?php\n\nnamespace " . self::NS . "Orders;\n\n#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: '/orders', methods: ['POST'])]\nfinal class AlsoPlaces\n{\n}\n");
        }];
        yield 'an #[AsEvent] attribute removed' => [static function (GraphFixture $f): void {
            $f->write('Orders/OrderPlaced.php', preg_replace('/^#\[\\\\?[A-Za-z\\\\]*AsEvent[^\]]*\]\n/m', '', $f->read('Orders/OrderPlaced.php')) ?? '');
        }];
        yield 'a class deleted while still referenced, then re-added without its attribute' => [static function (GraphFixture $f): void {
            $source = $f->read('Orders/OrderPlaced.php');
            $f->delete('Orders/OrderPlaced.php');
            $f->refresh();
            $f->write('Orders/OrderPlaced.php', preg_replace('/^#\[\\\\?[A-Za-z\\\\]*AsEvent[^\]]*\]\n/m', '', $source) ?? '');
        }];
    }

    /** @param \Closure(GraphFixture): void $edit */
    #[Test]
    #[DataProvider('edits')]
    public function a_refresh_converges_to_a_full_build(\Closure $edit): void
    {
        $refreshed = GraphFixture::create();
        self::prepare($refreshed);
        $refreshed->build();
        $edit($refreshed);
        $refreshed->refresh();

        $rebuilt = GraphFixture::create();
        self::prepare($rebuilt);
        $edit($rebuilt);
        $rebuilt->build();

        self::assertSame($rebuilt->nodeLines(), $refreshed->nodeLines(), 'nodes differ from a full build');
        self::assertSame($rebuilt->edgeLines(), $refreshed->edgeLines(), 'edges differ from a full build');
        self::assertSame(self::gapLines($rebuilt), self::gapLines($refreshed), 'coverage gaps differ from a full build');
    }

    #[Test]
    public function a_smaller_path_takes_a_twice_declared_class_over_in_a_refresh_as_in_a_rebuild(): void
    {
        // Round-2 fuzzing: a refresh kept the old copy, a rebuild met the new
        // one first, and the graphs differed (seed 2101). The smaller path wins.
        $copy = static function (GraphFixture $f): void {
            $f->write('AAA/OrderPlaced.php', str_replace("#[AsEvent]\n", '', $f->read('Orders/OrderPlaced.php')));
        };
        $refreshed = GraphFixture::built();
        $copy($refreshed);
        $refreshed->refresh();

        $rebuilt = GraphFixture::create();
        $copy($rebuilt);
        $rebuilt->build();

        self::assertSame($rebuilt->nodeLines(), $refreshed->nodeLines());
        self::assertSame($rebuilt->edgeLines(), $refreshed->edgeLines());
        self::assertContains(GraphFixture::classId('Orders\\OrderPlaced') . ' class AAA/OrderPlaced.php', $refreshed->nodeLines());
    }

    #[Test]
    public function a_parse_error_records_the_line_php_names(): void
    {
        $fixture = GraphFixture::create();
        $fixture->write('Legacy/Broken.php', "<?php\n\nfinal class {\n");
        $fixture->build();

        $gaps = $fixture->storage->gaps->findAll(CoverageGapKind::ParseError);

        self::assertCount(1, $gaps);
        self::assertSame(3, $gaps[0]->getLine(), $gaps[0]->getDetail());
    }

    /** Every case starts from a tree where one class is declared twice and one file holds two classes. */
    private static function prepare(GraphFixture $fixture): void
    {
        $fixture->write('Twin/Copy.php', $fixture->read('Orphan/NobodyUsesMe.php'));
        $fixture->write('Cycle/Ping.php', $fixture->read('Cycle/Ping.php') . "\nfinal class Extra\n{\n}\n");
        $fixture->write('Cycle/Pong.php', str_replace('return new Ping();', 'new Extra(); return new Ping();', $fixture->read('Cycle/Pong.php')));
    }

    /** @return list<string> */
    private static function gapLines(GraphFixture $fixture): array
    {
        $lines = [];
        foreach ($fixture->storage->gaps->findAll() as $gap) {
            $lines[] = $gap->getKind()->value . ' ' . str_replace($fixture->root . '/', '', $gap->getFile()) . ' ' . $gap->getSubject()
                . ' ' . str_replace($fixture->root . '/', '', $gap->getDetail());
        }
        sort($lines);

        return $lines;
    }
}
