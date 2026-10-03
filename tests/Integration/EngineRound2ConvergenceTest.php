<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * Refresh == full build, for the edits the round-2 fuzzer (2026-10-02, 487
 * seeded sequences) found breaking it. Each project gets a Composer loader
 * for its own namespace, so a constant holder can be found the way the
 * engine finds it in a real project.
 */
final class EngineRound2ConvergenceTest extends TestCase
{
    private const NS = 'R2Engine';

    private ?ClassLoader $loader = null;

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        $this->loader = null;
    }

    /** @return array<string, string> */
    private static function project(): array
    {
        $ns = self::NS;
        $payload = static fn (string $class, string $path, string $dir = 'Api'): string => "<?php\nnamespace {$ns}\\{$dir};\n\nuse {$ns}\\Routes;\nuse {$ns}\\Holder;\n\n"
            . "#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: {$path}, methods: ['GET'])]\nfinal class {$class}\n{\n}\n";

        return [
            'src/Routes.php' => "<?php\nnamespace {$ns};\n\nfinal class Routes\n{\n    public const LIST = '/list';\n    public const ORDERS = '/orders';\n}\n",
            'src/Holder.php' => "<?php\nnamespace {$ns};\n\nfinal class Holder\n{\n    public const K = Routes::ORDERS;\n}\n",
            'src/Api/ListPayload.php' => $payload('ListPayload', 'Routes::LIST'),
            'src/Api/OrdersPayload.php' => $payload('OrdersPayload', 'Holder::K'),
            'src/Api/SameA.php' => $payload('SameA', "'/same'"),
            'src/Api/SameB.php' => $payload('SameB', "'/same'"),
            'src/modules/Billing/src/Invoice.php' => "<?php\nnamespace {$ns}\\Billing;\n\nfinal class Invoice\n{\n}\n",
            'src/modules/Billing/src/Refund.php' => "<?php\nnamespace {$ns}\\Billing;\n\nfinal class Refund\n{\n    public function of(Invoice \$i): void {}\n}\n",
            'src/Dup/A.php' => "<?php\nnamespace {$ns}\\Dup;\n\n#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: '/one', methods: ['GET'])]\nfinal class Twin\n{\n}\n",
            'src/Dup/B.php' => "<?php\nnamespace {$ns}\\Dup;\n\n#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: '/two', methods: ['GET'])]\nfinal class Twin\n{\n}\n",
            // The resource role comes from the handler's attribute alone (handle() returns nothing).
            'src/Orders/PlaceOrderPayload.php' => $payload('PlaceOrderPayload', "'/place'", 'Orders'),
            'src/Orders/OrderResource.php' => "<?php\nnamespace {$ns}\\Orders;\n\nfinal class OrderResource\n{\n}\n",
            'src/Orders/PlaceOrderHandler.php' => "<?php\nnamespace {$ns}\\Orders;\n\n#[\\Semitexa\\Core\\Attribute\\AsPayloadHandler(payload: PlaceOrderPayload::class, resource: OrderResource::class)]\nfinal class PlaceOrderHandler\n{\n    public function handle(PlaceOrderPayload \$payload): void\n    {\n    }\n}\n",
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function edits(): iterable
    {
        yield 'the holder of a constant stops declaring it' => ['the holder of a constant stops declaring it'];
        yield 'the holder of a constant breaks' => ['the holder of a constant breaks'];
        yield 'a constant two hops away changes' => ['a constant two hops away changes'];
        yield 'one of two payloads serving a route is deleted' => ['one of two payloads serving a route is deleted'];
        yield 'the other one is deleted' => ['the other one is deleted'];
        yield 'a file of a local module is deleted' => ['a file of a local module is deleted'];
        yield 'the owner of a twice-declared payload is deleted' => ['the owner of a twice-declared payload is deleted'];
        yield 'a handler stops naming its resource' => ['a handler stops naming its resource'];
    }

    /** By name: a separate process cannot be handed a closure. */
    private static function apply(string $edit, GraphFixture $fixture): void
    {
        match ($edit) {
            'the holder of a constant stops declaring it' => (static function (GraphFixture $f): void {
            $f->write('src/Routes.php', "<?php\nnamespace " . self::NS . ";\n\nfinal class Routes\n{\n    public const ORDERS = '/orders';\n}\n");
            })($fixture),
            'the holder of a constant breaks' => (static function (GraphFixture $f): void {
            $f->write('src/Routes.php', "<?php\nnamespace " . self::NS . ";\n\nfinal class Routes {\n");
            })($fixture),
            'a constant two hops away changes' => (static function (GraphFixture $f): void {
            $f->write('src/Routes.php', str_replace("'/orders'", "'/changed'", $f->read('src/Routes.php')));
            })($fixture),
            'one of two payloads serving a route is deleted' => (static function (GraphFixture $f): void {
            $f->delete('src/Api/SameA.php');
            })($fixture),
            'the other one is deleted' => (static function (GraphFixture $f): void {
            $f->delete('src/Api/SameB.php');
            })($fixture),
            'a file of a local module is deleted' => (static function (GraphFixture $f): void {
            $f->delete('src/modules/Billing/src/Invoice.php');
            })($fixture),
            'the owner of a twice-declared payload is deleted' => (static function (GraphFixture $f): void {
            $f->delete('src/Dup/A.php');
            })($fixture),
            'the owner of a twice-declared payload moves' => (static function (GraphFixture $f): void {
            mkdir($f->path('config'));
            rename($f->path('src/Dup/A.php'), $f->path('config/Twin.php'));
            })($fixture),
            'a handler stops naming its resource' => (static function (GraphFixture $f): void {
            $f->write('src/Orders/PlaceOrderHandler.php', str_replace(', resource: OrderResource::class', '', $f->read('src/Orders/PlaceOrderHandler.php')));
            })($fixture),
        };
    }

    #[Test]
    #[DataProvider('edits')]
    #[RunInSeparateProcess]
    public function a_refresh_converges_to_a_full_build(string $edit): void
    {
        $refreshed = $this->built();
        $before = [$refreshed->nodeLines(), $refreshed->edgeLines()];
        self::apply($edit, $refreshed);
        $refreshed->refresh();
        $refreshedNodes = $refreshed->nodeLines();
        $refreshedEdges = $refreshed->edgeLines();
        // An edit of a file the project does not have wrote a new, empty one
        // and compared two unchanged graphs.
        self::assertNotSame($before, [$refreshedNodes, $refreshedEdges], 'the edit changed nothing, so it tested nothing');

        $rebuilt = GraphFixture::create();
        $this->write($rebuilt);
        self::apply($edit, $rebuilt);
        $this->point($rebuilt);
        $rebuilt->build();

        self::assertSame($rebuilt->nodeLines(), $refreshedNodes, 'nodes differ from a full build');
        self::assertSame($rebuilt->edgeLines(), $refreshedEdges, 'edges differ from a full build');
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_duplicate_gap_names_the_file_that_holds_the_class_now(): void
    {
        // Which of two declarations wins depends on the order files are met,
        // in a refresh as in a full build; that is by design. What must hold:
        // the class stays declared and the gap names a file that exists
        // (it named the deleted path the owner moved from, round 2).
        $fixture = $this->built();
        self::apply('the owner of a twice-declared payload moves', $fixture);
        $fixture->refresh();

        self::assertContains('class:R2Engine\\Dup\\Twin payload ' . self::holder($fixture), $fixture->nodeLines());
        $gaps = $fixture->storage->gaps->findAll(\Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind::DuplicateClass);
        self::assertCount(1, $gaps, 'the other copy still records the duplicate');
        foreach ($gaps as $gap) {
            $named = substr($gap->getDetail(), strlen('The graph already holds this class from '));
            self::assertFileExists($named, $gap->getDetail());
        }
    }

    private static function holder(GraphFixture $fixture): string
    {
        $node = $fixture->storage->nodes->findById('class:R2Engine\\Dup\\Twin');

        return str_replace($fixture->root . '/', '', (string) $node?->getFile());
    }

    #[Test]
    #[RunInSeparateProcess]
    public function moving_a_file_changes_nothing_and_says_so(): void
    {
        $fixture = $this->built();
        mkdir($fixture->path('src/Moved'));
        rename($fixture->path('src/modules/Billing/src/Refund.php'), $fixture->path('src/Moved/Refund.php'));

        $result = $fixture->refresh();

        // A move reported +1/-1 node when nothing was added or removed.
        self::assertSame([0, 0], [$result->nodesAdded, $result->nodesRemoved]);
    }

    private function built(): GraphFixture
    {
        $fixture = GraphFixture::create();
        $this->write($fixture);
        $this->point($fixture);
        $fixture->build();

        return $fixture;
    }

    private function write(GraphFixture $fixture): void
    {
        foreach (self::project() as $path => $contents) {
            $fixture->write($path, $contents);
        }
    }

    /** One loader at a time, pointed at the fixture being built. */
    private function point(GraphFixture $fixture): void
    {
        $this->loader?->unregister();
        $this->loader = new ClassLoader();
        $this->loader->addPsr4(self::NS . '\\', $fixture->path('src') . '/');
        $this->loader->register();
    }
}
