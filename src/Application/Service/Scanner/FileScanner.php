<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Scanner;

use Semitexa\ProjectGraph\Application\Service\Extractor\ConfigReferenceExtractor;

final class FileScanner
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'var'];

    /** @var \Closure(string): (string|false) */
    private readonly \Closure $hasher;

    /**
     * @param (\Closure(string): (string|false))|null $hasher content hash of a file, false when it cannot be read
     */
    public function __construct(
        private readonly IgnorePatternLoader $ignoreLoader,
        ?\Closure $hasher = null,
    ) {
        $this->hasher = $hasher ?? static fn (string $path): string|false => @hash_file('xxh3', $path);
    }

    /** @var array<string, int> rule => how many directories or PHP files it kept out of the last scan */
    private array $exclusions = [];

    /**
     * What the last scan left out, by rule: "dir:vendor" counts pruned
     * directories, an ignore pattern counts the directories it pruned or the
     * PHP files it skipped. Absence-based answers need to know this — a class
     * nobody references may be referenced from an excluded directory.
     *
     * @return array<string, int>
     */
    public function lastExclusions(): array
    {
        ksort($this->exclusions);

        return $this->exclusions;
    }

    /** @var array<string, true>|null resolved paths the scan may return; null = everything */
    private ?array $only = null;

    /**
     * The same scanner, limited to these files. The graph diff passes the files
     * git would commit, so the working tree is read the way the base ref is.
     *
     * @param array<string, true>|null $files resolved path => true
     */
    public function restrictedTo(?array $files): self
    {
        $scanner = clone $this;
        $scanner->only = $files;

        return $scanner;
    }

    /** @return list<FileScanResult> */
    public function scan(string $projectRoot, array $indexedFiles): array
    {
        $ignorePatterns = $this->ignoreLoader->load($projectRoot);
        $results = [];
        $this->exclusions = [];
        $root = rtrim($projectRoot, '/') . '/';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file) use ($root, $ignorePatterns) {
                    if ($file->isDir()) {
                        // An unreadable directory aborted the whole build from
                        // inside the iterator (round 2); it is left out and counted.
                        if (!$file->isReadable() || !$file->isExecutable()) {
                            $this->exclude('unreadable');
                            return false;
                        }
                        if (in_array($file->getBasename(), self::EXCLUDED_DIRS, true)) {
                            $this->exclude('dir:' . $file->getBasename());
                            return false;
                        }
                        // A directory pattern prunes the whole directory rather
                        // than being checked against every file inside it.
                        $relative = substr($file->getPathname(), strlen($root)) . '/';
                        foreach ($ignorePatterns as $pattern) {
                            if (str_ends_with($pattern, '/') && self::matchesDirectory($pattern, $relative)) {
                                $this->exclude($pattern);
                                return false;
                            }
                        }
                        return true;
                    }
                    $path = self::resolvePath($file);
                    if ($path === null || ($this->only !== null && !isset($this->only[$path]))) {
                        return false;
                    }
                    $relative = str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
                    foreach ($ignorePatterns as $pattern) {
                        $matches = str_ends_with($pattern, '/')
                            ? self::matchesDirectory($pattern, $relative)
                            // A pattern with a slash is a path from the project
                            // root ("src/Legacy/*.php"); it was matched against
                            // the file's basename and so never matched at all.
                            : (str_contains($pattern, '/')
                                ? fnmatch(ltrim($pattern, '/'), $relative, FNM_PATHNAME)
                                : fnmatch($pattern, basename($path)));
                        if ($matches) {
                            if ($file->getExtension() === 'php' || ConfigReferenceExtractor::handles($path)) {
                                $this->exclude($pattern);
                            }
                            return false;
                        }
                    }
                    return true;
                }
            )
        );

        $seen = [];
        foreach ($iterator as $file) {
            // PHP, and the configuration files that name classes.
            if ($file->getExtension() !== 'php' && !ConfigReferenceExtractor::handles($file->getPathname()) && !self::isPhpScript($file)) {
                continue;
            }

            $path = self::resolvePath($file);
            if ($path === null) {
                continue;
            }

            $hash = ($this->hasher)($path);
            if ($hash === false) {
                // One unreadable file (mode 000, a broken mount) used to abort
                // the whole build. It is left out and counted, so the coverage
                // report says the graph did not read everything.
                $this->exclude('unreadable');
                continue;
            }
            $seen[$path] = true;

            if (!isset($indexedFiles[$path])) {
                $results[] = new FileScanResult($path, $hash, FileStatus::Added);
            } elseif ($indexedFiles[$path] !== $hash) {
                $results[] = new FileScanResult($path, $hash, FileStatus::Modified);
            }
        }

        // Gone from the scan is gone from the graph: deleted, or now excluded
        // by a default or a .graphignore pattern. Checking only file_exists()
        // left newly ignored files in the graph until the next --full build.
        foreach ($indexedFiles as $indexedPath => $indexedHash) {
            if (!isset($seen[$indexedPath])) {
                $results[] = new FileScanResult($indexedPath, $indexedHash, FileStatus::Deleted);
            }
        }

        // By path, not directory-listing order: when two files declare one
        // class, the one met first holds it, and that must be the same file
        // in every build (see GraphBuilder's smallest-path rule).
        usort($results, static fn (FileScanResult $a, FileScanResult $b): int => strcmp($a->path, $b->path));

        return $results;
    }

    private function exclude(string $rule): void
    {
        $this->exclusions[$rule] = ($this->exclusions[$rule] ?? 0) + 1;
    }

    /**
     * A directory pattern: `/x/` is anchored at the project root; `x/` and
     * `a/b/` match at any depth. The default `tests/fixtures/` only ever
     * matched at the root, so every package's tests/fixtures was scanned.
     */
    private static function matchesDirectory(string $pattern, string $relative): bool
    {
        if (str_starts_with($pattern, '/')) {
            return str_starts_with($relative, substr($pattern, 1));
        }

        return str_starts_with($relative, $pattern) || str_contains('/' . $relative, '/' . $pattern);
    }

    /**
     * An extensionless executable PHP script (`bin/semitexa`, `#!/usr/bin/env
     * php`): it names classes like any other PHP file and was never read, so
     * the commands only it starts graded as unused (round 2).
     */
    private static function isPhpScript(\SplFileInfo $file): bool
    {
        if ($file->getExtension() !== '' || !$file->isFile() || !$file->isReadable() || $file->getSize() < 6) {
            return false;
        }
        $head = @file_get_contents($file->getPathname(), false, null, 0, 64);

        return is_string($head) && preg_match('/^#![^\n]*\bphp\b/', $head) === 1;
    }

    private static function resolvePath(\SplFileInfo $file): ?string
    {
        $path = $file->getRealPath();
        if (is_string($path)) {
            return $path;
        }

        $path = $file->getPathname();
        if ($path !== '' && file_exists($path)) {
            return $path;
        }

        return null;
    }
}
