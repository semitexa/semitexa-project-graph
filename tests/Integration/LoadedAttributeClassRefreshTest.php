<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Index\InstalledPackages;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\ProjectGraph\Tests\Support\GraphFixture;
use Semitexa\ProjectGraph\Tests\Support\PackageAttribute;
use Semitexa\ProjectGraph\Tests\Support\PackageAttributeExtractor;

/**
 * Extractors ask attribute instances, so the attribute CLASS is part of what
 * a project file means. InstalledPackagesRefreshTest covers the class that
 * changes with installed.json. One in a path repository (this workspace's
 * packages/*), or in the project's own source, changes with no installed.json
 * change at all: a refresh kept `catalog.read` on the unchanged screen where
 * a full build gave `catalog.view`
 * (tk-graph-refresh-misses-loaded-class-changes).
 *
 * A process cannot load two versions of one class, so the edit of the class
 * file comes with {@see PackageAttribute::$release} turned, which stands for
 * what a new process loads from the edited file.
 */
final class LoadedAttributeClassRefreshTest extends TestCase
{
    private const SCREEN = 'class:Campaign\\Shop\\ProductsScreen';
    private const INSTALLED = 'vendor/composer/installed.json';

    private int $release = 1;

    /** @var list<string> directories outside the fixture project, removed afterwards */
    private array $outside = [];

    protected function setUp(): void
    {
        $this->release = PackageAttribute::$release;
        PackageAttribute::$release = 1;
    }

    protected function tearDown(): void
    {
        PackageAttribute::$release = $this->release;
        foreach ($this->outside as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->outside = [];
    }

    /** @return iterable<string, array{string, string}> where the attribute class is declared, and the file edited */
    public static function scannedSources(): iterable
    {
        yield "the project's own source" => ['src/Attribute/PackageAttribute.php', 'src/Attribute/PackageAttribute.php'];
        yield 'a path repository under the project root' => ['packages/acme-screens/src/PackageAttribute.php', 'packages/acme-screens/src/PackageAttribute.php'];
        yield 'a class the attribute inherits from' => ['src/Attribute/PackageAttribute.php', 'src/Attribute/SluggedAttribute.php'];
    }

    #[Test]
    #[DataProvider('scannedSources')]
    public function a_refresh_after_an_attribute_class_changes_leaves_what_a_full_build_would(string $declared, string $edited): void
    {
        $fixture = self::project();
        if ($declared === $edited) {
            $fixture->write($declared, self::attributeSource('read'));
        } else {
            $fixture->write($declared, "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Support;\n\n#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal class PackageAttribute extends SluggedAttribute\n{\n}\n");
            $fixture->write($edited, self::attributeSource('read', 'SluggedAttribute'));
        }
        if (str_starts_with($declared, 'packages/')) {
            self::installFromPath($fixture, $fixture->path('packages/acme-screens'));
        }
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'nothing changed: nothing is read again');
        $installed = InstalledPackages::fingerprint($fixture->root);

        // The attribute class is edited; installed.json and the screen are not.
        $fixture->write($edited, str_replace("'read'", "'view'", $fixture->read($edited)));
        PackageAttribute::$release = 2;
        clearstatcache();
        $result = $engine->update($fixture->root);

        self::assertSame(1, $result->filesScanned, 'one file changed on disk: a refresh, not a full build');
        self::assertSame($installed, InstalledPackages::fingerprint($fixture->root), 'a scanned package is no part of the fingerprint: editing it must not rebuild the graph whole');
        self::assertSame(['permission:catalog.view'], self::needs($fixture), 'the unchanged screen is read with the attribute class as it is now');
        $refreshed = [$fixture->nodeLines(), $fixture->edgeLines()];

        $engine->fullBuild($fixture->root);
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshed, 'the refreshed graph is the full build');
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'and it is current again');
    }

    /**
     * Review of 326d1c4: the users of an attribute class were looked up once,
     * for the files changed on disk. A class whose constructor default is a
     * constant of another class (AsCrud's paginationMode) was re-read when the
     * constant changed, as its reader, and its users were not.
     */
    #[Test]
    public function a_refresh_after_a_constant_an_attribute_class_reads_changes_leaves_what_a_full_build_would(): void
    {
        $fixture = self::project();
        $fixture->write('src/Attribute/Slugs.php', "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Support;\n\nfinal class Slugs\n{\n    public const READ = 'read';\n}\n");
        $fixture->write('src/Attribute/PackageAttribute.php', "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Support;\n\n#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal class PackageAttribute\n{\n"
            . "    public function __construct(public readonly string \$permission, public readonly string \$read = Slugs::READ)\n    {\n    }\n}\n");
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));

        // The constant is edited; the attribute class and the screen are not.
        $fixture->write('src/Attribute/Slugs.php', str_replace("'read'", "'view'", $fixture->read('src/Attribute/Slugs.php')));
        PackageAttribute::$release = 2;
        clearstatcache();
        $result = $engine->update($fixture->root);

        self::assertSame(1, $result->filesScanned, 'one file changed on disk: a refresh, not a full build');
        self::assertSame(['permission:catalog.view'], self::needs($fixture), 'the unchanged screen is read with what the attribute class gives now');
        $refreshed = [$fixture->nodeLines(), $fixture->edgeLines()];

        $engine->fullBuild($fixture->root);
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshed, 'the refreshed graph is the full build');
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'and it is current again');
    }

    /**
     * A link to a file that is not there hides nothing and is hashed by where
     * it points. (A file that is there and cannot be read is a failure:
     * InstalledPackagesRefreshTest, beside the unreadable installed.json.)
     */
    #[Test]
    public function a_dangling_link_in_a_path_repository_is_hashed_by_where_it_points(): void
    {
        $fixture = self::project();
        $package = $fixture->path('vendor/acme/screens');
        mkdir($package, 0o755, true);
        file_put_contents($package . '/PackageAttribute.php', self::attributeSource('read'));
        self::installFromPath($fixture, $package);
        $plain = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $plain);

        symlink($package . '/gone/One.php', $package . '/Linked.php');
        $one = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $one);
        self::assertNotSame($plain, $one, 'the link is part of the package');
        unlink($package . '/Linked.php');
        symlink($package . '/gone/Two.php', $package . '/Linked.php');
        $two = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $two);
        self::assertNotSame($one, $two, 'and so is where it points');
        unlink($package . '/Linked.php');
        self::assertSame($plain, InstalledPackages::fingerprint($fixture->root));
    }

    /** @return iterable<string, array{bool}> whether the package directory is outside the project root */
    public static function unscannedInstalls(): iterable
    {
        yield 'outside the project root, linked into vendor' => [true];
        yield 'copied into vendor' => [false];
    }

    /**
     * The scan never reads these files, so no edge leads from them to the
     * screen: their content is part of what the graph was built for.
     */
    #[Test]
    #[DataProvider('unscannedInstalls')]
    public function a_refresh_after_an_attribute_class_changes_in_a_path_repository_the_scan_does_not_read_leaves_what_a_full_build_would(bool $outside): void
    {
        $fixture = self::project();
        if ($outside) {
            $package = sys_get_temp_dir() . '/semitexa-graph-path-repo-' . bin2hex(random_bytes(4));
            $this->outside[] = $package;
        } else {
            $package = $fixture->path('vendor/acme/screens');
        }
        mkdir($package . '/src', 0o755, true);
        file_put_contents($package . '/src/PackageAttribute.php', self::attributeSource('read'));
        // Not PHP, and a vendor of its own: neither is part of the package's content.
        file_put_contents($package . '/README.md', "one\n");
        mkdir($package . '/vendor/other', 0o755, true);
        file_put_contents($package . '/vendor/other/Other.php', "<?php\n");
        self::installFromPath($fixture, $package);
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));
        self::assertTrue($engine->isCurrentBuild($fixture->root));

        file_put_contents($package . '/README.md', "two\n");
        file_put_contents($package . '/vendor/other/Other.php', "<?php\n// changed\n");
        clearstatcache();
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'nothing an attribute class is read from changed: no rebuild');

        file_put_contents($package . '/src/PackageAttribute.php', self::attributeSource('view'));
        PackageAttribute::$release = 2;
        clearstatcache();
        self::assertFalse($engine->isCurrentBuild($fixture->root), 'built for the package as it was');
        $engine->update($fixture->root);

        self::assertSame(['permission:catalog.view'], self::needs($fixture), 'the unchanged screen is read with the attribute class as it is now');
        $refreshed = [$fixture->nodeLines(), $fixture->edgeLines()];
        $engine->fullBuild($fixture->root);
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshed, 'the refreshed graph is the full build');
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'and it is current again');
    }

    /** A path package installed.json names and the disk does not have is a state of its own, not a failure. */
    #[Test]
    public function a_path_repository_that_is_gone_changes_the_fingerprint_and_fails_nothing(): void
    {
        $fixture = self::project();
        $package = $fixture->path('vendor/acme/screens');
        mkdir($package, 0o755, true);
        file_put_contents($package . '/PackageAttribute.php', self::attributeSource('read'));
        self::installFromPath($fixture, $package);
        $there = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $there);
        self::assertNotSame(hash_file('xxh3', $fixture->path(self::INSTALLED)), $there, 'the content of a path package the scan skips is in the fingerprint');

        exec('rm -rf ' . escapeshellarg($package));
        clearstatcache();
        $gone = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $gone, 'installed.json is still there: not the fingerprint of a project with no manifest');
        self::assertNotSame($there, $gone);

        // Installed as a link to a directory that is gone: the same state, and no failure either.
        symlink($fixture->root . '/gone/acme-screens', $package);
        clearstatcache();
        self::assertSame($gone, InstalledPackages::fingerprint($fixture->root));
    }

    /**
     * Review on project-graph#45: a package whose src is a link to another
     * directory was hashed without it, so an attribute class there changed
     * with the same fingerprint. A link back to a parent must not hang.
     */
    #[Test]
    public function a_directory_link_inside_a_path_repository_is_followed_once(): void
    {
        $fixture = self::project();
        $package = $fixture->path('vendor/acme/screens');
        $shared = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        $this->outside[] = $shared;
        mkdir($package, 0o755, true);
        mkdir($shared . '/Attribute', 0o755, true);
        file_put_contents($shared . '/Attribute/PackageAttribute.php', self::attributeSource('read'));
        symlink($shared, $package . '/src');
        symlink($package, $shared . '/Attribute/back');
        self::installFromPath($fixture, $package);
        $read = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $read);

        file_put_contents($shared . '/Attribute/PackageAttribute.php', self::attributeSource('view'));
        clearstatcache();
        $view = InstalledPackages::fingerprint($fixture->root);

        self::assertNotSame('', $view);
        self::assertNotSame($read, $view, 'the class behind the linked src is part of the package');
    }

    /**
     * A scan enters no linked directory. A path repository under the root is
     * the scan's, so with `src -> /shared/src` nothing read the class behind
     * the link: a refresh kept `catalog.read` where a full build gave
     * `catalog.view` (review on project-graph#45,
     * tk-graph-refresh-misses-edits-behind-a-linked-dir). Only what is behind
     * the link is in the fingerprint: the rest of the package still refreshes
     * file by file.
     */
    #[Test]
    public function a_refresh_after_an_edit_behind_a_directory_link_of_a_scanned_path_repository_leaves_what_a_full_build_would(): void
    {
        $fixture = self::project();
        $shared = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        $this->outside[] = $shared;
        mkdir($shared . '/Attribute', 0o755, true);
        file_put_contents($shared . '/Attribute/PackageAttribute.php', self::attributeSource('read'));
        $fixture->write('packages/acme-screens/Plain.php', "<?php\nnamespace Acme\\Screens;\n\nfinal class Plain\n{\n}\n");
        symlink($shared, $fixture->path('packages/acme-screens/src'));
        self::installFromPath($fixture, $fixture->path('packages/acme-screens'));
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'nothing changed: nothing is read again');
        $installed = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $installed);
        self::assertNotSame(hash_file('xxh3', $fixture->path(self::INSTALLED)), $installed, 'what the link leads to is in the fingerprint');

        // A file of the package the scan reads: still a refresh of that file.
        $fixture->write('packages/acme-screens/Plain.php', $fixture->read('packages/acme-screens/Plain.php') . "// touched\n");
        clearstatcache();
        self::assertSame(1, $engine->update($fixture->root)->filesScanned, 'one scanned file changed: a refresh, not a full build');
        self::assertSame($installed, InstalledPackages::fingerprint($fixture->root), 'the scanned part of the package is no part of the fingerprint');

        // The class behind the link is edited; no scanned file and no installed.json is.
        file_put_contents($shared . '/Attribute/PackageAttribute.php', self::attributeSource('view'));
        PackageAttribute::$release = 2;
        clearstatcache();
        self::assertFalse($engine->isCurrentBuild($fixture->root), 'built for the linked directory as it was');
        $engine->update($fixture->root);

        self::assertSame(['permission:catalog.view'], self::needs($fixture), 'the unchanged screen is read with the attribute class as it is now');
        $refreshed = [$fixture->nodeLines(), $fixture->edgeLines()];
        $engine->fullBuild($fixture->root);
        self::assertSame([$fixture->nodeLines(), $fixture->edgeLines()], $refreshed, 'the refreshed graph is the full build');
        self::assertSame(0, $engine->update($fixture->root)->filesScanned, 'and it is current again');
    }

    /**
     * Which directory links of a scanned path repository the fingerprint
     * follows: not one into what the scan reads, not one to a directory the
     * project is under, and a directory two links lead to once. One under a
     * directory no scan enters (vendor) is followed.
     */
    #[Test]
    public function only_a_directory_link_out_of_the_scan_is_in_the_fingerprint_and_a_link_to_a_parent_of_the_project_is_not_followed(): void
    {
        $fixture = self::project();
        $shared = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        $this->outside[] = $shared;
        mkdir($shared, 0o755, true);
        file_put_contents($shared . '/One.php', "<?php\n");
        $package = $fixture->path('packages/acme-screens');
        $fixture->write('packages/acme-screens/Plain.php', "<?php\n");
        $fixture->write('src/Scanned/Two.php', "<?php\n");
        self::installFromPath($fixture, $package);
        $plain = InstalledPackages::fingerprint($fixture->root);
        self::assertSame(hash_file('xxh3', $fixture->path(self::INSTALLED)), $plain, 'no link: installed.json alone, as before');

        // Into the scan, to the project root, to its parent and to /: nothing more to read.
        symlink($fixture->path('src/Scanned'), $package . '/scanned');
        symlink($fixture->root, $package . '/root');
        symlink(dirname($fixture->root), $package . '/parent');
        symlink('/', $package . '/machine');
        self::assertSame($plain, InstalledPackages::fingerprint($fixture->root));

        symlink($shared, $package . '/src');
        $one = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $one);
        self::assertNotSame($plain, $one, 'a link out of the scan is part of the package');
        // A second link to the same directory, and a link back from it: read once, and it ends.
        symlink($shared, $package . '/again');
        symlink($package, $shared . '/back');
        $twice = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $twice);
        self::assertNotSame($one, $twice, 'the second link is one more name for the classes');
        file_put_contents($shared . '/One.php', "<?php\n// changed\n");
        clearstatcache();
        $changed = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $changed);
        self::assertNotSame($twice, $changed);

        // Under the project root and in a directory no scan enters: not read by the scan either.
        mkdir($fixture->path('vendor/acme/shared'), 0o755, true);
        file_put_contents($fixture->path('vendor/acme/shared/Three.php'), "<?php\n");
        symlink($fixture->path('vendor/acme/shared'), $package . '/vendored');
        $vendored = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $vendored);
        self::assertNotSame($changed, $vendored);
        file_put_contents($fixture->path('vendor/acme/shared/Three.php'), "<?php\n// changed\n");
        clearstatcache();
        $edited = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $edited);
        self::assertNotSame($vendored, $edited);
    }

    /**
     * The fingerprint is read on every refresh, and a link may lead to a tree
     * of any size: past {@see InstalledPackages::LINKED_ENTRIES} entries
     * behind one link it reads no more, and says so where the scan says what
     * it left out. scandir() lists by name, so "a" is read before the many
     * and "z" is not reached.
     *
     * Each link has its own count (review of 61ad6d6): with one count for all,
     * the large tree behind `assets`, read first, left `src` unread and the
     * edit of the attribute class there unseen.
     */
    #[Test]
    public function a_tree_too_large_behind_one_link_is_partly_read_and_said_so_and_takes_nothing_from_another_link(): void
    {
        $fixture = self::project();
        $large = sys_get_temp_dir() . '/semitexa-graph-shared-assets-' . bin2hex(random_bytes(4));
        $small = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        array_push($this->outside, $large, $small);
        mkdir($large . '/a', 0o755, true);
        mkdir($large . '/z', 0o755, true);
        for ($i = 0; $i < InstalledPackages::LINKED_ENTRIES; $i++) {
            mkdir($large . '/m/' . $i, 0o755, true);
        }
        file_put_contents($large . '/a/First.php', "<?php\n");
        file_put_contents($large . '/z/Last.php', "<?php\n");
        mkdir($small, 0o755, true);
        file_put_contents($small . '/PackageAttribute.php', self::attributeSource('read'));
        $fixture->write('packages/acme-screens/Plain.php', "<?php\n");
        symlink($large, $fixture->path('packages/acme-screens/assets'));
        symlink($small, $fixture->path('packages/acme-screens/src'));
        self::installFromPath($fixture, $fixture->path('packages/acme-screens'));
        $truncated = [InstalledPackages::TRUNCATED . 'acme/screens/assets' => 1];

        [$built, $unread] = InstalledPackages::read($fixture->root);
        self::assertNotSame('', $built);
        self::assertSame($truncated, $unread, 'the large tree is named as partly read; the small one is whole');
        $engine = self::engine($fixture);
        $engine->fullBuild($fixture->root);
        self::assertSame(['permission:catalog.read'], self::needs($fixture));
        $stored = json_decode((string) $fixture->storage->getMeta('coverage_exclusions'), true);
        self::assertIsArray($stored);
        self::assertSame($truncated, array_intersect_key($stored, $truncated), 'the graph says what it did not read, beside what the scan left out');

        file_put_contents($large . '/z/Last.php', "<?php\n// changed\n");
        clearstatcache();
        self::assertSame($built, InstalledPackages::fingerprint($fixture->root), 'past the limit: not read, which is what the graph says');
        file_put_contents($large . '/a/First.php', "<?php\n// changed\n");
        clearstatcache();
        $first = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $first);
        self::assertNotSame($built, $first, 'within the limit: read');

        // The class behind the other link: its own count, so still seen.
        $engine->fullBuild($fixture->root);
        file_put_contents($small . '/PackageAttribute.php', self::attributeSource('view'));
        PackageAttribute::$release = 2;
        clearstatcache();
        self::assertFalse($engine->isCurrentBuild($fixture->root), 'the large tree took nothing from the small one');
        $engine->update($fixture->root);
        self::assertSame(['permission:catalog.view'], self::needs($fixture));

        // Whole again: nothing is said, and the fingerprint is another one.
        exec('rm -rf ' . escapeshellarg($large . '/m'));
        clearstatcache();
        [$whole, $unread] = InstalledPackages::read($fixture->root);
        self::assertSame([], $unread);
        self::assertNotSame('', $whole);
        self::assertNotSame($first, $whole);
    }

    /**
     * Review of 61ad6d6: the links were looked for where the scan does not
     * look. A link under the default-ignored tests/fixtures/, or under a
     * directory .graphignore names, was followed and hashed.
     */
    #[Test]
    public function a_link_the_scan_is_told_to_ignore_is_not_followed(): void
    {
        $fixture = self::project();
        $shared = sys_get_temp_dir() . '/semitexa-graph-shared-src-' . bin2hex(random_bytes(4));
        $this->outside[] = $shared;
        mkdir($shared, 0o755, true);
        file_put_contents($shared . '/One.php', "<?php\n");
        $fixture->write('.graphignore', "Generated/\n/packages/acme-screens/legacy/\n");
        $fixture->write('packages/acme-screens/Plain.php', "<?php\n");
        $fixture->write('packages/acme-screens/tests/fixtures/Stub.php', "<?php\n");
        $fixture->write('packages/acme-screens/Generated/Stub.php', "<?php\n");
        self::installFromPath($fixture, $fixture->path('packages/acme-screens'));
        $plain = hash_file('xxh3', $fixture->path(self::INSTALLED));

        symlink($shared, $fixture->path('packages/acme-screens/tests/fixtures/data'));
        symlink($shared, $fixture->path('packages/acme-screens/Generated/data'));
        symlink($shared, $fixture->path('packages/acme-screens/legacy'));
        self::assertSame([$plain, []], InstalledPackages::read($fixture->root), 'under an ignored directory, or ignored itself: installed.json alone');

        symlink($shared, $fixture->path('packages/acme-screens/src'));
        $linked = InstalledPackages::fingerprint($fixture->root);
        self::assertNotSame('', $linked);
        self::assertNotSame($plain, $linked, 'the same directory through a link the scan is not told to ignore');
    }

    private static function project(): GraphFixture
    {
        $fixture = GraphFixture::create();
        $fixture->write('Shop/ProductsScreen.php', "<?php\nnamespace Campaign\\Shop;\n\n#[\\" . PackageAttribute::class . "(permission: 'catalog')]\nfinal class ProductsScreen\n{\n}\n");
        $fixture->write(self::INSTALLED, (string) json_encode(['packages' => []]));

        return $fixture;
    }

    /** The class file as the package ships it: release 1 names the read permission "read", release 2 "view". */
    private static function attributeSource(string $read, string $class = 'PackageAttribute'): string
    {
        $head = $class === 'PackageAttribute' ? "#[\\Attribute(\\Attribute::TARGET_CLASS)]\nfinal class" : 'abstract class';

        return "<?php\nnamespace Semitexa\\ProjectGraph\\Tests\\Support;\n\n{$head} {$class}\n{\n"
            . "    public function __construct(public readonly string \$permission)\n    {\n    }\n\n"
            . "    public function permissionFor(string \$operation): string\n    {\n"
            . "        return \$this->permission . '.' . (\$operation === 'read' ? '{$read}' : \$operation);\n    }\n}\n";
    }

    /**
     * What `composer install` leaves for a path repository: installed.json
     * names it once, with a reference that no later edit of its files changes,
     * and vendor/<name> is the directory, or a link to it.
     */
    private static function installFromPath(GraphFixture $fixture, string $package): void
    {
        $vendored = $fixture->path('vendor/acme/screens');
        if ($package !== $vendored) {
            mkdir(dirname($vendored), 0o755, true);
            symlink($package, $vendored);
        }
        $fixture->write(self::INSTALLED, (string) json_encode(['packages' => [[
            'name' => 'acme/screens',
            'version' => 'dev-master',
            'dist' => ['type' => 'path', 'url' => $package, 'reference' => str_repeat('a', 40)],
            'install-path' => '../acme/screens',
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
