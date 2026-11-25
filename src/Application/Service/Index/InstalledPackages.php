<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;

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
 * A scan enters no linked directory, so a path repository under the root
 * with `src -> /shared/src` has files the scan never reads. Those subtrees,
 * and only those, are part of the fingerprint by content: the rest of the
 * package stays the scan's, or every workspace edit would be a full build
 * (review, project-graph#45, tk-graph-refresh-misses-edits-behind-a-linked-dir).
 *
 * A link the scan would not look at (under a directory it skips or
 * .graphignore names) is not followed. What is behind a link and cannot be
 * read, or lies past the first {@see self::LINKED_ENTRIES} entries of that
 * link, is left out and said: {@see self::read()} names the link, and the
 * engine stores it with what the scan left out (coverage_exclusions).
 *
 * Known limits: a path repository under the root that .graphignore keeps out
 * of the scan; a directory link out of the scan that is in the project's own
 * source, not in a path repository; one that leads to a directory the project
 * root is under (a link to /), which is not followed; an absolute link target
 * that is there on the host and not in the container (or the other way), which
 * gives the two different fingerprints, so each rebuilds the other's graph;
 * a constant another file sets with define(), read in an
 * attribute argument (none of the workspace's 18006 attributes reads one,
 * measured 2026-11-25); and a process that loaded an attribute class before
 * its edit, which reads the loaded one whatever is refreshed. Each needs a
 * full build in a new process.
 *
 * @phpstan-type LinkWalk array{root: string, left: int, truncated: bool, unreadable: array<string, true>}
 */
final class InstalledPackages
{
    public const META = 'installed_packages';

    /** installed.json is what is on disk; composer.lock stands in before an install. Empty when the project has neither. */
    private const SOURCES = ['/vendor/composer/installed.json', '/composer.lock'];

    /**
     * How many directory entries (files of any kind and directories) the
     * fingerprint looks at behind ONE directory link of a scanned path
     * repository. It is read on every refresh, and a link may lead to a tree
     * of any size; each link has its own count, so a large tree behind one
     * takes nothing from another. scandir() lists by name, so the entries
     * left out are the same ones each time. Measured 2026-11-25 in the dev
     * container: a link with 3000 PHP files behind it costs one call about
     * 40 ms at 2 KB a file and 52 ms at 20 KB.
     */
    public const LINKED_ENTRIES = 3000;

    /** Rule prefixes of what {@see self::read()} left out behind a link, as coverage_exclusions has them. */
    public const TRUNCATED = 'link:truncated:';
    public const UNREADABLE = 'link:unreadable:';

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
        return self::read($projectRoot)[0];
    }

    /**
     * The fingerprint, and what it could not cover behind the directory links
     * of scanned path repositories: "link:truncated:<package><link>" => 1 for
     * a link with more than {@see self::LINKED_ENTRIES} entries behind it,
     * "link:unreadable:<package><link>" => how many files and directories
     * behind it could not be read. Neither is a failure, as a file the scan
     * cannot read is none: the tree is partly watched, and a reader of the
     * graph is told so.
     *
     * @return array{string, array<string, int>}
     *
     * @throws \RuntimeException as {@see self::fingerprint()}
     */
    public static function read(string $projectRoot): array
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
            [$unscanned, $linked] = $source === self::SOURCES[0] ? self::unscannedPathPackages($root, $path) : [[], []];
            if ($unscanned === [] && $linked === []) {
                return [$hash, []];
            }
            $context = hash_init('xxh3');
            hash_update($context, $hash);
            foreach ($unscanned as $name => $dir) {
                $own = [$dir => true];
                hash_update($context, "\0" . $name . "\0" . self::contentOf($dir, $own));
            }
            $unread = [];
            $targets = [];
            $realRoot = (string) realpath($root);
            foreach ($linked as $label => $link) {
                $real = realpath($link);
                $content = '';
                // A directory two links lead to is read once, under the first name.
                if ($real !== false && !isset($targets[$real])) {
                    $targets[$real] = true;
                    $visited = [$real => true];
                    $walk = ['root' => $realRoot, 'left' => self::LINKED_ENTRIES, 'truncated' => false, 'unreadable' => []];
                    $content = self::contentOf($link, $visited, $walk);
                    $shown = str_replace("\0", '', $label);
                    ksort($walk['unreadable']);
                    foreach ($walk['unreadable'] as $relative => $_) {
                        $content .= "\0unreadable:" . $relative;
                        $unread[self::UNREADABLE . $shown] = ($unread[self::UNREADABLE . $shown] ?? 0) + 1;
                    }
                    if ($walk['truncated']) {
                        $content .= "\0truncated:" . $label;
                        $unread[self::TRUNCATED . $shown] = 1;
                    }
                }
                hash_update($context, "\0link\0" . $label . "\0" . $content);
            }
            ksort($unread);

            return [hash_final($context), $unread];
        }

        return ['', []];
    }

    /**
     * The path-repository packages whose files the scan never reads: installed
     * outside the project root, or as a copy under a directory every scan skips
     * (vendor). installed.json names where each one is; it does not change when
     * a file of theirs does. And, for the ones the scan does read, the
     * directory links in them that lead out of what it reads.
     *
     * @return array{array<string, string>, array<string, string>} package name => its directory ('' when it is not there), by name;
     *                                                             then "name\0path in the package" => the directory link, by that key
     */
    private static function unscannedPathPackages(string $root, string $installedJson): array
    {
        $installed = json_decode((string) @file_get_contents($installedJson), true);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $realRoot = realpath($root);
        $found = [];
        $linked = [];
        $patterns = null;
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
            if ($realRoot === false || !self::scanReads($dir, $realRoot)) {
                $found[$name] = $dir;
                continue;
            }
            $links = [];
            $patterns ??= (new IgnorePatternLoader())->load($root);
            self::directoryLinksIn($dir, strlen($realRoot) + 1, $patterns, $links);
            foreach ($links as $link) {
                $real = realpath($link);
                if ($real !== false && self::isOutOfScan($real, $realRoot)) {
                    $linked[$name . "\0" . substr($link, strlen($dir))] = $link;
                }
            }
        }
        ksort($found);
        ksort($linked);

        return [$found, $linked];
    }

    /** Whether a scan of $realRoot reads the files of the directory $real: under the root, and under no directory a scan skips. */
    private static function scanReads(string $real, string $realRoot): bool
    {
        return str_starts_with($real . '/', $realRoot . '/')
            && array_intersect(explode('/', substr($real, strlen($realRoot))), FileScanner::EXCLUDED_DIRS) === [];
    }

    /**
     * Whether a directory link to $real leads to files the fingerprint has to
     * read. Not one into what the scan reads: those files are read at their
     * real path. Not one to a directory the root is under either (a link to
     * /): it leads to the whole project again, and to everything beside it.
     */
    private static function isOutOfScan(string $real, string $realRoot): bool
    {
        return !self::scanReads($real, $realRoot) && !str_starts_with($realRoot . '/', rtrim($real, '/') . '/');
    }

    /**
     * The directory links under $dir, as the scan meets them: it enters no
     * linked directory, none of {@see FileScanner::EXCLUDED_DIRS}, none an
     * ignore pattern prunes (the default tests/fixtures/, a .graphignore
     * line) and none it cannot list, so no link is looked for behind those,
     * and a link such a pattern names is none either.
     *
     * @param int $rootLength where the path from the project root starts in $dir
     * @param list<string> $patterns the scan's ignore patterns
     * @param list<string> $links
     */
    private static function directoryLinksIn(string $dir, int $rootLength, array $patterns, array &$links): void
    {
        $entries = @scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            $path = $dir . '/' . $entry;
            if ($entry === '.' || $entry === '..' || in_array($entry, FileScanner::EXCLUDED_DIRS, true) || !is_dir($path)
                || FileScanner::prunedBy($patterns, substr($path, $rootLength) . '/') !== null) {
                continue;
            }
            if (is_link($path)) {
                $links[] = $path;
            } else {
                self::directoryLinksIn($path, $rootLength, $patterns, $links);
            }
        }
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
     * Behind a directory link of a scanned package ($walk) nothing is a
     * failure: the scan leaves out and counts what it cannot read, and a
     * project that links to a tree with one closed directory in it must not
     * lose every graph command. What cannot be read is named in $walk.
     *
     * @param array<string, true> $visited real paths of the directories entered so far, $dir among them
     * @param LinkWalk|null $walk
     * @param-out ($walk is null ? null : LinkWalk) $walk
     *
     * @throws \RuntimeException when a file or a directory of it cannot be read, and $walk is null
     */
    private static function contentOf(string $dir, array &$visited, ?array &$walk = null): string
    {
        if ($dir === '') {
            return '';
        }
        $files = [];
        self::phpFilesIn($dir, $dir, $visited, $files, $walk);
        ksort($files);
        $context = hash_init('xxh3');
        foreach ($files as $file => $dangling) {
            $hash = $dangling ? 'link:' . (string) @readlink($file) : @hash_file('xxh3', $file);
            if (!is_string($hash) && $walk !== null) {
                $walk['unreadable'][substr($file, strlen($dir))] = true;
                continue;
            }
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
     * With $walk (behind a directory link of a scanned package): no directory
     * the scan of $walk['root'] reads, or that the root is under, is entered;
     * each entry looked at takes one from $walk['left'], and at none left the
     * walk ends and says so; a directory that cannot be read is named in
     * $walk and left out.
     *
     * @param array<string, true> $visited real paths of the directories entered
     * @param array<string, bool> $files path => whether it is a link to a file that is not there
     * @param LinkWalk|null $walk
     * @param-out ($walk is null ? null : LinkWalk) $walk
     *
     * @throws \RuntimeException when a directory cannot be listed or searched, or a link leads behind one that cannot, and $walk is null
     */
    private static function phpFilesIn(string $package, string $dir, array &$visited, array &$files, ?array &$walk = null): void
    {
        $entries = @scandir($dir);
        // Listed and not searchable (r--): every entry would look absent.
        if ($entries === false || @stat($dir . '/.') === false) {
            if ($walk !== null) {
                $walk['unreadable'][substr($dir, strlen($package))] = true;

                return;
            }
            throw new \RuntimeException("Cannot read the path repository {$package} ({$dir} cannot be listed or searched), so the installed packages cannot be told apart; the graph would be taken for current.");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($walk !== null) {
                if ($walk['left'] <= 0) {
                    $walk['truncated'] = true;

                    return;
                }
                $walk['left']--;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $real = in_array($entry, FileScanner::EXCLUDED_DIRS, true) ? false : realpath($path);
                if ($real !== false && !isset($visited[$real]) && ($walk === null || self::isOutOfScan($real, $walk['root']))) {
                    $visited[$real] = true;
                    self::phpFilesIn($package, $path, $visited, $files, $walk);
                }
                continue;
            }
            $dangling = is_link($path) && !file_exists($path);
            if ($dangling) {
                // file_exists() is false as well for a target behind a directory
                // that cannot be searched: that one is there, not dangling.
                $hiding = self::hidingDirectory($path . '/.');
                if ($hiding !== null && $walk !== null) {
                    $walk['unreadable'][substr($path, strlen($package))] = true;
                    continue;
                }
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
