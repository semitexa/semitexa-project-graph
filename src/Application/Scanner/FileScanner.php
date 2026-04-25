<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Scanner;

final class FileScanner
{
    private const DEFAULT_EXTENSIONS = ['php'];
    private const EXCLUDED_DIRS = ['vendor', 'node_modules', '.git', 'var'];

    public function __construct(
        private readonly IgnorePatternLoader $ignoreLoader,
    ) {}

    /** @return list<FileScanResult> */
    public function scan(string $projectRoot, array $indexedFiles): array
    {
        $ignorePatterns = $this->ignoreLoader->load($projectRoot);
        $results = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $dir) use ($projectRoot, $ignorePatterns) {
                    if ($file->isDir()) {
                        return !in_array($file->getBasename(), self::EXCLUDED_DIRS, true);
                    }
                    $path = self::resolvePath($file);
                    if ($path === null) {
                        return false;
                    }
                    foreach ($ignorePatterns as $pattern) {
                        if (str_ends_with($pattern, '/')) {
                            $relative = str_replace($projectRoot . '/', '', $path);
                            if (str_starts_with($relative, $pattern)) {
                                return false;
                            }
                        } elseif (fnmatch($pattern, basename($path))) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
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
