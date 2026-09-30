<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\ProjectGraph\Application\Db\SQLite\Model\GraphEdgeResource;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;

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

    /**
     * Every edge of a type. No default limit: a cap here used to truncate
     * whole-graph answers with nothing telling the caller they were partial.
     * A caller that wants a page asks for one.
     *
     * @return list<Edge>
     */
    public function findByType(EdgeType $type, ?int $limit = null): array
    {
        $query = $this->newQuery()
            ->where(ColumnRef::for(GraphEdgeResource::class, 'type'), Operator::Equals, $type->value);

        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        return $query->fetchAllAs(Edge::class, $this->mapperRegistry);
    }

    /**
     * Insert the edge, or refresh its metadata when (source, target, type)
     * already exists. Plain SQL on purpose: this runs once per extracted edge
     * — ~94k times on a workspace full build — and the ORM path (hydrate the
     * existing row, then insert or update through the write engine) made the
     * build spend ~90% of its time here.
     */
    /** @return bool true when the edge was inserted, false when it existed and was refreshed */
    public function upsert(Edge $edge): bool
    {
        $key = [
            'source' => $edge->getSourceId(),
            'target' => $edge->getTargetId(),
            'type'   => $edge->getType()->value,
        ];
        $metadata = json_encode($edge->getMetadata()) ?: '{}';

        $existingId = $this->adapter->execute(
            'SELECT id FROM graph_edges WHERE source_id = :source AND target_id = :target AND type = :type LIMIT 1',
            $key,
        )->fetchColumn();

        if ($existingId !== false && $existingId !== null) {
            $this->adapter->execute('UPDATE graph_edges SET metadata = :metadata WHERE id = :id', ['metadata' => $metadata, 'id' => $existingId]);
            return false;
        }

        $this->adapter->execute(
            'INSERT INTO graph_edges (source_id, target_id, type, metadata) VALUES (:source, :target, :type, :metadata)',
            $key + ['metadata' => $metadata],
        );

        return true;
    }

    /** @return list<Edge> every edge, without the ORM */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $row): Edge => new Edge(
                sourceId: (string) $row['source_id'],
                targetId: (string) $row['target_id'],
                type:     EdgeType::from((string) $row['type']),
                metadata: json_decode((string) $row['metadata'], true) ?: [],
            ),
            $this->adapter->execute('SELECT source_id, target_id, type, metadata FROM graph_edges')->fetchAll(),
        ));
    }

    /**
     * Edges leaving any of the given nodes, without the ORM (runs once per
     * re-read file).
     *
     * @param list<string> $sourceIds
     * @return list<Edge>
     */
    public function findBySourceIds(array $sourceIds): array
    {
        if ($sourceIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $rows = $this->adapter->execute(
            'SELECT source_id, target_id, type, metadata FROM graph_edges WHERE source_id IN (' . $placeholders . ')',
            $sourceIds,
        )->fetchAll();

        return array_values(array_map(
            static fn (array $row): Edge => new Edge(
                sourceId: (string) $row['source_id'],
                targetId: (string) $row['target_id'],
                type:     EdgeType::from((string) $row['type']),
                metadata: json_decode((string) $row['metadata'], true) ?: [],
            ),
            $rows,
        ));
    }

    /**
     * Delete the edges LEAVING the given nodes — the ones their own file's
     * extraction emitted. Edges other files have into them are not touched:
     * those files are not being re-read, so nothing would put the edges back.
     *
     * @param list<string> $sourceIds
     * @return int count of deleted edges
     */
    public function deleteBySourceIds(array $sourceIds): int
    {
        if ($sourceIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $count = (int) ($this->adapter->execute(
            'SELECT COUNT(*) FROM graph_edges WHERE source_id IN (' . $placeholders . ')',
            $sourceIds,
        )->fetchColumn() ?? 0);

        $this->adapter->execute(
            'DELETE FROM graph_edges WHERE source_id IN (' . $placeholders . ')',
            $sourceIds,
        );

        return $count;
    }

    /**
     * Which of the given nodes some edge still points at.
     *
     * @param list<string> $nodeIds
     * @return list<string>
     */
    public function referencedAmong(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));
        $rows = $this->adapter->execute(
            'SELECT DISTINCT target_id FROM graph_edges WHERE target_id IN (' . $placeholders . ')',
            $nodeIds,
        )->fetchAll();

        return array_values(array_map(static fn (array $row): string => (string) $row['target_id'], $rows));
    }

    /**
     * Edges with at least one end inside $module.
     *
     * The alternative — reporting the graph-wide edge total next to a module's node count —
     * reads as a real measurement and is not one: a module with no nodes came out as zero
     * nodes and forty thousand edges.
     */
    public function countTouchingModule(string $module): int
    {
        $result = $this->adapter->execute(
            'SELECT COUNT(*) as cnt FROM graph_edges WHERE source_id IN '
                . '(SELECT id FROM graph_nodes WHERE module = :module) '
                . 'OR target_id IN (SELECT id FROM graph_nodes WHERE module = :module)',
            ['module' => $module],
        );

        return (int) ($result->fetchOne()['cnt'] ?? 0);
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
