<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;

/**
 * Which packages the project has installed, as one hash stored in the graph
 * meta beside build_version.
 *
 * Extractors read attribute INSTANCES (UiNavigationExtractor asks an AsCrud
 * for permissionFor()), and attribute classes come from installed packages.
 * A refresh re-reads only the project files that changed, so after a
 * `composer update` every unchanged file kept the facts the old attribute
 * class gave. A changed fingerprint makes the next refresh a full build.
 *
 * A package installed from a path repository changes without installed.json
 * changing. One under the project root (this workspace's packages/*) is
 * scanned like the project's own source, and the engine re-reads the users of
 * an attribute class when its file changes
 * ({@see IncrementalEngine}, LoadedAttributeClassRefreshTest). One the scan
 * does not enter (outside the root, or copied into vendor) is part of the
 * fingerprint by the content of its PHP files: an edit of any of them, a test
 * of the package included, makes the next refresh a full build.
 *
 * Known limits: a path repository under the root that .graphignore keeps out
 * of the scan; a directory link inside a path repository under the root that
 * points out of the scan (src -> /shared/src), whose files neither the scan nor
 * this fingerprint reads (review, project-graph#45, tk-graph-refresh-misses-edits-behind-a-linked-dir);
 * a constant another file sets with define(), read in an
 * attribute argument (none of the workspace's 18006 attributes reads one,
 * measured 2026-11-25); and a process that loaded an attribute class before
 * its edit, which reads the loaded one whatever is refreshed. Each needs a
 * full build in a new process.
 */
final class InstalledPackages
{
    public const META = 'installed_packages';

    /** installed.json is what is on disk; composer.lock stands in before an install. Empty when the project has neither. */
    private const SOURCES = ['/vendor/composer/installed.json', '/composer.lock'];

    /**
     * The next source stands in only for one that is absent. One that is there
     * and cannot be read is a failure: two installs with the same lock file
     * would get the same fingerprint, and a graph built for the other one
     * would read as current (review, project-graph#40). So is one in a
     * directory that cannot be searched (a root-owned vendor/composer at
     * 0700): nothing says it is absent.
     *
     * @throws \RuntimeException when a source that exists, or may, cannot be read
     */
    public static function fingerprint(string $projectRoot): string
    {
        $root = rtrim($projectRoot, '/');
        foreach (self::SOURCES as $source) {
            $path = $root . $source;
            if (!is_file($path)) {
                $hiding = self::hidingDirectory($path);
                if ($hiding !== null) {
                    throw new \RuntimeException("Cannot search {$hiding} for {$path}, so the installed packages cannot be told apart; the graph would be taken for current.");
                }
                continue;
            }
            $hash = @hash_file('xxh3', $path);
            if (!is_string($hash)) {
                throw new \RuntimeException("Cannot read {$path}, so the installed packages cannot be told apart; the graph would be taken for current.");
            }
            $unscanned = $source === self::SOURCES[0] ? self::unscannedPathPackages($root, $path) : [];
            if ($unscanned === []) {
                return $hash;
            }
            $context = hash_init('xxh3');
            hash_update($context, $hash);
            foreach ($unscanned as $name => $dir) {
                hash_update($context, "\0" . $name . "\0" . self::contentOf($dir));
            }

            return hash_final($context);
        }

        return '';
    }

    /**
     * The path-repository packages whose files the scan never reads: installed
     * outside the project root, or as a copy under a directory every scan skips
     * (vendor). installed.json names where each one is; it does not change when
     * a file of theirs does.
     *
     * @return array<string, string> package name => its directory ('' when it is not there), by name
     */
    private static function unscannedPathPackages(string $root, string $installedJson): array
    {
        $installed = json_decode((string) @file_get_contents($installedJson), true);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $realRoot = realpath($root);
        $found = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (!is_array($package) || !is_array($package['dist'] ?? null) || ($package['dist']['type'] ?? null) !== 'path') {
                continue;
            }
            $name = $package['name'] ?? null;
            $installPath = $package['install-path'] ?? null;
            if (!is_string($name) || !is_string($installPath)) {
                continue;
            }
            $named = str_starts_with($installPath, '/') ? $installPath : dirname($installedJson) . '/' . $installPath;
            $dir = realpath($named);
            // is_dir() too: PHP's realpath cache answers for a directory removed a moment ago.
            if ($dir === false || !is_dir($dir)) {
                // realpath() fails as well for a package behind a directory that
                // cannot be searched: that one is there, and what it holds may
                // have changed (review, project-graph#45).
                $hiding = self::hidingDirectory($named . '/.');
                if ($hiding !== null) {
                    throw new \RuntimeException("Cannot search {$hiding} for the path repository {$named} ({$name}), so the installed packages cannot be told apart; the graph would be taken for current.");
                }
                $found[$name] = '';
                continue;
            }
            $inside = $realRoot !== false && str_starts_with($dir . '/', $realRoot . '/') ? substr($dir, strlen($realRoot)) : null;
            if ($inside === null || array_intersect(explode('/', $inside), FileScanner::EXCLUDED_DIRS) !== []) {
                $found[$name] = $dir;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * One hash for the PHP files of a package directory, by path and content,
     * leaving out what a scan leaves out (its own vendor, var, .git).
     *
     * A file or a directory that is there and cannot be read is a failure, as
     * an unreadable installed.json is: what it holds may have changed, and the
     * graph would be taken for current. A link to a file that is not there
     * hides nothing, PHP can load no class from it, and it is hashed by where
     * it points: one dangling link must not stop every graph command.
     *
     * @throws \RuntimeException when a file or a directory of it cannot be read
     */
    private static function contentOf(string $dir): string
    {
        if ($dir === '') {
            return '';
        }
        $files = [];
        $visited = [$dir => true];
        self::phpFilesIn($dir, $dir, $visited, $files);
        ksort($files);
        $context = hash_init('xxh3');
        foreach ($files as $file => $dangling) {
            $hash = $dangling ? 'link:' . (string) @readlink($file) : @hash_file('xxh3', $file);
            if (!is_string($hash)) {
                throw new \RuntimeException("Cannot read {$file}, so the installed packages cannot be told apart; the graph would be taken for current.");
            }
            hash_update($context, substr($file, strlen($dir)) . "\0" . $hash . "\0");
        }

        return hash_final($context);
    }

    /**
     * The PHP files under $dir, through directory links too: PHP autoloads a
     * class from `src -> ../shared/src` like from any other directory, and an
     * iterator that does not follow links left such a class out of the
     * fingerprint (review, project-graph#45). A directory is entered once, by
     * its real path, so a link back to a parent ends there. A link is followed
     * wherever it points, outside the package too: a link to /usr is walked,
     * and one to / stops at the first system directory it cannot search.
     *
     * @param array<string, true> $visited real paths of the directories entered
     * @param array<string, bool> $files path => whether it is a link to a file that is not there
     *
     * @throws \RuntimeException when a directory cannot be listed or searched, or a link leads behind one that cannot
     */
    private static function phpFilesIn(string $package, string $dir, array &$visited, array &$files): void
    {
        $entries = @scandir($dir);
        // Listed and not searchable (r--): every entry would look absent.
        if ($entries === false || @stat($dir . '/.') === false) {
            throw new \RuntimeException("Cannot read the path repository {$package} ({$dir} cannot be listed or searched), so the installed packages cannot be told apart; the graph would be taken for current.");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $real = in_array($entry, FileScanner::EXCLUDED_DIRS, true) ? false : realpath($path);
                if ($real !== false && !isset($visited[$real])) {
                    $visited[$real] = true;
                    self::phpFilesIn($package, $path, $visited, $files);
                }
                continue;
            }
            $dangling = is_link($path) && !file_exists($path);
            if ($dangling) {
                // file_exists() is false as well for a target behind a directory
                // that cannot be searched: that one is there, not dangling.
                $hiding = self::hidingDirectory($path . '/.');
                if ($hiding !== null) {
                    throw new \RuntimeException("Cannot search {$hiding} for what {$path} links to, so the installed packages cannot be told apart; the graph would be taken for current.");
                }
            }
            if (str_ends_with($entry, '.php')) {
                $files[$path] = $dangling;
            }
        }
    }

    /**
     * The directory that hides $path: the deepest one on its way that exists,
     * when it cannot be searched. Null when that one can be searched, so $path
     * is absent. A symbolic link on the way that leads to no directory is
     * followed (review, project-graph#43): a vendor linked into a directory
     * that cannot be searched hides the file; a dangling link leaves it absent,
     * as no vendor does. A link loop, or links more than 40 deep (the kernel's
     * limit), cannot be followed, and the link hides $path.
     */
    private static function hidingDirectory(string $path, int $links = 0): ?string
    {
        $dir = dirname($path);
        while (!is_dir($dir) && !is_link($dir) && $dir !== dirname($dir)) {
            $dir = dirname($dir);
        }
        if (is_dir($dir)) {
            // stat() of "<dir>/." looks a name up in <dir>, which takes search permission on it.
            return @stat($dir . '/.') === false ? $dir : null;
        }
        $target = $links < 40 && is_link($dir) ? @readlink($dir) : false;
        if ($target === false) {
            return $dir;
        }
        $followed = (str_starts_with($target, '/') ? $target : dirname($dir) . '/' . $target) . substr($path, strlen($dir));

        return self::hidingDirectory($followed, $links + 1);
    }
}
