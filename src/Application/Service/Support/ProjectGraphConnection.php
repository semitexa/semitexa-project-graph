<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Support;

use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\Orm\OrmManager;

final class ProjectGraphConnection
{
    public const NAME = 'project_graph';

    public static function manager(ConnectionRegistry $connections, string $projectRoot): OrmManager
    {
        if (!$connections->has(self::NAME)) {
            $connections->register(self::NAME, new OrmManager(
                config: self::resolveConfig($projectRoot),
                connectionName: self::NAME,
            ));
        }

        return $connections->manager(self::NAME);
    }

    private static function resolveConfig(string $projectRoot): ConnectionConfig
    {
        if (self::hasNamedConnectionEnvironment()) {
            return ConnectionConfig::fromEnvironment(self::NAME);
        }

        return new ConnectionConfig(
            driver: 'sqlite',
            sqlitePath: self::resolveDefaultSqlitePath($projectRoot),
            sqliteMemory: false,
        );
    }

    /**
     * The first usable candidate is not enough: whether a directory is writable
     * depends on who asks. The app worker runs as root and can write anywhere,
     * a CLI run as the host user cannot write a root-owned `var/storage`, so the
     * two used to resolve DIFFERENT files — the CLI rebuilt one graph while the
     * worker kept reading another, frozen at whenever root last built it.
     *
     * Among the candidates this process can use, the graph that already exists
     * and was written most recently wins; only when none exists does order decide.
     */
    public static function resolveDefaultSqlitePath(string $projectRoot): string
    {
        $candidates = [
            $projectRoot . '/var/storage/project-graph.sqlite',
            $projectRoot . '/var/tmp/project-graph.sqlite',
        ];

        $firstUsable = null;
        $newest = null;
        $newestMtime = -1;

        foreach ($candidates as $path) {
            $dir = dirname($path);

            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }

            if (!is_writable($dir) || (is_file($path) && !is_writable($path))) {
                continue;
            }

            $firstUsable ??= $path;

            $mtime = is_file($path) ? (int) filemtime($path) : -1;
            if ($mtime > $newestMtime) {
                $newest = $path;
                $newestMtime = $mtime;
            }
        }

        return $newest ?? $firstUsable ?? sys_get_temp_dir() . '/semitexa-project-graph.sqlite';
    }

    private static function hasNamedConnectionEnvironment(): bool
    {
        $prefixes = [
            'DB_PROJECT_GRAPH_DRIVER',
            'DB_PROJECT_GRAPH_SQLITE_PATH',
            'DB_PROJECT_GRAPH_SQLITE_MEMORY',
            'DB_PROJECT_GRAPH_HOST',
            'DB_PROJECT_GRAPH_PORT',
            'DB_PROJECT_GRAPH_DATABASE',
            'DB_PROJECT_GRAPH_USERNAME',
            'DB_PROJECT_GRAPH_USER',
            'DB_PROJECT_GRAPH_PASSWORD',
        ];

        foreach ($prefixes as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
