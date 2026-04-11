<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Support;

use Semitexa\Orm\Connection\ConnectionConfig;
use Semitexa\Orm\Connection\ConnectionRegistry;
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

    private static function resolveDefaultSqlitePath(string $projectRoot): string
    {
        $candidates = [
            $projectRoot . '/var/storage/project-graph.sqlite',
            $projectRoot . '/var/tmp/project-graph.sqlite',
        ];

        foreach ($candidates as $path) {
            $dir = dirname($path);

            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }

            if (is_writable($dir)) {
                return $path;
            }
        }

        return sys_get_temp_dir() . '/semitexa-project-graph.sqlite';
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
