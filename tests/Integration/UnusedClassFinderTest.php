<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

final class UnusedClassFinderTest extends TestCase
{
    /** @return array<string, array{confidence: string, evidence: string}> by short FQCN (relative to the fixture namespace, or as-is) */
    private static function graded(GraphFixture $fixture): array
    {
        $out = [];
        foreach ((new UnusedClassFinder())->find($fixture->storage) as $f) {
            $out[str_replace(GraphFixture::NS, '', $f['fqcn'])] = ['confidence' => $f['confidence'], 'evidence' => $f['evidence']];
        }
        ksort($out);

        return $out;
    }

    #[Test]
    public function the_fixture_is_graded_as_expected(): void
    {
        $graded = self::graded(GraphFixture::built());

        // Nothing refers to them and nothing discovers them.
        self::assertSame('high', $graded['Orphan\\NobodyUsesMe']['confidence']);
        self::assertSame('high', $graded['Orders\\SqlOrderRepository']['confidence'], 'implements an interface, but nothing wires or builds it');
        // Discovered by the framework, referenced by no code.
        self::assertSame('medium', $graded['Orders\\PlaceOrderHandler']['confidence']);
        self::assertStringContainsString('handler', $graded['Orders\\PlaceOrderHandler']['evidence']);
        self::assertSame('medium', $graded['Mail\\SendReceiptListener']['confidence']);
        // Used: injected, handled, produced, listened to.
        foreach (['Orders\\OrderRepository', 'Orders\\PlaceOrderPayload', 'Orders\\OrderResource', 'Orders\\OrderPlaced'] as $used) {
            self::assertArrayNotHasKey($used, $graded, $used . ' is used');
        }
    }

    #[Test]
    public function a_class_named_only_as_a_string_is_low(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('Orders/Registry.php', "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orders;\nfinal class Registry\n{\n    public const JOBS = [\\Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orphan\\NobodyUsesMe::class];\n}\n");
        $fixture->refresh();

        $graded = self::graded($fixture);

        self::assertSame('low', $graded['Orphan\\NobodyUsesMe']['confidence']);
        self::assertStringContainsString('Foo::class', $graded['Orphan\\NobodyUsesMe']['evidence']);
    }

    #[Test]
    public function a_class_only_tests_use_is_medium(): void
    {
        $fixture = GraphFixture::built();
        $fixture->write('tests/NobodyUsesMeTest.php', "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Tests;\nfinal class NobodyUsesMeTest\n{\n    public function test(): void\n    {\n        new \\Semitexa\\ProjectGraph\\Tests\\Fixture\\GraphProject\\Orphan\\NobodyUsesMe();\n    }\n}\n");
        $fixture->refresh();

        $graded = self::graded($fixture);

        self::assertSame('medium', $graded['Orphan\\NobodyUsesMe']['confidence']);
        self::assertStringContainsString('only by tests', $graded['Orphan\\NobodyUsesMe']['evidence']);
        self::assertArrayNotHasKey('Tests\\NobodyUsesMeTest', $graded, 'test code is never a candidate');
    }

    #[Test]
    public function a_runtime_class_name_in_the_module_lowers_confidence(): void
    {
        $fixture = GraphFixture::create();
        $fixture->write('packages/semitexa-shop/src/Catalog.php', "<?php\nnamespace Semitexa\\Shop;\nfinal class Catalog\n{\n}\n");
        $fixture->write('packages/semitexa-shop/src/Loader.php', "<?php\nnamespace Semitexa\\Shop;\nfinal class Loader\n{\n    public function make(string \$c): object\n    {\n        return new \$c();\n    }\n}\n");
        $fixture->write('packages/semitexa-quiet/src/Quiet.php', "<?php\nnamespace Semitexa\\Quiet;\nfinal class Quiet\n{\n}\n");
        $fixture->build();

        $graded = self::graded($fixture);

        self::assertSame('low', $graded['Semitexa\\Shop\\Catalog']['confidence']);
        self::assertStringContainsString('at runtime', $graded['Semitexa\\Shop\\Catalog']['evidence']);
        self::assertSame('high', $graded['Semitexa\\Quiet\\Quiet']['confidence']);
    }
}
