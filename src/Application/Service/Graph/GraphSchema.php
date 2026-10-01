<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

/**
 * The graph tables, written out, for stores that live next to the project
 * graph rather than inside it: the graphs `ai:review-graph:diff --base`
 * builds for two refs, and the stores tests build.
 *
 * Not collected from the resource models: the schema collector reads EVERY
 * resource model in the workspace, not this package's five, and one of them
 * asks SQLite for an AUTOINCREMENT on a non-integer key. GraphSchemaTest keeps
 * these columns equal to the models' so the two cannot drift.
 */
final class GraphSchema
{
    /** @var array<string, list<string>> table => DDL statements */
    public const TABLES = [
        'graph_nodes' => [
            'CREATE TABLE graph_nodes (
                id TEXT PRIMARY KEY, type TEXT NOT NULL, fqcn TEXT NOT NULL, name TEXT NOT NULL,
                file TEXT NOT NULL, line INTEGER NOT NULL, end_line INTEGER NOT NULL DEFAULT 0,
                module TEXT NOT NULL DEFAULT \'\', metadata TEXT NOT NULL DEFAULT \'{}\',
                is_placeholder INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE INDEX graph_nodes_file ON graph_nodes (file)',
        ],
        'graph_edges' => [
            'CREATE TABLE graph_edges (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source_id TEXT NOT NULL, target_id TEXT NOT NULL,
                type TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT \'{}\'
            )',
            'CREATE INDEX graph_edges_source ON graph_edges (source_id)',
            'CREATE INDEX graph_edges_target ON graph_edges (target_id)',
            'CREATE UNIQUE INDEX graph_edges_identity ON graph_edges (source_id, target_id, type)',
        ],
        'graph_file_index' => [
            'CREATE TABLE graph_file_index (
                path TEXT PRIMARY KEY, content_hash TEXT NOT NULL, indexed_at INTEGER NOT NULL,
                module TEXT NOT NULL, line_count INTEGER NOT NULL, is_dirty INTEGER NOT NULL DEFAULT 0
            )',
        ],
        'graph_meta' => [
            'CREATE TABLE graph_meta (meta_key TEXT PRIMARY KEY, value TEXT NOT NULL)',
        ],
        'graph_coverage_gaps' => [
            'CREATE TABLE graph_coverage_gaps (
                id INTEGER PRIMARY KEY AUTOINCREMENT, file TEXT NOT NULL, kind TEXT NOT NULL,
                line INTEGER NOT NULL DEFAULT 0, subject TEXT NOT NULL DEFAULT \'\', detail TEXT NOT NULL
            )',
            'CREATE INDEX graph_coverage_gaps_file ON graph_coverage_gaps (file)',
        ],
    ];

    public static function create(DatabaseAdapterInterface $adapter): void
    {
        foreach (self::TABLES as $statements) {
            foreach ($statements as $sql) {
                $adapter->execute($sql);
            }
        }
    }
}
