<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Scanner;

final class IgnorePatternLoader
{
    private const DEFAULT_PATTERNS = [
        'vendor/',
        'var/',
        'node_modules/',
        '.git/',
        'tests/fixtures/',
        '*.generated.php',
    ];

    /** @var list<string> */
    private array $patterns = [];

    public function load(string $projectRoot): array
    {
        $this->patterns = self::DEFAULT_PATTERNS;

        $ignoreFile = $projectRoot . '/.graphignore';
        if (is_file($ignoreFile)) {
            $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, '#')) {
                    $this->patterns[] = $line;
                }
            }
        }

        return $this->patterns;
    }

    public function shouldExclude(string $filePath, string $projectRoot): bool
    {
        $relative = str_replace($projectRoot . '/', '', $filePath);

        foreach ($this->patterns as $pattern) {
            if (str_ends_with($pattern, '/')) {
                if (str_starts_with($relative, $pattern)) {
                    return true;
                }
            } elseif (fnmatch($pattern, basename($relative))) {
                return true;
            }
        }

        return false;
    }
}
