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
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphNodeResource;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

final class GraphNodeRepository
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
            GraphNodeResource::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    public function findById(string $id): ?Node
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'id'), Operator::Equals, $id)
            ->fetchOneAs(Node::class, $this->mapperRegistry) ?: null;
    }

    public function findByFqcn(string $fqcn): ?Node
    {
        $candidates = $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'fqcn'), Operator::Equals, $fqcn)
            ->fetchAllAs(Node::class, $this->mapperRegistry);

        if (empty($candidates)) {
            return null;
        }

        foreach ($candidates as $node) {
            if (!$node->isPlaceholder && str_starts_with($node->id, 'class:')) {
                return $node;
            }
        }

        foreach ($candidates as $node) {
            if (!$node->isPlaceholder) {
                return $node;
            }
        }

        return $candidates[0];
    }

    /** @return list<Node> */
    public function findByFile(string $filePath): array
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'file'), Operator::Equals, $filePath)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function findByType(string $type, ?string $module = null): array
    {
        $q = $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'type'), Operator::Equals, $type);
        if ($module !== null && $module !== '') {
            $q->where(ColumnRef::for(GraphNodeResource::class, 'module'), Operator::Equals, $module);
        }
        return $q->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function findByModule(string $module): array
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'module'), Operator::Equals, $module)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function search(string $pattern, int $limit = 20): array
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'name'), Operator::Like, '%' . $pattern . '%')
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function searchFull(string $query, int $limit = 20): array
    {
        $byName = $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'name'), Operator::Like, '%' . $query . '%')
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry);

        $byFqcn = $this->newQuery()
            ->where(ColumnRef::for(GraphNodeResource::class, 'fqcn'), Operator::Like, '%' . $query . '%')
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry);

        $merged = [];
        foreach ([...$byName, ...$byFqcn] as $node) {
            $merged[$node->id] = $node;
        }
        return array_slice(array_values($merged), 0, $limit);
    }

    public function upsert(Node $node): void
    {
        $existing = $this->findById($node->id);
        if ($existing !== null) {
            $this->writeEngine->update($node, GraphNodeResource::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($node, GraphNodeResource::class, $this->mapperRegistry);
        }
    }

    public function insertPlaceholder(string $nodeId): void
    {
        if ($this->findById($nodeId) !== null) {
            return;
        }

        $placeholder = new Node(
            id:            $nodeId,
            type:          NodeType::Class_,
            fqcn:          NodeId::extractFqcn($nodeId),
            file:          '',
            line:          0,
            endLine:       0,
            module:        '',
            metadata:      [],
            isPlaceholder: true,
        );
        $this->writeEngine->insert($placeholder, GraphNodeResource::class, $this->mapperRegistry);
    }

    /** @return int count of deleted nodes */
    public function deleteByFile(string $filePath): int
    {
        $countResult = $this->adapter->execute(
            'SELECT COUNT(*) as cnt FROM graph_nodes WHERE file = :file',
            ['file' => $filePath],
        );
        $count = (int) ($countResult->fetchOne()['cnt'] ?? 0);

        $this->adapter->execute(
            'DELETE FROM graph_nodes WHERE file = :file',
            ['file' => $filePath],
        );

        return $count;
    }

    public function countAll(): int
    {
        $result = $this->adapter->execute('SELECT COUNT(*) as cnt FROM graph_nodes');
        return (int) ($result->fetchOne()['cnt'] ?? 0);
    }

    /** @return array<string, int> type => count */
    public function countByType(): array
    {
        $rows = $this->adapter->execute('SELECT type, COUNT(*) as cnt FROM graph_nodes GROUP BY type')->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['cnt'];
        }
        return $counts;
    }

    public function truncate(): void
    {
        $this->adapter->execute('DELETE FROM graph_nodes');
    }

    /** @return list<string> */
    public function getNodeIdsByFile(string $filePath): array
    {
        $rows = $this->adapter->execute(
            'SELECT id FROM graph_nodes WHERE file = :file',
            ['file' => $filePath],
        )->fetchAll();
        return array_map(fn($r) => $r['id'], $rows);
    }

    private function newQuery(): ResourceModelQuery
    {
        return clone $this->query;
    }
}
