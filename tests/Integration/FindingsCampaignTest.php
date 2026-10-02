<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Findings\CycleFinder;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * Findings campaign (2026-10-02): the unused-class and cycle verdicts a
 * fixture hunt showed to be wrong, each scanned for real from small PHP
 * sources written into a fresh fixture copy.
 *
 * The sources use the Fx\ namespace on purpose — nothing can autoload them,
 * so every verdict rests on what the graph read from the files.
 */
final class FindingsCampaignTest extends TestCase
{
    /** @param array<string, string> $files relative path => PHP body after the opening tag */
    private static function graphOf(array $files): GraphFixture
    {
        $fixture = GraphFixture::create();
        foreach ($files as $path => $body) {
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

    /** F3: discovery is any class-level attribute the framework can look classes up by, not only one in an \Attribute\ namespace. */
    #[Test]
    public function an_attribute_outside_an_attribute_namespace_is_still_discovery(): void
    {
        $fixture = self::graphOf([
            // A Semitexa attribute the scan does not include (vendor in a consumer project): known only by name.
            'packages/alpha/src/Sitemap/SitemapOnly.php' => "namespace Fx\\Alpha\\Sitemap;\nuse Semitexa\\Ssr\\Application\\Service\\Seo\\Sitemap\\AsSitemapProvider;\n#[AsSitemapProvider(priority: 10)]\nfinal class SitemapOnly {}",
            // A project's own attribute, declared in the scanned tree and carrying #[Attribute].
            'packages/alpha/src/Plugin/AsPlugin.php' => "namespace Fx\\Alpha\\Plugin;\n#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal class AsPlugin {}",
            'packages/alpha/src/Plugin/Registry.php' => "namespace Fx\\Alpha\\Plugin;\nfinal class Registry { public const ATTRIBUTE = AsPlugin::class; public function n(): AsPlugin { return new AsPlugin(); } }",
            'packages/alpha/src/Plugin/MyPlugin.php' => "namespace Fx\\Alpha\\Plugin;\n#[AsPlugin]\nfinal class MyPlugin {}",
            // PHP's own attributes discover nothing.
            'packages/alpha/src/Plain/Dynamic.php' => "namespace Fx\\Alpha\\Plain;\n#[\\AllowDynamicProperties]\nfinal class Dynamic {}",
            // Neither does a third-party attribute the scan cannot see.
            'packages/alpha/src/Plain/Vendor.php' => "namespace Fx\\Alpha\\Plain;\n#[\\Acme\\Marker]\nfinal class Vendor {}",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('medium', $graded['Fx\\Alpha\\Sitemap\\SitemapOnly']['confidence'], $graded['Fx\\Alpha\\Sitemap\\SitemapOnly']['evidence']);
        self::assertStringContainsString('#[AsSitemapProvider]', $graded['Fx\\Alpha\\Sitemap\\SitemapOnly']['evidence']);
        self::assertSame('medium', $graded['Fx\\Alpha\\Plugin\\MyPlugin']['confidence'], $graded['Fx\\Alpha\\Plugin\\MyPlugin']['evidence']);
        self::assertSame('high', $graded['Fx\\Alpha\\Plain\\Dynamic']['confidence'], '#[AllowDynamicProperties] discovers nothing');
        self::assertSame('high', $graded['Fx\\Alpha\\Plain\\Vendor']['confidence'], 'a non-Semitexa attribute the scan cannot see is not discovery');
    }

    /** F4: a static call and a Foo::class from one source are one edge; it must read as a use whichever came last. */
    #[Test]
    public function a_static_call_next_to_a_class_name_is_a_use_whatever_the_order(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Clobber/Svc.php' => "namespace Fx\\Alpha\\Clobber;\nfinal class Svc { public static function make(): string { return 'x'; } }",
            'packages/alpha/src/Clobber/Svc2.php' => "namespace Fx\\Alpha\\Clobber;\nfinal class Svc2 { public static function make(): string { return 'x'; } }",
            'packages/alpha/src/Clobber/Built.php' => "namespace Fx\\Alpha\\Clobber;\nfinal class Built {}",
            'packages/alpha/src/Clobber/Caller.php' => "namespace Fx\\Alpha\\Clobber;\n#[\\Semitexa\\Core\\Attribute\\AsService]\nfinal class Caller\n{\n    public function run(): array\n    {\n        \$made = Svc::make();\n        return [\$made, Svc::class, Svc2::class, Svc2::make(), new Built(), Built::class];\n    }\n}",
        ]);

        $graded = self::graded($fixture);

        foreach (['Svc', 'Svc2', 'Built'] as $used) {
            self::assertArrayNotHasKey('Fx\\Alpha\\Clobber\\' . $used, $graded, $used . ' is called or built, not only named');
        }
    }

    /** F5: a class name built at runtime in a module that depends on another can name that module's classes. */
    #[Test]
    public function a_runtime_class_name_in_a_dependent_module_makes_absence_unproven(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Str/ByString.php' => "namespace Fx\\Alpha\\Str;\nfinal class ByString {}",
            'packages/alpha/src/Api.php' => "namespace Fx\\Alpha;\nfinal class Api {}",
            'packages/beta/src/Loader.php' => "namespace Fx\\Beta;\n#[\\Semitexa\\Core\\Attribute\\AsService]\nfinal class Loader\n{\n    public function load(): object\n    {\n        \$class = 'Fx\\\\Alpha\\\\Str\\\\ByString';\n        return new \$class();\n    }\n    public function api(): \\Fx\\Alpha\\Api { return new \\Fx\\Alpha\\Api(); }\n}",
            // Gamma depends on nothing that builds class names: absence there stays proof.
            'packages/gamma/src/Quiet.php' => "namespace Fx\\Gamma;\nfinal class Quiet {}",
            // Delta depends on Beta, but Beta's runtime names cannot reach a module that depends on it.
            'packages/delta/src/Downstream.php' => "namespace Fx\\Delta;\nfinal class Downstream { public function l(): \\Fx\\Beta\\Loader { return new \\Fx\\Beta\\Loader(); } }",
            'packages/delta/src/Lonely.php' => "namespace Fx\\Delta;\nfinal class Lonely {}",
        ]);

        $graded = self::graded($fixture);

        self::assertNotSame('high', $graded['Fx\\Alpha\\Str\\ByString']['confidence'], $graded['Fx\\Alpha\\Str\\ByString']['evidence']);
        self::assertStringContainsString('Beta', $graded['Fx\\Alpha\\Str\\ByString']['evidence']);
        self::assertFalse(self::proof($fixture, 'Fx\\Alpha\\Str\\ByString'));
        $relevant = (new CoverageReport($fixture->storage))->forNodes([NodeId::forClass('Fx\\Alpha\\Str\\ByString')])['relevant'];
        self::assertContains('Fx\\Beta\\Loader', array_column($relevant, 'subject'));

        self::assertSame('high', $graded['Fx\\Gamma\\Quiet']['confidence']);
        self::assertTrue(self::proof($fixture, 'Fx\\Gamma\\Quiet'));
        self::assertSame('high', $graded['Fx\\Delta\\Lonely']['confidence'], 'Beta does not depend on Delta');
        self::assertTrue(self::proof($fixture, 'Fx\\Delta\\Lonely'));
    }

    /** F6: the copy of a duplicated class the graph dropped had references nobody can see. */
    #[Test]
    public function what_a_shadowed_duplicate_refers_to_is_not_proven_unused(): void
    {
        // Each copy refers to a different class, so whichever copy the scan
        // order shadows, one target lost its only reference.
        $fixture = self::graphOf([
            'packages/alpha/src/Dup/One.php' => "namespace Fx\\Alpha\\Dup;\nfinal class Twin { public function u(): TargetOne { return new TargetOne(); } }",
            'packages/alpha/src/Dup/Two.php' => "namespace Fx\\Alpha\\Dup;\nfinal class Twin { public function u(): TargetTwo { return new TargetTwo(); } }",
            'packages/alpha/src/Dup/TargetOne.php' => "namespace Fx\\Alpha\\Dup;\nfinal class TargetOne {}",
            'packages/alpha/src/Dup/TargetTwo.php' => "namespace Fx\\Alpha\\Dup;\nfinal class TargetTwo {}",
            'packages/alpha/src/User.php' => "namespace Fx\\Alpha;\nfinal class User { public function t(): Dup\\Twin { return new Dup\\Twin(); } }",
            'packages/gamma/src/Quiet.php' => "namespace Fx\\Gamma;\nfinal class Quiet {}",
        ]);

        $graded = self::graded($fixture);

        $targets = array_values(array_filter(['Fx\\Alpha\\Dup\\TargetOne', 'Fx\\Alpha\\Dup\\TargetTwo'], static fn (string $c): bool => isset($graded[$c])));
        self::assertCount(1, $targets, 'precondition: the shadowed copy\'s target has no edge');
        $target = (string) reset($targets);
        self::assertNotSame('high', $graded[$target]['confidence'], $graded[$target]['evidence']);
        self::assertStringContainsString('duplicate', $graded[$target]['evidence']);
        self::assertFalse(self::proof($fixture, $target));
        self::assertSame('high', $graded['Fx\\Gamma\\Quiet']['confidence'], 'an unrelated module is untouched');
        self::assertTrue(self::proof($fixture, 'Fx\\Gamma\\Quiet'));
    }

    /** F8: a #[GraphIgnore]'d class is code the graph did not read — what it uses is not proven unused. */
    #[Test]
    public function what_an_ignored_class_uses_is_not_proven_unused(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Ign/Ignored.php' => "namespace Fx\\Alpha\\Ign;\n#[\\Semitexa\\ProjectGraph\\Attribute\\GraphIgnore('fixture: deliberately ignored')]\nfinal class Ignored { public function run(): Helped { return new Helped(); } }",
            'packages/alpha/src/Ign/Helped.php' => "namespace Fx\\Alpha\\Ign;\nfinal class Helped {}",
            'packages/gamma/src/Quiet.php' => "namespace Fx\\Gamma;\nfinal class Quiet {}",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('medium', $graded['Fx\\Alpha\\Ign\\Helped']['confidence'], $graded['Fx\\Alpha\\Ign\\Helped']['evidence']);
        self::assertStringContainsString('GraphIgnore', $graded['Fx\\Alpha\\Ign\\Helped']['evidence']);
        self::assertFalse(self::proof($fixture, 'Fx\\Alpha\\Ign\\Helped'));
        self::assertSame('high', $graded['Fx\\Gamma\\Quiet']['confidence']);
        self::assertTrue(self::proof($fixture, 'Fx\\Gamma\\Quiet'));
    }

    /** F9: files outside packages/, src/ and tests/ have module '' — their runtime class names still count. */
    #[Test]
    public function a_runtime_class_name_outside_any_module_still_counts(): void
    {
        $fixture = self::graphOf([
            'plain/Dyn.php' => "namespace Fx\\Plain;\nfinal class Dyn { public function make(string \$c): object { return new \$c(); } }",
            'plain/Lonely.php' => "namespace Fx\\Plain;\nfinal class Lonely {}",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('low', $graded['Fx\\Plain\\Lonely']['confidence'], $graded['Fx\\Plain\\Lonely']['evidence']);
        self::assertFalse(self::proof($fixture, 'Fx\\Plain\\Lonely'));
        // The base fixture's Orphan/ is another top-level directory with no runtime names of its own.
        self::assertSame('high', $graded[GraphFixture::NS . 'Orphan\\NobodyUsesMe']['confidence']);
    }

    /** F10: a `use` line nothing else backs is not a use. */
    #[Test]
    public function a_dead_import_does_not_keep_a_class_alive(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Imp/OnlyImported.php' => "namespace Fx\\Alpha\\Imp;\nfinal class OnlyImported {}",
            'packages/alpha/src/Imp2/Importer.php' => "namespace Fx\\Alpha\\Imp2;\nuse Fx\\Alpha\\Imp\\OnlyImported;\n#[\\Semitexa\\Core\\Attribute\\AsService]\nfinal class Importer { public function run(): int { return 1; } }",
            'packages/alpha/src/Imp/ImportedAndBuilt.php' => "namespace Fx\\Alpha\\Imp;\nfinal class ImportedAndBuilt {}",
            'packages/alpha/src/Imp2/Builder.php' => "namespace Fx\\Alpha\\Imp2;\nuse Fx\\Alpha\\Imp\\ImportedAndBuilt;\n#[\\Semitexa\\Core\\Attribute\\AsService]\nfinal class Builder { public function run(): object { return new ImportedAndBuilt(); } }",
        ]);

        $graded = self::graded($fixture);

        self::assertSame('high', $graded['Fx\\Alpha\\Imp\\OnlyImported']['confidence'] ?? null, 'only a dead `use` line names it');
        self::assertArrayNotHasKey('Fx\\Alpha\\Imp\\ImportedAndBuilt', $graded);
    }

    /** F11: Foo::class means the same to both finders — a name, neither a dependency nor a use. */
    #[Test]
    public function two_classes_naming_each_other_are_not_a_loop(): void
    {
        $fixture = self::graphOf([
            'packages/alpha/src/Cn/M.php' => "namespace Fx\\Alpha\\Cn;\nfinal class M { public const OTHER = N::class; }",
            'packages/alpha/src/Cn/N.php' => "namespace Fx\\Alpha\\Cn;\nfinal class N { public const OTHER = M::class; }",
            'packages/alpha/src/Cn/P.php' => "namespace Fx\\Alpha\\Cn;\nfinal class P { public function q(): string { return Q::class . Q::make(); } }",
            'packages/alpha/src/Cn/Q.php' => "namespace Fx\\Alpha\\Cn;\nfinal class Q { public static function make(): string { return P::class; } public function p(): P { return new P(); } }",
        ]);

        $graded = self::graded($fixture);
        $members = array_map(static fn (array $c): array => $c['members'], (new CycleFinder())->find($fixture->storage));

        // A static call and `new` are dependencies, whatever else names the class.
        self::assertContains([NodeId::forClass('Fx\\Alpha\\Cn\\P'), NodeId::forClass('Fx\\Alpha\\Cn\\Q')], $members);
        // In no loop at all — not as a pair, nor inside a larger one.
        foreach ($members as $component) {
            self::assertNotContains(NodeId::forClass('Fx\\Alpha\\Cn\\M'), $component, 'two Foo::class constants are not a loop');
            self::assertNotContains(NodeId::forClass('Fx\\Alpha\\Cn\\N'), $component, 'two Foo::class constants are not a loop');
        }
        self::assertSame('low', $graded['Fx\\Alpha\\Cn\\M']['confidence']);
        self::assertSame('low', $graded['Fx\\Alpha\\Cn\\N']['confidence']);
    }
}
