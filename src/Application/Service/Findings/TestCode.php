<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * Whether a stored file is test code, judged INSIDE the project.
 *
 * Stored paths are absolute. Matching `/tests/` against them made every class
 * test code when the project itself sits under a `tests` directory (a CI
 * workspace, `/srv/tests/shop`), and the findings then reported nothing. The
 * project root the graph was built from is recorded as meta `project_root`;
 * a graph built before that falls back to the absolute match.
 */
final class TestCode
{
    private function __construct(private readonly string $rootPrefix)
    {
    }

    public static function of(GraphStorage $storage): self
    {
        return self::forRoot((string) ($storage->getMeta('project_root') ?? ''));
    }

    public static function forRoot(string $projectRoot): self
    {
        $root = rtrim($projectRoot, '/');

        return new self($root === '' ? '' : $root . '/');
    }

    public function contains(string $file): bool
    {
        $relative = $this->rootPrefix !== '' && str_starts_with($file, $this->rootPrefix)
            ? substr($file, strlen($this->rootPrefix))
            : $file;

        return str_starts_with($relative, 'tests/') || str_contains($relative, '/tests/');
    }
}
