<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;

/**
 * Questions about the node table answered in SQL, without hydrating nodes:
 * name lookups for the resolver, the whole-graph census, and shared nodes a
 * refresh must repair. Split out of GraphNodeRepository, which crossed the
 * 30-method outlier line when they were added (2026-10-02).
 */
final class GraphNodeLookupRepository
{
    /** Node kinds a file DECLARES that more than one file may emit. */
    private const SHARED_DECLARED_PREFIXES = ['route', 'flow', 'domain', 'subject', 'hotspot'];

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
    ) {}

    /**
     * The whole graph's census, counted in SQL: loading 8k nodes and 70k edges
     * to count them exhausted 128M (`ai:review-graph:show` with no filter).
     *
     * @return array{node_types: array<string, int>, edge_types: array<string, int>, modules: array<string, int>, cross_module: int, placeholders: int, orphans: int}
     */
    public function census(): array
    {
        $pairs = function (string $sql): array {
            $out = [];
            foreach ($this->adapter->execute($sql)->fetchAll() as $row) {
                $out[(string) $row['k']] = (int) $row['c'];
            }
            arsort($out);

            return $out;
        };
        $one = fn (string $sql): int => (int) ($this->adapter->execute($sql)->fetchOne()['c'] ?? 0);

        return [
            'node_types'   => $pairs('SELECT type AS k, COUNT(*) AS c FROM graph_nodes GROUP BY type'),
            'edge_types'   => $pairs('SELECT type AS k, COUNT(*) AS c FROM graph_edges GROUP BY type'),
            'modules'      => $pairs("SELECT module AS k, COUNT(*) AS c FROM graph_nodes WHERE module IS NOT NULL AND module != '' GROUP BY module"),
            'cross_module' => $one("SELECT COUNT(*) AS c FROM graph_edges e JOIN graph_nodes s ON s.id = e.source_id JOIN graph_nodes t ON t.id = e.target_id WHERE s.module != '' AND t.module != '' AND s.module != t.module"),
            'placeholders' => $one('SELECT COUNT(*) AS c FROM graph_nodes WHERE is_placeholder = 1'),
            'orphans'      => $one("SELECT COUNT(*) AS c FROM graph_nodes n WHERE n.type != 'route' AND NOT EXISTS (SELECT 1 FROM graph_edges WHERE source_id = n.id) AND NOT EXISTS (SELECT 1 FROM graph_edges WHERE target_id = n.id)"),
        ];
    }

    /**
     * Ids of nodes whose FQCN equals $fqcn ignoring ASCII case (PHP class names
     * are case-insensitive), declared ones first.
     *
     * @return list<string>
     */
    public function idsByFqcnIgnoringCase(string $fqcn): array
    {
        $rows = $this->adapter->execute(
            'SELECT id FROM graph_nodes WHERE lower(fqcn) = lower(:fqcn) ORDER BY is_placeholder, id',
            ['fqcn' => $fqcn],
        )->fetchAll();

        return array_values(array_map(static fn (array $r): string => (string) $r['id'], $rows));
    }

    /**
     * Declared classes whose short name is $name — for a person who typed
     * `DemoItemCreated`, which is only an answer when exactly one class has it.
     *
     * @return list<string>
     */
    public function idsOfDeclaredClassesNamed(string $name): array
    {
        $rows = $this->adapter->execute(
            // PHP class names are case-insensitive: `usersignedup` is UserSignedUp.
            "SELECT id FROM graph_nodes WHERE lower(name) = lower(:name) AND id LIKE 'class:%' AND is_placeholder = 0 ORDER BY id",
            ['name' => $name],
        )->fetchAll();

        return array_values(array_map(static fn (array $r): string => (string) $r['id'], $rows));
    }

    /**
     * Shared nodes left a placeholder while a declared node still points at
     * them — their declaring file went, and another file that emits them was
     * never asked again.
     *
     * @return list<string>
     */
    public function orphanedSharedNodes(): array
    {
        $like = implode(' OR ', array_map(static fn (string $p): string => "n.id LIKE '" . $p . ":%'", self::SHARED_DECLARED_PREFIXES));
        $rows = $this->adapter->execute(
            'SELECT DISTINCT n.id AS id FROM graph_nodes n JOIN graph_edges e ON e.target_id = n.id'
            . ' JOIN graph_nodes s ON s.id = e.source_id AND s.is_placeholder = 0'
            . ' WHERE n.is_placeholder = 1 AND (' . $like . ') ORDER BY n.id',
        )->fetchAll();

        return array_values(array_map(static fn (array $r): string => (string) $r['id'], $rows));
    }
}
