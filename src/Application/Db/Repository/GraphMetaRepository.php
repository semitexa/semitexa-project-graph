<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\Operator;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\TableModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphMetaTableModel;

final class GraphMetaRepository
{
    private TableModelQuery $query;

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly MapperRegistry $mapperRegistry,
        private readonly TableModelHydrator $hydrator,
        private readonly TableModelMetadataRegistry $metadataRegistry,
        private readonly TableModelRelationLoader $relationLoader,
        private readonly AggregateWriteEngine $writeEngine,
    ) {
        $this->query = new TableModelQuery(
            GraphMetaTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    public function get(string $key): ?string
    {
        $result = $this->adapter->execute(
            'SELECT value FROM graph_meta WHERE key = :key',
            ['key' => $key],
        );
        return $result[0]['value'] ?? null;
    }

    public function set(string $key, string $value): void
    {
        $this->adapter->execute(
            'INSERT INTO graph_meta (key, value) VALUES (:key, :value) ON CONFLICT(key) DO UPDATE SET value = :value',
            ['key' => $key, 'value' => $value],
        );
    }

    public function truncate(): void
    {
        $this->adapter->execute('DELETE FROM graph_meta');
    }
}
