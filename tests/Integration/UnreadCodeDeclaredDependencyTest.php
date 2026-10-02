<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * How far unread code reaches was built only from edges the graph read. A
 * module whose only link to another is inside the unread code itself — a
 * runtime class name, an ignored class — reached nothing, and the class it
 * uses was graded HIGH with absence_is_proof. A package's composer.json
 * `require` is a dependency the graph does not have to read code to see.
 */
final class UnreadCodeDeclaredDependencyTest extends TestCase
{
    /** @param array<string, string> $php relative path => body after the opening tag */
    private static function graphOf(array $php): GraphFixture
    {
        $fixture = GraphFixture::create();
        $fixture->write('packages/alpha/composer.json', '{"name": "fx/alpha", "require": {"php": ">=8.4"}}');
        $fixture->write('packages/beta/composer.json', '{"name": "fx/beta", "require": {"php": ">=8.4", "fx/alpha": "*"}}');
        $fixture->write('packages/gamma/composer.json', '{"name": "fx/gamma", "require-dev": {"fx/alpha": "*"}}');
        foreach ($php as $path => $body) {
            $fixture->write($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
        }
        $fixture->build();

        return $fixture;
    }

    /** @return array<string, array{confidence: string, evidence: string}> by FQCN */
    private static function graded(GraphFixture $fixture): array
    {
        $out = [];
        foreach ((new UnusedClassFinder())->find($fixture->storage) as $f) {
            $out[$f['fqcn']] = ['confidence' => $f['confidence'], 'evidence' => $f['evidence']];
        }

        return $out;
    }

    private static function proof(GraphFixture $fixture, string $fqcn): bool
    {
        return (new CoverageReport($fixture->storage))->forNodes([NodeId::forClass($fqcn)])['absence_is_proof'];
    }

    #[Test]
    public function a_runtime_name_in_a_package_that_requires_another_reaches_it_without_an_edge(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Str/ByString.php' => "namespace Fx\\Alpha\\Str;\nfinal class ByString {}",
            // No type, no `new`, no `use` of anything in Alpha: only the runtime name.
            'packages/beta/src/Loader.php' => "namespace Fx\\Beta;\n#[\\Semitexa\\Core\\Attribute\\AsService]\nfinal class Loader\n{\n    public function load(): object\n    {\n        \$class = 'Fx\\\\Alpha\\\\Str\\\\ByString';\n        return new \$class();\n    }\n}",
            'packages/gamma/src/Quiet.php' => "namespace Fx\\Gamma;\nfinal class Quiet {}",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('medium', $graded['Fx\\Alpha\\Str\\ByString']['confidence'], $graded['Fx\\Alpha\\Str\\ByString']['evidence']);
        self::assertStringContainsString('Beta', $graded['Fx\\Alpha\\Str\\ByString']['evidence']);
        self::assertFalse(self::proof($fixture, 'Fx\\Alpha\\Str\\ByString'));
        // Gamma requires Alpha for development only, and builds no names: still proof.
        self::assertSame('high', $graded['Fx\\Gamma\\Quiet']['confidence']);
        self::assertTrue(self::proof($fixture, 'Fx\\Gamma\\Quiet'));
    }

    #[Test]
    public function an_ignored_class_alone_in_its_package_counts_in_that_package(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Ign/Helped.php' => "namespace Fx\\Alpha\\Ign;\nfinal class Helped {}",
            'packages/beta/src/Ign/Ignored.php' => "namespace Fx\\Beta\\Ign;\n#[\\Semitexa\\ProjectGraph\\Attribute\\GraphIgnore('fixture: deliberately ignored')]\nfinal class Ignored { public function run(): \\Fx\\Alpha\\Ign\\Helped { return new \\Fx\\Alpha\\Ign\\Helped(); } }",
            'packages/gamma/src/Quiet.php' => "namespace Fx\\Gamma;\nfinal class Quiet {}",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('medium', $graded['Fx\\Alpha\\Ign\\Helped']['confidence'], $graded['Fx\\Alpha\\Ign\\Helped']['evidence']);
        self::assertStringContainsString('GraphIgnore', $graded['Fx\\Alpha\\Ign\\Helped']['evidence']);
        self::assertStringContainsString('Beta', $graded['Fx\\Alpha\\Ign\\Helped']['evidence']);
        self::assertFalse(self::proof($fixture, 'Fx\\Alpha\\Ign\\Helped'));
        self::assertSame('high', $graded['Fx\\Gamma\\Quiet']['confidence']);
    }
}
