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
use Semitexa\ProjectGraph\Application\Db\SQLite\Model\GraphNodeResource;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;

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

    /**
     * Many nodes in one round trip per 500 ids — a view that expands a node
     * would otherwise pay one query per neighbour.
     *
     * @param list<string> $ids
     * @return array<string, Node> keyed by id; ids the graph does not hold are absent
     */
    public function findByIds(array $ids): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $nodes = $this->newQuery()
                ->whereIn(ColumnRef::for(GraphNodeResource::class, 'id'), $chunk)
                ->fetchAllAs(Node::class, $this->mapperRegistry);
            foreach ($nodes as $node) {
                if ($node instanceof Node) {
                    $found[$node->getId()] = $node;
                }
            }
        }

        return $found;
    }

    /**
     * Every node — for an export of the whole graph, not for a request path.
     *
     * @return list<Node>
     */
    public function all(): array
    {
        return array_values(array_filter(
            $this->newQuery()->fetchAllAs(Node::class, $this->mapperRegistry),
            static fn (object $node): bool => $node instanceof Node,
        ));
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
            if (!$node->getIsPlaceholder() && str_starts_with($node->getId(), 'class:')) {
                return $node;
            }
        }

        foreach ($candidates as $node) {
            if (!$node->getIsPlaceholder()) {
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

    /**
     * Name or FQCN containing $text LITERALLY (`%` and `_` are escaped), real
     * nodes first and name-prefix matches next, ranked in SQL before the limit
     * so placeholders cannot crowd real classes out of the page.
     *
     * @return list<Node>
     */
    /**
     * @param list<string> $excludeTypes node types left out IN the query: filtering
     *        them after the LIMIT let fifty hidden nodes crowd every visible match out
     */
    public function searchText(string $text, int $limit = 50, array $excludeTypes = [], ?string $module = null, ?string $type = null): array
    {
        $like = '%' . strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $prefix = substr($like, 1);
        $params = ['like' => $like, 'prefix' => $prefix];
        if ($module !== null) {
            $params['module'] = $module;
        }
        if ($type !== null) {
            $params['type'] = $type;
        }
        $exclude = '';
        foreach (array_values($excludeTypes) as $i => $excluded) {
            $params['x' . $i] = $excluded;
            $exclude .= ($exclude === '' ? '' : ', ') . ':x' . $i;
        }
        $rows = $this->adapter->execute(
            "SELECT id FROM graph_nodes WHERE (name LIKE :like ESCAPE '\\' OR fqcn LIKE :like ESCAPE '\\')"
            . ($exclude === '' ? '' : ' AND type NOT IN (' . $exclude . ')')
            . ($module === null ? '' : ' AND module = :module')
            . ($type === null ? '' : ' AND type = :type')
            . " ORDER BY is_placeholder, CASE WHEN name LIKE :prefix ESCAPE '\\' THEN 0 ELSE 1 END, name LIMIT " . max(1, $limit),
            $params,
        )->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            if (is_scalar($row['id'] ?? null)) {
                $ids[] = (string) $row['id'];
            }
        }
        $nodes = $this->findByIds($ids);

        return array_values(array_filter(array_map(static fn (string $id): ?Node => $nodes[$id] ?? null, $ids)));
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
            $merged[$node->getId()] = $node;
        }
        return array_slice(array_values($merged), 0, $limit);
    }

    public function upsert(Node $node): void
    {
        $existing = $this->findById($node->getId());
        if ($existing !== null) {
            $this->writeEngine->update($node, GraphNodeResource::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($node, GraphNodeResource::class, $this->mapperRegistry);
        }
    }

    /**
     * fqcn, type and placeholder flag of every class-like node, without
     * hydrating them.
     *
     * @return list<array{fqcn: string, type: string, placeholder: bool}>
     */
    public function classLikeFqcns(): array
    {
        $rows = $this->adapter->execute("SELECT fqcn, type, is_placeholder FROM graph_nodes WHERE id LIKE 'class:%'")->fetchAll();

        return array_values(array_map(
            static fn (array $row): array => [
                'fqcn'        => (string) $row['fqcn'],
                'type'        => (string) $row['type'],
                'placeholder' => (int) $row['is_placeholder'] === 1,
            ],
            $rows,
        ));
    }

    /**
     * Every declared (non-placeholder) class-like node, without hydrating.
     *
     * @return list<array{id: string, fqcn: string, type: string, file: string, line: int, module: string}>
     */
    public function declaredClasses(): array
    {
        $rows = $this->adapter->execute(
            "SELECT id, fqcn, type, file, line, module FROM graph_nodes WHERE id LIKE 'class:%' AND is_placeholder = 0 ORDER BY id",
        )->fetchAll();

        return array_values(array_map(
            static fn (array $row): array => [
                'id'     => (string) $row['id'],
                'fqcn'   => (string) $row['fqcn'],
                'type'   => (string) $row['type'],
                'file'   => (string) $row['file'],
                'line'   => (int) $row['line'],
                'module' => (string) $row['module'],
            ],
            $rows,
        ));
    }

    public function exists(string $id): bool
    {
        return $this->adapter->execute('SELECT 1 FROM graph_nodes WHERE id = :id LIMIT 1', ['id' => $id])->fetchColumn() !== false;
    }

    /** A node known only because an edge points at it. Plain SQL: runs once per edge target. */
    public function existsDeclared(string $nodeId): bool
    {
        return $this->adapter->execute(
            'SELECT 1 FROM graph_nodes WHERE id = :id AND is_placeholder = 0 LIMIT 1',
            ['id' => $nodeId],
        )->fetchColumn() !== false;
    }

    /**
     * "resource" is a role another file gives a class (a handler's
     * `produces`). It stuck after the handler stopped naming it, and a
     * placeholder kept it after the class was deleted (round 2). The role
     * holds exactly while a `produces` edge points at the node; otherwise a
     * declaration returns to the type it declared and a placeholder to the
     * type its id implies.
     */
    public function reconcileResourceRoles(): void
    {
        // And the other way: a declared class something `produces` IS a
        // resource, however the files were read. A handler file that also
        // declared a copy of the class lost the role in a full build and kept
        // it in a refresh (round-4 fuzzing, seed 3160).
        $promote = $this->adapter->execute(
            "SELECT id, type, metadata FROM graph_nodes WHERE is_placeholder = 0 AND type IN ('class', 'interface', 'trait', 'enum')"
            . " AND EXISTS (SELECT 1 FROM graph_edges e WHERE e.target_id = graph_nodes.id AND e.type = 'produces')",
        )->fetchAll();
        foreach ($promote as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $meta['declared_type'] = (string) $row['type'];
            $this->adapter->execute('UPDATE graph_nodes SET type = :type, metadata = :metadata WHERE id = :id', [
                'type' => NodeType::Resource->value,
                'metadata' => (string) json_encode($meta),
                'id' => (string) $row['id'],
            ]);
        }

        $rows = $this->adapter->execute(
            "SELECT id, is_placeholder, metadata FROM graph_nodes WHERE type = 'resource'"
            . " AND NOT EXISTS (SELECT 1 FROM graph_edges e WHERE e.target_id = graph_nodes.id AND e.type = 'produces')",
        )->fetchAll();
        foreach ($rows as $row) {
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            $declared = is_array($meta) && is_string($meta['declared_type'] ?? null) ? $meta['declared_type'] : null;
            if ((int) $row['is_placeholder'] === 1) {
                $type = NodeType::forPlaceholderId((string) $row['id'])->value;
            } elseif ($declared !== null) {
                $type = $declared;
            } else {
                continue; // declared as a resource by its own file
            }
            // The bookkeeping goes with the role: a full build of the same tree
            // never wrote it.
            if (is_array($meta)) {
                unset($meta['declared_type']);
            }
            $this->adapter->execute('UPDATE graph_nodes SET type = :type, metadata = :metadata WHERE id = :id', [
                'type' => $type,
                // Encoded as the mapper encodes it, so it compares byte for byte.
                'metadata' => (int) $row['is_placeholder'] === 1 ? '[]' : (string) json_encode(is_array($meta) ? $meta : []),
                'id' => (string) $row['id'],
            ]);
        }
    }

    public function insertPlaceholder(string $nodeId): void
    {
        if ($this->exists($nodeId)) {
            return;
        }

        $fqcn = NodeId::extractFqcn($nodeId);
        $slash = strrpos($fqcn, '\\');
        $this->adapter->execute(
            'INSERT INTO graph_nodes (id, type, fqcn, name, file, line, end_line, module, metadata, is_placeholder)'
            . " VALUES (:id, :type, :fqcn, :name, '', 0, 0, '', '[]', 1)",
            [
                'id'   => $nodeId,
                'type' => NodeType::forPlaceholderId($nodeId)->value,
                'fqcn' => $fqcn,
                'name' => $slash === false ? $fqcn : substr($fqcn, $slash + 1),
            ],
        );
    }

    /**
     * @param list<string> $ids
     * @return int count of deleted nodes
     */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $count = (int) ($this->adapter->execute(
            'SELECT COUNT(*) as cnt FROM graph_nodes WHERE id IN (' . $placeholders . ')',
            $ids,
        )->fetchOne()['cnt'] ?? 0);

        $this->adapter->execute('DELETE FROM graph_nodes WHERE id IN (' . $placeholders . ')', $ids);

        return $count;
    }

    /**
     * Turn nodes whose file no longer declares them, but which edges still
     * point at, into placeholders: the dangling reference stays visible
     * instead of vanishing with its target. The type is kept — a dropped
     * route stays a route.
     *
     * @param list<string> $ids
     */
    public function demoteToPlaceholders(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Back to exactly what insertPlaceholder() writes, with one exception:
        // a type another file's edge still implies. The declared role ("event"
        // from #[AsEvent], "payload") used to survive the declaration, so a
        // class that lost its attribute kept the role a full build drops.
        // A handler's `produces` edge is the one mention that implies a role.
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $produced = [];
            foreach ($this->adapter->execute("SELECT DISTINCT target_id FROM graph_edges WHERE type = 'produces' AND target_id IN (" . $in . ')', $chunk)->fetchAll() as $row) {
                $produced[(string) $row['target_id']] = true;
            }
            foreach ($chunk as $id) {
                // fqcn and name as insertPlaceholder() writes them: a demoted
                // flow node kept "" where a fresh build has its name (round 2).
                $fqcn = NodeId::extractFqcn($id);
                $slash = strrpos($fqcn, '\\');
                $this->adapter->execute(
                    "UPDATE graph_nodes SET is_placeholder = 1, type = :type, fqcn = :fqcn, name = :name, file = '', line = 0, end_line = 0, module = '', metadata = '[]' WHERE id = :id",
                    [
                        'id' => $id,
                        'type' => isset($produced[$id]) ? NodeType::Resource->value : NodeType::forPlaceholderId($id)->value,
                        'fqcn' => $fqcn,
                        'name' => $slash === false ? $fqcn : substr($fqcn, $slash + 1),
                    ],
                );
            }
        }
    }

    /** @return int count of placeholders deleted because no edge points at them any more */
    public function deleteUnreferencedPlaceholders(): int
    {
        $where = 'is_placeholder = 1 AND NOT EXISTS (SELECT 1 FROM graph_edges e WHERE e.target_id = graph_nodes.id)';
        $count = (int) ($this->adapter->execute('SELECT COUNT(*) as cnt FROM graph_nodes WHERE ' . $where)
            ->fetchOne()['cnt'] ?? 0);

        if ($count > 0) {
            $this->adapter->execute('DELETE FROM graph_nodes WHERE ' . $where);
        }

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

    /**
     * Every module that currently owns at least one node.
     *
     * Counting modules is not the same question as listing nodes, and the difference used to
     * be paid in full: the only way to learn the module set was to pull every node object out
     * of the database and collect the field. One GROUP BY answers it.
     *
     * @return list<string>
     */
    public function distinctModules(): array
    {
        $rows = $this->adapter->execute(
            "SELECT DISTINCT module FROM graph_nodes WHERE module IS NOT NULL AND module != ''",
        )->fetchAll();

        return array_values(array_map(static fn (array $r): string => (string) $r['module'], $rows));
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
