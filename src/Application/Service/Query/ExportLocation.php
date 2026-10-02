<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

/**
 * Where `ai:review-graph:show --format=html` may write. The export is a map
 * of the codebase — every class, route and wiring edge — and it is the kind
 * of "proof" an agent attaches somewhere to show its work. So it lands under
 * `<project>/var/`, which is not served — by default in the evidence inbox,
 * where `ai:evidence` gives it a passport (real data, private, an expiry) and
 * which the scaffold .gitignore excludes:
 *  - `public/graph.html` put the map on the site;
 *  - a path inside the tree outside var/ is one `git add -A` from a commit;
 *  - an absolute path outside the project, from inside the container, is a
 *    file nobody on the host can open — it read as written and was lost.
 * Anywhere else is an explicit `--allow-anywhere`.
 */
final class ExportLocation
{
    /** The evidence inbox: `ai:evidence` (semitexa-dev) records what lands here. */
    public const DEFAULT_DIR = 'var/evidence/inbox';
    public const INBOX_SCHEMA = 'semitexa.evidence-inbox/v1';
    public const TTL_DAYS = 14;

    /**
     * @param string|null $requested --output as given; null or '' picks the default
     * @param string $slice what the file holds ('ProjectGraph', a focus, 'whole'), for the default name
     * @return string the absolute path to write
     */
    public static function resolve(string $projectRoot, ?string $requested, string $slice, bool $allowAnywhere): string
    {
        $root = rtrim($projectRoot, '/');
        if ($requested === null || $requested === '') {
            $name = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $slice), '-.');

            return $root . '/' . self::DEFAULT_DIR . '/graph-' . ($name === '' ? 'whole' : $name) . '.html';
        }

        $path = self::normalise(str_starts_with($requested, '/') ? $requested : $root . '/' . $requested);
        if ($allowAnywhere || str_starts_with($path, self::normalise($root . '/var') . '/')) {
            return $path;
        }

        throw new \InvalidArgumentException(sprintf(
            '%s is outside %s/var/. The export is a map of this codebase: it stays where git and the web server do not reach it. '
            . 'Omit --output to write %s/, or pass --allow-anywhere if this location is meant.',
            $requested,
            $root,
            self::DEFAULT_DIR,
        ));
    }

    /**
     * Beside a file written into the inbox, the hint `ai:evidence` adopts it
     * by. Elsewhere under var/ the operator chose the place: no hint.
     *
     * @return string|null the hint written
     */
    public static function hintFor(string $projectRoot, string $path, string $slice): ?string
    {
        if (!str_starts_with(self::normalise($path), self::normalise(rtrim($projectRoot, '/') . '/' . self::DEFAULT_DIR) . '/')) {
            return null;
        }
        $hint = $path . '.evidence.json';
        $written = @file_put_contents($hint, json_encode([
            'schema' => self::INBOX_SCHEMA,
            'kind' => 'graph-export',
            // The graph of this codebase is never synthetic.
            'data' => 'real',
            'note' => 'Project graph export: ' . $slice,
            'ttl_days' => self::TTL_DAYS,
            'created_by' => 'ai:review-graph:show',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return $written === false ? null : $hint;
    }

    /** `..` and `.` resolved lexically: the file need not exist yet, and a symlinked var/ is still var/. */
    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
