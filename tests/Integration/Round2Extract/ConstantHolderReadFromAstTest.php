<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration\Round2Extract;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Tests\Support\ExtractorCampaign;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;

/**
 * A constant (or enum case) another class declares, read in an attribute
 * argument — `#[AsPublicPayload(path: Routes::LIST)]` — used to be read by
 * LOADING the holder: class_exists(), then interface_exists(), then
 * enum_exists(), each autoloading. Composer includes the file on every
 * failed check, so measured 2026-10-02 (round-2 repro b7):
 *  - a holder file whose class was renamed (Routes.php now declares Nova) was
 *    included three times: "Cannot redeclare class Nova", an uncatchable
 *    FATAL that killed `ai:review-graph:generate --full`;
 *  - a holder using a trait that does not exist: "Trait not found", FATAL;
 *  - a holder implementing a missing interface threw on load, and the route
 *    silently vanished — or not, depending on which file loaded it first.
 * The holder is now read from its parsed file, never loaded.
 *
 * Every holder lives in a namespace a test-local Composer ClassLoader maps
 * (PSR-4), so the OLD code could and did autoload it: that is what makes the
 * red run red.
 */
final class ConstantHolderReadFromAstTest extends TestCase
{
    use ExtractorCampaign;

    private ?ClassLoader $loader = null;

    protected function tearDown(): void
    {
        $this->loader?->unregister();
        $this->loader = null;
    }

    private function project(string $namespace, array $files): GraphFixture
    {
        $fixture = GraphFixture::create();
        foreach ($files as $path => $contents) {
            $fixture->write($path, $contents);
        }
        $this->loader = new ClassLoader();
        $this->loader->addPsr4($namespace . '\\', $fixture->path('src') . '/');
        $this->loader->register();
        $fixture->build();

        return $fixture;
    }

    private static function payload(string $namespace, string $class, string $path, string $extends = ''): string
    {
        return "<?php\nnamespace {$namespace}\\Api;\n\nuse {$namespace}\\Routes;\nuse {$namespace}\\Slug;\n\n"
            . "#[\\Semitexa\\Core\\Attribute\\AsPublicPayload(path: {$path}, methods: ['GET'])]\nfinal class {$class}" . ($extends !== '' ? " extends {$extends}" : '') . "\n{\n}\n";
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_holder_whose_class_was_renamed_is_not_included_and_the_build_survives(): void
    {
        $fixture = $this->project('R2Renamed', [
            'src/Routes.php' => "<?php\nnamespace R2Renamed;\nfinal class Nova\n{\n    public const LIST = '/list';\n}\n",
            'src/Api/ListPayload.php' => self::payload('R2Renamed', 'ListPayload', 'Routes::LIST'),
        ]);

        self::assertFalse(class_exists('R2Renamed\\Nova', false), 'the holder file was included to read a constant');
        // Routes no longer declares LIST anywhere: the route is unknown, not invented.
        self::assertFalse($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Renamed\\Api\\ListPayload', NodeId::forRoute('GET', '/list')));
        self::assertTrue($fixture->hasEdge(EdgeType::References, 'class:R2Renamed\\Api\\ListPayload', 'class:R2Renamed\\Routes'));
    }

    #[Test]
    #[RunInSeparateProcess]
    public function a_holder_using_a_missing_trait_is_read_not_loaded(): void
    {
        $fixture = $this->project('R2Trait', [
            'src/Routes.php' => "<?php\nnamespace R2Trait;\nfinal class Routes\n{\n    use \\R2Trait\\Missing\\NoSuchTrait;\n    public const LIST = '/trait-list';\n}\n",
            'src/Api/ListPayload.php' => self::payload('R2Trait', 'ListPayload', 'Routes::LIST'),
        ]);

        self::assertFalse(class_exists('R2Trait\\Routes', false), 'the holder was loaded to read a constant');
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Trait\\Api\\ListPayload', NodeId::forRoute('GET', '/trait-list')));
    }

    /** Z2: the route used to vanish when the holder could not be loaded. */
    #[Test]
    public function a_holder_implementing_a_missing_interface_still_gives_its_route(): void
    {
        $fixture = $this->project('R2Iface', [
            'src/Routes.php' => "<?php\nnamespace R2Iface;\nfinal class Routes implements \\R2Iface\\Missing\\NoSuchInterface\n{\n    public const LIST = '/iface-list';\n}\n",
            'src/Api/ListPayload.php' => self::payload('R2Iface', 'ListPayload', 'Routes::LIST'),
        ]);

        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Iface\\Api\\ListPayload', NodeId::forRoute('GET', '/iface-list')));
    }

    #[Test]
    public function constants_built_from_other_constants_and_enum_values_are_evaluated_from_the_ast(): void
    {
        $fixture = $this->project('R2Expr', [
            'src/Base.php' => "<?php\nnamespace R2Expr;\ninterface Base\n{\n    public const PREFIX = '/api';\n}\n",
            'src/Routes.php' => "<?php\nnamespace R2Expr;\nfinal class Routes implements Base\n{\n    public const LIST = self::PREFIX . '/list';\n    public const DEEP = Routes::LIST . '/' . Slug::Deep->value;\n}\n",
            'src/Slug.php' => "<?php\nnamespace R2Expr;\nenum Slug: string\n{\n    case Item = '/item';\n    case Deep = 'deep';\n}\n",
            'src/Api/ListPayload.php' => self::payload('R2Expr', 'ListPayload', 'Routes::LIST'),
            'src/Api/DeepPayload.php' => self::payload('R2Expr', 'DeepPayload', 'Routes::DEEP'),
            'src/Api/ItemPayload.php' => self::payload('R2Expr', 'ItemPayload', 'Slug::Item->value'),
        ]);

        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Expr\\Api\\ListPayload', NodeId::forRoute('GET', '/api/list')));
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Expr\\Api\\DeepPayload', NodeId::forRoute('GET', '/api/list/deep')));
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Expr\\Api\\ItemPayload', NodeId::forRoute('GET', '/item')));
        foreach (['R2Expr\\Routes', 'R2Expr\\Base', 'R2Expr\\Slug'] as $holder) {
            self::assertFalse(class_exists($holder, false) || interface_exists($holder, false) || enum_exists($holder, false), $holder . ' was loaded to read a constant');
        }
    }

    /**
     * Z5 (round-2 repro b4): `path: self::P` where P is declared on the
     * PARENT, in another file. The payload depends on that file, and the
     * refresh engine finds dependents by a references edge — there was none,
     * so editing the parent's constant left the old route in the graph.
     */
    #[Test]
    public function an_inherited_self_constant_references_the_class_that_declares_it(): void
    {
        $fixture = $this->project('R2Inherit', [
            'src/BaseRoutes.php' => "<?php\nnamespace R2Inherit;\nabstract class BaseRoutes\n{\n    public const P = '/base';\n}\n",
            'src/MidRoutes.php' => "<?php\nnamespace R2Inherit;\nabstract class MidRoutes extends BaseRoutes\n{\n}\n",
            'src/Api/SelfPayload.php' => self::payload('R2Inherit', 'SelfPayload', 'self::P', '\\R2Inherit\\BaseRoutes'),
            'src/Api/GrandPayload.php' => self::payload('R2Inherit', 'GrandPayload', 'self::P', '\\R2Inherit\\MidRoutes'),
        ]);

        $edge = self::edgeBetween($fixture, EdgeType::References, 'class:R2Inherit\\Api\\SelfPayload', 'class:R2Inherit\\BaseRoutes');
        self::assertSame('constant', $edge?->getMetadata()['via'] ?? null, 'no dependency on the parent declaring the constant');
        $grand = self::edgeBetween($fixture, EdgeType::References, 'class:R2Inherit\\Api\\GrandPayload', 'class:R2Inherit\\BaseRoutes');
        self::assertSame('constant', $grand?->getMetadata()['via'] ?? null, 'the grandparent declares it: that file is the dependency');
        self::assertTrue($fixture->hasEdge(EdgeType::ServesRoute, 'class:R2Inherit\\Api\\GrandPayload', NodeId::forRoute('GET', '/base')));
        self::assertFalse(class_exists('R2Inherit\\BaseRoutes', false), 'the parent was loaded to read its constant');
    }
}
