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
use Semitexa\ProjectGraph\Application\Db\Model\GraphEdgeTableModel;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;

final class GraphEdgeRepository
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
            GraphEdgeTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    /** @return list<Edge> */
    public function findBySource(string $sourceId, ?EdgeType $type = null): array
    {
        $q = $this->query->clone()
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'source_id'), Operator::Equals, $sourceId);
        if ($type !== null) {
            $q->where(ColumnRef::for(GraphEdgeTableModel::class, 'type'), Operator::Equals, $type->value);
        }
        return $q->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    /** @return list<Edge> */
    public function findByTarget(string $targetId, ?EdgeType $type = null): array
    {
        $q = $this->query->clone()
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'target_id'), Operator::Equals, $targetId);
        if ($type !== null) {
            $q->where(ColumnRef::for(GraphEdgeTableModel::class, 'type'), Operator::Equals, $type->value);
        }
        return $q->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    /** @return list<Edge> */
    public function findByNode(string $nodeId): array
    {
        $outgoing = $this->findBySource($nodeId);
        $incoming = $this->findByTarget($nodeId);
        return array_merge($outgoing, $incoming);
    }

    /** @return list<Edge> */
    public function findByType(EdgeType $type, int $limit = 1000): array
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'type'), Operator::Equals, $type->value)
            ->limit($limit)
            ->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    public function upsert(Edge $edge): void
    {
        $existing = $this->query->clone()
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'source_id'), Operator::Equals, $edge->sourceId)
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'target_id'), Operator::Equals, $edge->targetId)
            ->where(ColumnRef::for(GraphEdgeTableModel::class, 'type'), Operator::Equals, $edge->type->value)
            ->fetchOneAs(Edge::class, $this->mapperRegistry) ?: null;

        if ($existing !== null) {
            $updated = new Edge(
                id:       $existing->id,
                sourceId: $edge->sourceId,
                targetId: $edge->targetId,
                type:     $edge->type,
                metadata: $edge->metadata,
            );
            $this->writeEngine->update($updated, GraphEdgeTableModel::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($edge, GraphEdgeTableModel::class, $this->mapperRegistry);
        }
    }

    /** @return int count of deleted edges */
    public function deleteByNodeIds(array $nodeIds): int
    {
        if (empty($nodeIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $count = (int) $this->adapter->execute(
            'SELECT COUNT(*) FROM graph_edges WHERE source_id IN (' . $placeholders . ') OR target_id IN (' . $placeholders . ')',
            [...$nodeIds, ...$nodeIds],
        )[0][0] ?? 0;

        $this->adapter->execute(
            'DELETE FROM graph_edges WHERE source_id IN (' . $placeholders . ') OR target_id IN (' . $placeholders . ')',
            [...$nodeIds, ...$nodeIds],
        );

        return $count;
    }

    public function countAll(): int
    {
        $result = $this->adapter->execute('SELECT COUNT(*) as cnt FROM graph_edges');
        return (int) ($result[0]['cnt'] ?? 0);
    }

    public function truncate(): void
    {
        $this->adapter->execute('DELETE FROM graph_edges');
    }
}
