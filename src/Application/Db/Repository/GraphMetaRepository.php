<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\ProjectGraph\Application\Db\Model\GraphMetaResource;

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
        $this->adapter->execute('DELETE FROM graph_meta');
    }
}
