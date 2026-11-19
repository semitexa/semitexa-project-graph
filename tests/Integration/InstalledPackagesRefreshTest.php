<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Semitexa\ProjectGraph\Tests\Support\PackageAttribute;
use Semitexa\ProjectGraph\Tests\Support\PackageAttributeExtractor;

/**
 * Extractors ask attribute instances, and attribute classes come from
 * installed packages: after a `composer update` the same project file means
 * something else. A refresh re-reads only changed project files, so it kept
 * the old package's facts for all the others
 * (tk-graph-refresh-misses-installed-package-changes, found by
 * lint:graph-extractors on UiNavigationExtractor's permissionFor() probe).
 * The installed package set is now part of what the graph was built for.
 */
final class InstalledPackagesRefreshTest extends TestCase
{
    private const SCREEN = 'class:Campaign\\Shop\\ProductsScreen';
    private const INSTALLED = 'vendor/composer/installed.json';

    protected function tearDown(): void
    {
        PackageAttribute::$release = 1;
    }

    #[Test]
    public function a_refresh_after_the_installed_packages_change_leaves_what_a_full_build_would(): void
    {
        $fixture = self::project();
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));

        // Nothing changed: the fingerprint must not turn every refresh into a build.
        self::assertSame(0, $engine->update($fixture->root)->filesScanned);

        // composer update: the package's attribute class answers differently,
        // and no project file changed.
        self::install($fixture, 2);
        clearstatcache();
        $engine->update($fixture->root);
        self::assertSame(['permission:catalog.view'], self::needs($fixture), 'the unchanged file is read with the installed attribute class');
        $refreshed = [$fixture->nodeLines(), $fixture->edgeLines()];

        $engine->fullBuild($fixture->root);
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshed, 'the refreshed graph is the full build');
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'and it is current again');
    }

    /** Before an install there is no installed.json: composer.lock says which packages the project means. */
    #[Test]
    public function without_installed_json_the_lock_file_is_the_package_set(): void
    {
        $fixture = self::project();
        unlink($fixture->path(self::INSTALLED));
        $fixture->write('composer.lock', '{"packages": [{"name": "acme/screens", "version": "1.0.0"}]}');
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertTrue($engine->isCurrentBuild($fixture->root));

        PackageAttribute::$release = 2;
        $fixture->write('composer.lock', '{"packages": [{"name": "acme/screens", "version": "2.0.0"}]}');
        clearstatcache();
        $engine->update($fixture->root);

        self::assertSame(['permission:catalog.view'], self::needs($fixture));
    }

    /**
     * The advisory refresh never rebuilds whole: with other packages installed
     * it must not refresh at all, or the caller reads a graph that is half the
     * old package's facts and says nothing.
     */
    #[Test]
    public function an_advisory_refresh_leaves_a_graph_of_other_packages_alone_and_says_it_is_not_current(): void
    {
        $fixture = self::project();
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertTrue($engine->isCurrentBuild($fixture->root));
        self::assertTrue($engine->isCurrentBuild(), 'asked without a root: the one it was built for');
        self::assertNotNull($engine->updateUnlessBusy($fixture->root));

        self::install($fixture, 2);
        $fixture->write('Later.php', "<?php\nnamespace Campaign;\nfinal class Later {}\n");
        clearstatcache();

        self::assertFalse($engine->isCurrentBuild($fixture->root));
        self::assertFalse($engine->isCurrentBuild());
        self::assertNull($engine->updateUnlessBusy($fixture->root));
        self::assertNull($fixture->storage->nodes->findById('class:Campaign\\Later'), 'nothing was refreshed');
        self::assertSame(['permission:catalog.read'], self::needs($fixture));
    }

    private static function project(): GraphFixture
    {
        $fixture = GraphFixture::create();
        $fixture->write('Shop/ProductsScreen.php', "<?php\nnamespace Campaign\\Shop;\n\n#[\\" . PackageAttribute::class . "(permission: 'catalog')]\nfinal class ProductsScreen\n{\n}\n");
        self::install($fixture, 1);

        return $fixture;
    }

    /** What `composer update` leaves: another release of the package, and installed.json naming it. */
    private static function install(GraphFixture $fixture, int $release): void
    {
        PackageAttribute::$release = $release;
        $fixture->write(self::INSTALLED, (string) json_encode(['packages' => [[
            'name' => 'acme/screens',
            'version' => $release . '.0.0',
            'dist' => ['reference' => str_repeat((string) $release, 40)],
        ]]]));
    }

    private static function engine(GraphFixture $fixture): IncrementalEngine
    {
        return new IncrementalEngine(
            new FileScanner(new IgnorePatternLoader()),
            new PhpParserAdapter(),
            new ExtractorPipeline([...ExtractorPipeline::default(), new PackageAttributeExtractor()]),
            new GraphBuilder($fixture->storage),
            $fixture->storage,
        );
    }

    /** @return list<string> */
    private static function needs(GraphFixture $fixture): array
    {
        $targets = [];
        foreach ($fixture->storage->edges->findBySource(self::SCREEN, EdgeType::RequiresPermission) as $edge) {
            $targets[] = $edge->getTargetId();
        }
        sort($targets);

        return $targets;
    }
}
