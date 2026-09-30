<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * A graph store of its own, apart from the project graph: in memory, or in a
 * SQLite file that is created empty. Used to build a graph of one git ref
 * without touching the project's.
 */
final class EphemeralGraph
{
    public static function inMemory(): GraphStorage
    {
        return self::open(new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
    }

    private static function open(ConnectionConfig $config): GraphStorage
    {
        $orm = new OrmManager(config: $config);
        GraphSchema::create($orm->getAdapter());

        return new GraphStorage(
            $orm->getAdapter(),
            $orm->getTransactionManager(),
            $orm->getMapperRegistry(),
            $orm->getResourceModelHydrator(),
            $orm->getResourceModelMetadataRegistry(),
            $orm->getResourceModelRelationLoader(),
            $orm->getAggregateWriteEngine(),
        );
    }
}
