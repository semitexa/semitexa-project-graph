<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Scanner;

final class IgnorePatternLoader
{
    private const DEFAULT_PATTERNS = [
        'vendor/',
        'var/',
        'node_modules/',
        '.git/',
        'tests/fixtures/',
        '*.generated.php',
        // Documentation snippets (semitexa-demo's resources/examples): code in
        // a fictional App\ namespace that nothing autoloads, reusing class
        // names across examples. Scanned, they were 12 of the workspace's 13
        // duplicate_class gaps and a class whose attributes could not be read.
        '*.example.php',
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
}
