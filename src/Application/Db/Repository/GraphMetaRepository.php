<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\ProjectGraph\Application\Db\Model\GraphMetaTableModel;

final class GraphMetaRepository
{
    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly MapperRegistry $mapperRegistry,
        private readonly TableModelHydrator $hydrator,
        private readonly TableModelMetadataRegistry $metadataRegistry,
        private readonly TableModelRelationLoader $relationLoader,
        private readonly AggregateWriteEngine $writeEngine,
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
