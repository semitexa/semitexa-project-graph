<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;

final class GraphMetaRepository
{
    public function __construct(
        private readonly DatabaseAdapterInterface      $adapter,
        private readonly MapperRegistry                $mapperRegistry,
        private readonly ResourceModelHydrator         $hydrator,
        private readonly ResourceModelMetadataRegistry $metadataRegistry,
        private readonly ResourceModelRelationLoader   $relationLoader,
        private readonly AggregateWriteEngine          $writeEngine,
    ) {}

    public function get(string $key): ?string
    {
        $result = $this->adapter->execute(
            'SELECT value FROM graph_meta WHERE meta_key = :key',
            ['key' => $key],
        );

        return $result->fetchOne()['value'] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $updated = $this->adapter->execute(
            'UPDATE graph_meta SET value = :value WHERE meta_key = :key',
            ['key' => $key, 'value' => $value],
        );

        if ($updated->rowCount > 0) {
            return;
        }

        $this->adapter->execute(
            'INSERT INTO graph_meta (meta_key, value) VALUES (:key, :value)',
            ['key' => $key, 'value' => $value],
        );
    }

    public function truncate(): void
    {
        // What the build derives goes; what a person recorded stays. A full
        // build used to erase `ai:review-graph:diff`'s baseline with the rest,
        // so the next diff said "previous scan: never".
        // A prefix compare, not LIKE: `_` is a LIKE wildcard, so the pattern
        // also kept keys like 'graphXdiffXlastXscan'.
        $this->adapter->execute("DELETE FROM graph_meta WHERE substr(meta_key, 1, 20) <> 'graph_diff_last_scan'");
    }
}
