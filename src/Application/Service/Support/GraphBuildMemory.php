<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Support;

/**
 * Building the graph of a large project needs more than PHP's default limit:
 * 137 MB for 6920 files, measured 2026-11-05 on the framework's own workspace.
 * Running out is a fatal, so no catch could turn it into the warning that says
 * the results may be stale. The limit is only ever raised.
 */
final class GraphBuildMemory
{
    private const NEEDED = '512M';

    public static function ensure(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && ini_parse_quantity($current) < ini_parse_quantity(self::NEEDED)) {
            ini_set('memory_limit', self::NEEDED);
        }
    }
}
