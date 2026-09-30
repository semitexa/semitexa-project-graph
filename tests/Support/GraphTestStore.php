<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * An empty graph store on in-memory SQLite, for tests.
 *
 * The tables are written out rather than collected. The schema collector
 * reads EVERY resource model in the workspace, not this package's four, and
 * one of them asks SQLite for an AUTOINCREMENT on a non-integer key — so
 * collecting here fails for a reason that has nothing to do with the graph.
 * The price is that a column added to a graph resource model must be added
 * here too.
 */
final class GraphTestStore
{
    private function __construct(
        public readonly DatabaseAdapterInterface $adapter,
        public readonly GraphStorage $storage,
    ) {}

    public static function create(): self
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));

        $db = $orm->getAdapter();
        $db->execute(
            'CREATE TABLE graph_nodes (
                id TEXT PRIMARY KEY, type TEXT NOT NULL, fqcn TEXT NOT NULL, name TEXT NOT NULL,
                file TEXT NOT NULL, line INTEGER NOT NULL, end_line INTEGER NOT NULL DEFAULT 0,
                module TEXT NOT NULL DEFAULT \'\', metadata TEXT NOT NULL DEFAULT \'{}\',
                is_placeholder INTEGER NOT NULL DEFAULT 0
            )',
        );
        $db->execute(
            'CREATE TABLE graph_edges (
                id INTEGER PRIMARY KEY AUTOINCREMENT, source_id TEXT NOT NULL, target_id TEXT NOT NULL,
                type TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT \'{}\'
            )',
        );
        $db->execute(
            'CREATE TABLE graph_file_index (
                path TEXT PRIMARY KEY, content_hash TEXT NOT NULL, indexed_at INTEGER NOT NULL,
                module TEXT NOT NULL, line_count INTEGER NOT NULL, is_dirty INTEGER NOT NULL DEFAULT 0
            )',
        );
        $db->execute('CREATE TABLE graph_meta (meta_key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        return new self($db, new GraphStorage(
            $orm->getAdapter(),
            $orm->getTransactionManager(),
            $orm->getMapperRegistry(),
            $orm->getResourceModelHydrator(),
            $orm->getResourceModelMetadataRegistry(),
            $orm->getResourceModelRelationLoader(),
            $orm->getAggregateWriteEngine(),
        ));
    }
}
