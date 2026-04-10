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
use Semitexa\Orm\Query\Direction;
use Semitexa\Orm\Query\TableModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphNodeTableModel;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

final class GraphNodeRepository
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
            GraphNodeTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    public function findById(string $id): ?Node
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'id'), Operator::Equals, $id)
            ->fetchOneAs(Node::class, $this->mapperRegistry) ?: null;
    }

    public function findByFqcn(string $fqcn): ?Node
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'fqcn'), Operator::Equals, $fqcn)
            ->fetchOneAs(Node::class, $this->mapperRegistry) ?: null;
    }

    /** @return list<Node> */
    public function findByFile(string $filePath): array
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'file'), Operator::Equals, $filePath)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function findByType(string $type, ?string $module = null): array
    {
        $q = $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'type'), Operator::Equals, $type);
        if ($module !== null && $module !== '') {
            $q->where(ColumnRef::for(GraphNodeTableModel::class, 'module'), Operator::Equals, $module);
        }
        return $q->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function findByModule(string $module): array
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'module'), Operator::Equals, $module)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function search(string $pattern, int $limit = 20): array
    {
        return $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'name'), Operator::Like, '%' . $pattern . '%')
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry);
    }

    /** @return list<Node> */
    public function searchFull(string $query, int $limit = 20): array
    {
        $byName = $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'name'), Operator::Like, '%' . $query . '%')
            ->limit($limit)
            ->fetchAllAs(Node::class, $this->mapperRegistry);

        $byFqcn = $this->query->clone()
            ->where(ColumnRef::for(GraphNodeTableModel::class, 'fqcn'), Operator::Like, '%' . $query . '%')
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
            $this->writeEngine->update($node, GraphNodeTableModel::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($node, GraphNodeTableModel::class, $this->mapperRegistry);
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
        $this->writeEngine->insert($placeholder, GraphNodeTableModel::class, $this->mapperRegistry);
    }

    /** @return int count of deleted nodes */
    public function deleteByFile(string $filePath): int
    {
        $count = (int) $this->adapter->execute(
            'SELECT COUNT(*) as cnt FROM graph_nodes WHERE file = :file',
            ['file' => $filePath],
        )[0]['cnt'] ?? 0;

        $this->adapter->execute(
            'DELETE FROM graph_nodes WHERE file = :file',
            ['file' => $filePath],
        );

        return $count;
    }

    public function countAll(): int
    {
        $result = $this->adapter->execute('SELECT COUNT(*) as cnt FROM graph_nodes');
        return (int) ($result[0]['cnt'] ?? 0);
    }

    /** @return array<string, int> type => count */
    public function countByType(): array
    {
        $rows = $this->adapter->execute('SELECT type, COUNT(*) as cnt FROM graph_nodes GROUP BY type');
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
        );
        return array_map(fn($r) => $r['id'], $rows);
    }
}
