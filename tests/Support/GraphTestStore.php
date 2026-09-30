<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphSchema;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/** An empty graph store on in-memory SQLite, for tests — the tables GraphSchema writes out. */
final class GraphTestStore
{
    private function __construct(
        public readonly DatabaseAdapterInterface $adapter,
        public readonly GraphStorage $storage,
    ) {}

    public static function create(): self
    {
        $orm = new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
        GraphSchema::create($orm->getAdapter());

        return new self($orm->getAdapter(), new GraphStorage(
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
