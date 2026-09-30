<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Scanner;

use Semitexa\ProjectGraph\Application\Service\Extractor\ConfigReferenceExtractor;

final class FileScanner
{
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'var'];

    public function __construct(
        private readonly IgnorePatternLoader $ignoreLoader,
    ) {}

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
                        if (in_array($file->getBasename(), self::EXCLUDED_DIRS, true)) {
                            $this->exclude('dir:' . $file->getBasename());
                            return false;
                        }
                        // A directory pattern prunes the whole directory rather
                        // than being checked against every file inside it.
                        $relative = substr($file->getPathname(), strlen($root)) . '/';
                        foreach ($ignorePatterns as $pattern) {
                            if (str_ends_with($pattern, '/') && str_starts_with($relative, $pattern)) {
                                $this->exclude($pattern);
                                return false;
                            }
                        }
                        return true;
                    }
                    $path = self::resolvePath($file);
                    if ($path === null) {
                        return false;
                    }
                    foreach ($ignorePatterns as $pattern) {
                        $matches = str_ends_with($pattern, '/')
                            ? str_starts_with(str_replace($root, '', $path), $pattern)
                            : fnmatch($pattern, basename($path));
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

        foreach ($iterator as $file) {
            // PHP, and the configuration files that name classes.
            if ($file->getExtension() !== 'php' && !ConfigReferenceExtractor::handles($file->getPathname())) {
                continue;
            }

            $path = self::resolvePath($file);
            if ($path === null) {
                continue;
            }

            $hash = hash_file('xxh3', $path);
            if ($hash === false) {
                throw new \RuntimeException(sprintf('Unable to hash scanned file: %s', $path));
            }

            if (!isset($indexedFiles[$path])) {
                $results[] = new FileScanResult($path, $hash, FileStatus::Added);
            } elseif ($indexedFiles[$path] !== $hash) {
                $results[] = new FileScanResult($path, $hash, FileStatus::Modified);
            }
        }

        foreach ($indexedFiles as $indexedPath => $indexedHash) {
            if (!file_exists($indexedPath)) {
                $results[] = new FileScanResult($indexedPath, $indexedHash, FileStatus::Deleted);
            }
        }

        return $results;
    }

    private function exclude(string $rule): void
    {
        $this->exclusions[$rule] = ($this->exclusions[$rule] ?? 0) + 1;
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
