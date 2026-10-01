<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\CycleFinder;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

final class CycleFinderTest extends TestCase
{
    #[Test]
    public function the_planted_two_class_loop_is_exactly_one_row(): void
    {
        $cycles = (new CycleFinder())->find(GraphFixture::built()->storage);

        $ping = GraphFixture::classId('Cycle\\Ping');
        $pong = GraphFixture::classId('Cycle\\Pong');
        self::assertSame([['members' => [$ping, $pong], 'cycle' => [$ping, $pong, $ping]]], $cycles);
    }

    #[Test]
    public function a_longer_loop_is_one_row_with_its_shortest_cycle(): void
    {
        $fixture = GraphFixture::built();
        $ns = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Ring';
        foreach (['A' => 'B', 'B' => 'C', 'C' => 'A'] as $from => $to) {
            $fixture->write("Ring/{$from}.php", "<?php\nnamespace {$ns};\nfinal class {$from}\n{\n    public function next(): {$to}\n    {\n        return new {$to}();\n    }\n}\n");
        }
        $fixture->refresh();

        $rings = array_values(array_filter(
            (new CycleFinder())->find($fixture->storage),
            static fn (array $c): bool => count($c['members']) === 3,
        ));

        self::assertCount(1, $rings);
        self::assertSame(["class:{$ns}\\A", "class:{$ns}\\B", "class:{$ns}\\C", "class:{$ns}\\A"], $rings[0]['cycle']);
    }

    #[Test]
    public function imports_alone_do_not_make_a_loop(): void
    {
        $fixture = GraphFixture::built();
        $ns = 'Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Imports';
        $fixture->write('Imports/Left.php', "<?php\nnamespace {$ns};\nuse {$ns}\\Right;\nfinal class Left\n{\n}\n");
        $fixture->write('Imports/Right.php', "<?php\nnamespace {$ns};\nuse {$ns}\\Left;\nfinal class Right\n{\n}\n");
        $fixture->refresh();

        $cycles = (new CycleFinder())->find($fixture->storage);
        self::assertSame(
            [[GraphFixture::classId('Cycle\\Ping'), GraphFixture::classId('Cycle\\Pong')]],
            array_column($cycles, 'members'),
            'precondition: the planted Ping/Pong loop is still found',
        );
        foreach ($cycles as $cycle) {
            self::assertNotContains("class:{$ns}\\Left", $cycle['members']);
        }
        self::assertTrue($fixture->hasEdge(\Semitexa\ProjectGraph\Application\Service\Graph\EdgeType::Imports, "class:{$ns}\\Left", "class:{$ns}\\Right"), 'precondition: the imports exist');
    }
}
