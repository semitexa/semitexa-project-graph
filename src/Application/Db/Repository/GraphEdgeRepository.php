<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphEdgeResource;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;

final class GraphEdgeRepository
{
    private ResourceModelQuery $query;

    public function __construct(
        private readonly DatabaseAdapterInterface      $adapter,
        private readonly MapperRegistry                $mapperRegistry,
        private readonly ResourceModelHydrator         $hydrator,
        private readonly ResourceModelMetadataRegistry $metadataRegistry,
        private readonly ResourceModelRelationLoader   $relationLoader,
        private readonly AggregateWriteEngine          $writeEngine,
    ) {
        $this->query = new ResourceModelQuery(
            GraphEdgeResource::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    /** @return list<Edge> */
    public function findBySource(string $sourceId, ?EdgeType $type = null): array
    {
        $q = $this->newQuery()
            ->where(ColumnRef::for(GraphEdgeResource::class, 'source_id'), Operator::Equals, $sourceId);
        if ($type !== null) {
            $q->where(ColumnRef::for(GraphEdgeResource::class, 'type'), Operator::Equals, $type->value);
        }
        return $q->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    /** @return list<Edge> */
    public function findByTarget(string $targetId, ?EdgeType $type = null): array
    {
        $q = $this->newQuery()
            ->where(ColumnRef::for(GraphEdgeResource::class, 'target_id'), Operator::Equals, $targetId);
        if ($type !== null) {
            $q->where(ColumnRef::for(GraphEdgeResource::class, 'type'), Operator::Equals, $type->value);
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
        return $this->newQuery()
            ->where(ColumnRef::for(GraphEdgeResource::class, 'type'), Operator::Equals, $type->value)
            ->limit($limit)
            ->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    public function upsert(Edge $edge): void
    {
        $existing = $this->newQuery()
            ->where(ColumnRef::for(GraphEdgeResource::class, 'source_id'), Operator::Equals, $edge->sourceId)
            ->where(ColumnRef::for(GraphEdgeResource::class, 'target_id'), Operator::Equals, $edge->targetId)
            ->where(ColumnRef::for(GraphEdgeResource::class, 'type'), Operator::Equals, $edge->type->value)
            ->fetchOneAs(Edge::class, $this->mapperRegistry) ?: null;

        if ($existing !== null) {
            $updated = new Edge(
                id:       $existing->id,
                sourceId: $edge->sourceId,
                targetId: $edge->targetId,
                type:     $edge->type,
                metadata: $edge->metadata,
            );
            $this->writeEngine->update($updated, GraphEdgeResource::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($edge, GraphEdgeResource::class, $this->mapperRegistry);
        }
    }

    /** @return int count of deleted edges */
    public function deleteByNodeIds(array $nodeIds): int
    {
        if (empty($nodeIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $countResult = $this->adapter->execute(
            'SELECT COUNT(*) FROM graph_edges WHERE source_id IN (' . $placeholders . ') OR target_id IN (' . $placeholders . ')',
            [...$nodeIds, ...$nodeIds],
        );
        $count = (int) ($countResult->fetchColumn() ?? 0);

        $this->adapter->execute(
            'DELETE FROM graph_edges WHERE source_id IN (' . $placeholders . ') OR target_id IN (' . $placeholders . ')',
            [...$nodeIds, ...$nodeIds],
        );

        return $count;
    }

    public function countAll(): int
    {
        $result = $this->adapter->execute('SELECT COUNT(*) as cnt FROM graph_edges');
        return (int) ($result->fetchOne()['cnt'] ?? 0);
    }

    public function truncate(): void
    {
        $this->adapter->execute('DELETE FROM graph_edges');
    }

    private function newQuery(): ResourceModelQuery
    {
        return clone $this->query;
    }
}
