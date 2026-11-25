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
use Semitexa\ProjectGraph\Application\Service\Index\InstalledPackages;
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

    /** The release before this test, put back as it was (review, project-graph#40). */
    private int $release = 1;

    protected function setUp(): void
    {
        $this->release = PackageAttribute::$release;
    }

    protected function tearDown(): void
    {
        PackageAttribute::$release = $this->release;
        PackageAttributeExtractor::$whileExtracting = null;
    }

    /**
     * Review on project-graph#40: the fingerprint was read when it was stored,
     * after the scan. Packages that changed during the scan were then stored
     * as the ones it used, and the graph read as current with the old facts.
     */
    #[Test]
    public function packages_that_change_during_a_refresh_leave_it_not_current(): void
    {
        $fixture = self::project();
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        $fixture->write('Shop/ProductsScreen.php', $fixture->read('Shop/ProductsScreen.php') . "// touched\n");
        PackageAttributeExtractor::$whileExtracting = static function () use ($fixture): void {
            PackageAttributeExtractor::$whileExtracting = null;
            self::install($fixture, 2);
        };

        $engine->update($fixture->root);

        self::assertNull(PackageAttributeExtractor::$whileExtracting, 'the refresh read the touched file');
        self::assertFalse($engine->isCurrentBuild($fixture->root), 'built while the packages changed: the next refresh must rebuild');
    }

    /**
     * Review on project-graph#40: an installed.json that is there and unreadable
     * is no reason to read composer.lock instead. Nor is one that a vendor/composer,
     * or a vendor, that cannot be searched hides from is_file()
     * (tk-graph-fingerprint-untraversable-vendor).
     */
    #[Test]
    public function an_installed_json_that_cannot_be_read_is_a_failure_not_the_lock_file(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            // verify:accept-test-change root reads a file whatever its mode, so there is nothing to provoke; the suite runs as uid 1000 in the workspace and the release clone
            self::markTestSkipped('root reads a file whatever its mode');
        }
        $fixture = self::project();
        $fixture->write('composer.lock', '{"packages": []}');
        chmod($fixture->path(self::INSTALLED), 0000);
        // Not try/fail/catch: PHPUnit's own failure is a RuntimeException too,
        // and the catch would take it for the gate's.
        $thrown = null;
        try {
            InstalledPackages::fingerprint($fixture->root);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($fixture->path(self::INSTALLED), 0644);
        }
        self::assertInstanceOf(\RuntimeException::class, $thrown, 'an unreadable installed.json gave a fingerprint (composer.lock\'s)');
        self::assertStringStartsWith('Cannot read ' . $fixture->path(self::INSTALLED), $thrown->getMessage());

        $installed = $fixture->path(self::INSTALLED);
        foreach ([dirname($installed), dirname($installed, 2)] as $closed) {
            chmod($closed, 0000);
            $thrown = null;
            try {
                InstalledPackages::fingerprint($fixture->root);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            } finally {
                chmod($closed, 0755);
            }
            self::assertInstanceOf(\RuntimeException::class, $thrown, "{$closed} at 0000 gave a fingerprint (composer.lock's)");
            self::assertStringStartsWith("Cannot search {$closed} for {$installed}, ", $thrown->getMessage());
        }
        self::assertSame(hash_file('xxh3', $installed), InstalledPackages::fingerprint($fixture->root), 'searchable and readable again: installed.json');

        // A vendor linked into a directory that cannot be searched (review, project-graph#43):
        // is_dir() is false for vendor and vendor/composer alike.
        $outside = sys_get_temp_dir() . '/semitexa-graph-outside-' . bin2hex(random_bytes(4));
        $vendor = $fixture->path('vendor');
        mkdir($outside . '/private/install', 0o755, true);
        rename($vendor, $outside . '/private/install/vendor');
        symlink($outside . '/private/install/vendor', $vendor);
        $thrown = null;
        try {
            chmod($outside . '/private', 0000);
            InstalledPackages::fingerprint($fixture->root);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($outside . '/private', 0o755);
            unlink($vendor);
            rename($outside . '/private/install/vendor', $vendor);
            exec('rm -rf ' . escapeshellarg($outside));
        }
        self::assertInstanceOf(\RuntimeException::class, $thrown, 'a vendor linked out of reach gave a fingerprint (composer.lock\'s)');
        self::assertStringStartsWith("Cannot search {$outside}/private for {$installed}, ", $thrown->getMessage());

        // A path repository the scan does not read is in the fingerprint by
        // content (tk-graph-refresh-misses-loaded-class-changes): a file or a
        // directory of it that cannot be read may hold anything.
        $package = $fixture->path('vendor/acme/screens');
        mkdir($package . '/src', 0o755, true);
        file_put_contents($package . '/src/Screen.php', "<?php\n");
        $fixture->write(self::INSTALLED, (string) json_encode(['packages' => [[
            'name' => 'acme/screens',
            'dist' => ['type' => 'path', 'url' => $package, 'reference' => str_repeat('a', 40)],
            'install-path' => '../acme/screens',
        ]]]));
        $readable = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $readable);
        self::assertNotSame(hash_file('xxh3', $installed), $readable);
        // A link to a file, and the package itself, behind a directory that
        // cannot be searched: there, not dangling and not gone (review, project-graph#45).
        mkdir($outside . '/private', 0o755, true);
        file_put_contents($outside . '/private/Hidden.php', "<?php\n");
        symlink($outside . '/private/Hidden.php', $package . '/src/Linked.php');
        $linked = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $linked);
        self::assertNotSame($readable, $linked, 'a link to a file that can be read is the file');
        foreach ([
            $package . '/src/Screen.php' => "Cannot read {$package}/src/Screen.php, ",
            $package . '/src' => "Cannot read the path repository {$package} (",
            $outside . '/private' => "Cannot search {$outside}/private for what {$package}/src/Linked.php links to, ",
        ] as $closed => $message) {
            $mode = fileperms($closed) & 0o777;
            chmod($closed, 0000);
            $thrown = null;
            try {
                InstalledPackages::fingerprint($fixture->root);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            } finally {
                chmod($closed, $mode);
            }
            self::assertInstanceOf(\RuntimeException::class, $thrown, "{$closed} at 0000 gave a fingerprint");
            self::assertStringStartsWith($message, $thrown->getMessage());
        }
        unlink($package . '/src/Linked.php');
        rename($package, $outside . '/private/screens');
        symlink($outside . '/private/screens', $package);
        $thrown = null;
        try {
            chmod($outside . '/private', 0000);
            InstalledPackages::fingerprint($fixture->root);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($outside . '/private', 0o755);
            exec('rm -rf ' . escapeshellarg($outside));
        }
        self::assertInstanceOf(\RuntimeException::class, $thrown, 'a path repository linked out of reach read as one that is gone');
        self::assertStringStartsWith("Cannot search {$outside}/private for the path repository " . dirname($installed) . '/../acme/screens (acme/screens), ', $thrown->getMessage());

        // Behind a directory link of a path repository the scan reads, what
        // cannot be read is no failure (review of 61ad6d6): it threw, so every
        // graph command failed in a project where the scan, which leaves such
        // a directory out and counts it, had worked. Left out and counted, in
        // LoadedAttributeClassRefreshTest's words for the rest of such links.
        $shared = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        mkdir($shared . '/Closed', 0o755, true);
        file_put_contents($shared . '/Closed/Hidden.php', "<?php\n");
        file_put_contents($shared . '/Closed.php', "<?php\n");
        file_put_contents($shared . '/Open.php', "<?php\n");
        $fixture->write('packages/acme-linked/Plain.php', "<?php\n");
        symlink($shared, $fixture->path('packages/acme-linked/src'));
        $fixture->write(self::INSTALLED, (string) json_encode(['packages' => [[
            'name' => 'acme/linked',
            'dist' => ['type' => 'path', 'url' => 'packages/acme-linked', 'reference' => str_repeat('a', 40)],
            'install-path' => '../../packages/acme-linked',
        ]]]));
        clearstatcache();
        [$open, $unread] = InstalledPackages::read($fixture->root);
        self::assertNotSame('', $open);
        self::assertNotSame(hash_file('xxh3', $installed), $open, 'what the link leads to is in the fingerprint');
        self::assertSame([], $unread);
        $thrown = null;
        $closed = ['', []];
        $edited = '';
        try {
            chmod($shared . '/Closed', 0000);
            chmod($shared . '/Closed.php', 0000);
            clearstatcache();
            $closed = InstalledPackages::read($fixture->root);
            file_put_contents($shared . '/Open.php', "<?php\n// changed\n");
            clearstatcache();
            $edited = InstalledPackages::fingerprint($fixture->root);
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            chmod($shared . '/Closed', 0o755);
            chmod($shared . '/Closed.php', 0o644);
            exec('rm -rf ' . escapeshellarg($shared));
        }
        self::assertNull($thrown, 'a closed directory behind a link stopped the fingerprint: ' . $thrown?->getMessage());
        self::assertSame([InstalledPackages::UNREADABLE . 'acme/linked/src' => 2], $closed[1], 'the directory and the file, counted under the link');
        self::assertNotSame('', $closed[0]);
        self::assertNotSame($open, $closed[0], 'not the fingerprint of the tree that was read whole');
        self::assertNotSame('', $edited);
        self::assertNotSame($closed[0], $edited, 'what can be read beside it is still read');
    }

    /** A vendor that is a dangling link is no install, as no vendor is: the lock file stands in (review, project-graph#43). */
    #[Test]
    public function a_dangling_vendor_link_leaves_the_lock_file_to_stand_in(): void
    {
        $fixture = self::project();
        $fixture->write('composer.lock', '{"packages": []}');
        $vendor = $fixture->path('vendor');
        exec('rm -rf ' . escapeshellarg($vendor));
        symlink($fixture->root . '/gone/vendor', $vendor);
        clearstatcache();

        self::assertSame(hash_file('xxh3', $fixture->path('composer.lock')), InstalledPackages::fingerprint($fixture->root));
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
