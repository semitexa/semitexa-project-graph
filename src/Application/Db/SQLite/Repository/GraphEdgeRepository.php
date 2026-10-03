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

        $existing = $this->adapter->execute(
            'SELECT id, metadata FROM graph_edges WHERE source_id = :source AND target_id = :target AND type = :type LIMIT 1',
            $key,
        )->fetchOne();

        if (is_array($existing) && isset($existing['id'])) {
            $held = json_decode((string) ($existing['metadata'] ?? '{}'), true);
            $merged = self::mergeMetadata(is_array($held) ? $held : [], $edge->getMetadata());
            $this->adapter->execute('UPDATE graph_edges SET metadata = :metadata WHERE id = :id', ['metadata' => json_encode($merged) ?: '{}', 'id' => $existing['id']]);
            return false;
        }

        $this->adapter->execute(
            'INSERT INTO graph_edges (source_id, target_id, type, metadata) VALUES (:source, :target, :type, :metadata)',
            $key + ['metadata' => $metadata],
        );

        return true;
    }

    /** How strongly a reference proves a use; a bare Foo::class proves least. */
    private const VIA_STRENGTH = ['class_name' => 0, 'config' => 1];

    /**
     * Two edges of one type between the same two nodes are one row; their
     * metadata used to be overwritten by whichever came last. So the verdict
     * depended on statement order — `Util::make(); return Util::class;` read
     * as "named only as a class name" — and of two #[BelongsTo] relations to
     * one target only the last survived (measured 2026-10-02: 4 relations of
     * TenantRelationOrder stored as 1). Now order-independent: the variant
     * with the strongest `via` is the edge's metadata, the others are kept in
     * `also`, sorted.
     *
     * @param array<string, mixed> $held
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    public static function mergeMetadata(array $held, array $incoming): array
    {
        $variants = [];
        foreach ([$held, ...(is_array($held['also'] ?? null) ? $held['also'] : []), $incoming] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            unset($variant['also']);
            ksort($variant);
            $variants[(string) json_encode($variant)] = $variant;
        }
        // An empty variant says nothing a non-empty one does not: kept, it
        // sorted first and pushed every handles edge's `execution` into
        // `also` (round 2).
        if (count($variants) > 1) {
            unset($variants['[]']);
        }
        $strength = static fn (array $v): int => is_string($v['via'] ?? null) ? (self::VIA_STRENGTH[$v['via']] ?? 2) : 2;
        uksort($variants, static fn (string $a, string $b): int => [-$strength($variants[$a]), $a] <=> [-$strength($variants[$b]), $b]);
        $variants = array_values($variants);

        $primary = $variants[0] ?? [];
        if (count($variants) > 1) {
            $primary['also'] = array_slice($variants, 1);
        }

        return $primary;
    }

    /**
     * Every edge with what is known about its source node, page by page (the
     * adapter buffers a whole result, and ~67k joined rows decoded at once do
     * not fit in 128M). `via` is read out of the metadata by SQL: how a
     * reference was made, or where an attribute was applied.
     *
     * @return \Generator<int, array{type: string, source_id: string, target_id: string, via: ?string, source_file: string, source_declared: bool}>
     */
    public function withSources(int $pageSize = 5000): \Generator
    {
        $after = 0;
        do {
            $rows = $this->adapter->execute(
                "SELECT e.id, e.type, e.source_id, e.target_id, COALESCE(json_extract(e.metadata, '$.via'), json_extract(e.metadata, '$.target')) AS via,"
                . ' n.file AS source_file, n.is_placeholder AS source_placeholder'
                . ' FROM graph_edges e LEFT JOIN graph_nodes n ON n.id = e.source_id'
                . ' WHERE e.id > :after ORDER BY e.id LIMIT ' . $pageSize,
                ['after' => $after],
            )->fetchAll();

            foreach ($rows as $row) {
                $after = (int) $row['id'];
                yield [
                    'type'            => (string) $row['type'],
                    'source_id'       => (string) $row['source_id'],
                    'target_id'       => (string) $row['target_id'],
                    'via'             => $row['via'] !== null ? (string) $row['via'] : null,
                    'source_file'     => (string) ($row['source_file'] ?? ''),
                    'source_declared' => $row['source_file'] !== null && (int) $row['source_placeholder'] === 0,
                ];
            }
        } while (count($rows) === $pageSize);
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
     * The edges ARRIVING at the given nodes — the reverse of findBySourceIds().
     *
     * @param list<string> $targetIds
     * @return list<Edge>
     */
    public function findByTargetIds(array $targetIds): array
    {
        $edges = [];
        foreach (array_chunk(array_values(array_unique($targetIds)), 500) as $chunk) {
            [$in, $params] = self::named('t', $chunk);
            $rows = $this->adapter->execute(
                'SELECT source_id, target_id, type, metadata FROM graph_edges WHERE target_id IN (' . $in . ')',
                $params,
            )->fetchAll();
            foreach ($rows as $row) {
                $type = EdgeType::tryFrom(self::text($row['type'] ?? null));
                if ($type === null) {
                    continue;
                }
                $metadata = json_decode(self::text($row['metadata'] ?? null), true);
                $edges[] = new Edge(
                    sourceId: self::text($row['source_id'] ?? null),
                    targetId: self::text($row['target_id'] ?? null),
                    type:     $type,
                    metadata: is_array($metadata) ? $metadata : [],
                );
            }
        }

        return $edges;
    }

    /**
     * How many edges arrive at each node, leaving out the given kinds — a node's
     * fan-in across the whole graph, not just the part a view has loaded.
     *
     * @param list<string> $targetIds
     * @param list<string> $excludeTypes edge type values not to count
     * @return array<string, int> keyed by target id; nodes nothing reaches are absent
     */
    public function countInboundByTarget(array $targetIds, array $excludeTypes = []): array
    {
        [$notIn, $excluded] = self::named('x', $excludeTypes);
        $counts = [];
        foreach (array_chunk(array_values(array_unique($targetIds)), 500) as $chunk) {
            [$in, $params] = self::named('t', $chunk);
            $sql = 'SELECT target_id, COUNT(*) AS c FROM graph_edges WHERE target_id IN (' . $in . ')'
                . ($excluded !== [] ? ' AND type NOT IN (' . $notIn . ')' : '')
                . ' GROUP BY target_id';
            foreach ($this->adapter->execute($sql, $params + $excluded)->fetchAll() as $row) {
                $c = $row['c'] ?? 0;
                $counts[self::text($row['target_id'] ?? null)] = is_numeric($c) ? (int) $c : 0;
            }
        }

        return $counts;
    }

    /**
     * Named placeholders for an IN list: `:t0,:t1` and the matching params.
     *
     * @param list<string> $values
     * @return array{0: string, 1: array<string, string>}
     */
    private static function named(string $prefix, array $values): array
    {
        $params = [];
        foreach ($values as $i => $value) {
            $params[$prefix . $i] = $value;
        }

        return [implode(',', array_map(static fn (string $k): string => ':' . $k, array_keys($params))), $params];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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

    /**
     * Edges of these types between two declared modules, optionally from
     * module $from and/or into module $to.
     *
     * @param list<string> $types
     * @return list<Edge>
     */
    public function crossModule(array $types, ?string $from = null, ?string $to = null): array
    {
        [$in, $params] = self::named('k', $types);
        $sql = 'SELECT e.source_id, e.target_id, e.type, e.metadata FROM graph_edges e'
            . ' JOIN graph_nodes s ON s.id = e.source_id JOIN graph_nodes t ON t.id = e.target_id'
            . " WHERE e.type IN (" . $in . ") AND s.module != '' AND t.module != '' AND s.module != t.module";
        if ($from !== null) {
            $sql .= ' AND s.module = :from';
            $params['from'] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND t.module = :to';
            $params['to'] = $to;
        }
        $sql .= ' ORDER BY e.id';

        return array_values(array_map(
            static fn (array $row): Edge => new Edge(
                sourceId: (string) $row['source_id'],
                targetId: (string) $row['target_id'],
                type:     EdgeType::from((string) $row['type']),
                metadata: json_decode((string) $row['metadata'], true) ?: [],
            ),
            $this->adapter->execute($sql, $params)->fetchAll(),
        ));
    }

    /**
     * Files whose declared nodes read a constant of one of these classes
     * (a `references` edge with via "constant", as the edge or in `also`).
     * In SQL: collecting the edges first cost a full build 26 MB.
     *
     * @param list<string> $targetIds
     * @return list<string>
     */
    public function sourceFilesReadingConstantsOf(array $targetIds): array
    {
        $files = [];
        foreach (array_chunk(array_values(array_unique($targetIds)), 500) as $chunk) {
            [$in, $params] = self::named('t', $chunk);
            $rows = $this->adapter->execute(
                "SELECT DISTINCT n.file AS file FROM graph_edges e JOIN graph_nodes n ON n.id = e.source_id"
                . " WHERE e.type = 'references' AND e.target_id IN (" . $in . ") AND e.metadata LIKE '%\"constant\"%'"
                . " AND n.file != '' AND n.is_placeholder = 0",
                $params,
            )->fetchAll();
            foreach ($rows as $row) {
                $files[self::text($row['file'] ?? null)] = true;
            }
        }
        unset($files['']);

        return array_keys($files);
    }

    /**
     * Files of declared nodes with an edge into $nodeId, first few by path.
     *
     * @return list<string>
     */
    public function sourceFilesPointingAt(string $nodeId, int $limit): array
    {
        $rows = $this->adapter->execute(
            'SELECT DISTINCT n.file AS file FROM graph_edges e JOIN graph_nodes n ON n.id = e.source_id'
            . " WHERE e.target_id = :id AND n.file != '' AND n.is_placeholder = 0 ORDER BY n.file LIMIT " . max(1, $limit),
            ['id' => $nodeId],
        )->fetchAll();

        return array_values(array_filter(array_map(static fn (array $r): string => self::text($r['file'] ?? null), $rows)));
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
