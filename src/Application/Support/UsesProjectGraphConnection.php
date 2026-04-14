<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Support;

use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;

trait UsesProjectGraphConnection
{
    private function createProjectGraphStorage(ConnectionRegistry $connections): GraphStorage
    {
        $orm = $this->projectGraphOrm($connections);
        $this->ensureProjectGraphSchema($orm);

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

    private function projectGraphOrm(ConnectionRegistry $connections): OrmManager
    {
        return ProjectGraphConnection::manager($connections, $this->getProjectRoot());
    }

    private function ensureProjectGraphSchema(OrmManager $orm): void
    {
        $collector = $orm->getSchemaCollector();
        $schema = $collector->collect();
        $errors = $collector->getErrors();

        if ($errors !== []) {
            throw new \RuntimeException('Project graph schema validation failed: ' . implode(' | ', $errors));
        }

        $diff = $orm->getSchemaComparator()->compare($schema);
        if ($diff->isEmpty()) {
            return;
        }

        $plan = $orm->getSyncEngine()->buildPlan($diff);
        $orm->getSyncEngine()->execute($plan, false);
    }
}
