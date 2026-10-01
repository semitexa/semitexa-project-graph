<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

use Semitexa\ProjectGraph\Application\Service\Findings\FindingsReport;
use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The graph as a browser wants it: small JSON-ready slices, fetched in bulk.
 *
 * One reader serves both places the graph is browsed — the Observatory's Graph
 * view, which asks for one slice at a time over HTTP, and the self-contained
 * HTML export, which embeds the same slices in the file. Keeping a single
 * source for both is what makes the export the same view rather than a second
 * viewer that drifts.
 *
 * Direction follows dependency, the way a reader walks from an entry point:
 * outgoing edges, plus the two edges the indexer records the "wrong" way round
 * for that walk — a payload serves a route, a handler handles a payload — so a
 * route expands to its payload and the payload to its handler.
 *
 * Every id that arrives here comes from a request. It is only ever bound into
 * SQL: never handed to class_exists() or reflection, which would autoload it.
 */
final class GraphBrowser
{
    /**
     * Edge kinds left out of every view. `imports` alone is ~20k edges of `use`
     * statements; the others point at documentation and grouping nodes rather
     * than at code a class depends on.
     */
    public const NOISE_EDGES = ['imports', 'belongs_to_domain', 'intent_for', 'participates_in_flow', 'annotated_with'];

    /** Node kinds that describe the code rather than being part of it. */
    public const HIDDEN_NODES = ['doc_node', 'domain_context', 'execution_flow'];

    /** Inbound edges walked as if outgoing: route ← payload ← handler. */
    private const ENTRY_REVERSED = ['serves_route', 'handles'];

    /** The node kinds a tree is rooted at. */
    public const ENTRY_TYPES = ['route', 'command', 'handler'];

    private const PATH_MAX_DEPTH = 8;
    private const PATH_MAX_VISITED = 5000;

    public const MAX_DEPTH = 4;
    public const MAX_NODES = 2500;
    private const SEARCH_LIMIT = 50;
    private const MAX_EDGES_PER_SIDE = 400;

    /** @var array<string, list<array{kind: string, line: int, subject: string, detail: string}>>|null gaps by absolute file path, loaded on first use */
    private ?array $gapsByFile = null;

    public function __construct(
        private readonly GraphStorage $storage,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * What the view opens on: counts, modules and the entry points the tree is
     * rooted at.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $entries = [];
        foreach (['route' => NodeType::Route, 'command' => NodeType::Command, 'handler' => NodeType::Handler] as $key => $type) {
            $list = [];
            foreach ($this->storage->nodes->findByType($type->value) as $node) {
                if ($node->getIsPlaceholder()) {
                    continue;
                }
                $list[] = $this->node($node);
            }
            usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            $entries[$key] = $list;
        }

        $byType = $this->storage->nodes->countByType();
        foreach (self::HIDDEN_NODES as $hidden) {
            unset($byType[$hidden]);
        }

        $lastUpdate = $this->storage->getMeta('last_update');
        $exclusions = json_decode((string) ($this->storage->getMeta('coverage_exclusions') ?? ''), true);

        return [
            'builtAt' => $lastUpdate !== null && $lastUpdate !== '' ? (int) $lastUpdate : null,
            'counts' => [
                'nodes' => $this->storage->nodes->countAll(),
                'edges' => $this->storage->edges->countAll(),
                'byType' => $byType,
            ],
            'modules' => $this->storage->nodes->distinctModules(),
            'entries' => $entries,
            'coverage' => [
                'gaps' => $this->storage->gaps->countByKind(),
                'excluded' => is_array($exclusions) ? $exclusions : [],
            ],
        ];
    }

    /**
     * One node and every edge touching it, neighbours resolved in bulk.
     *
     * @return array<string, mixed>|null null when the graph has no such node
     */
    public function describe(string $id): ?array
    {
        $node = $this->storage->nodes->findById($id);
        if ($node === null) {
            return null;
        }

        $out = $this->withoutNoise($this->storage->edges->findBySourceIds([$id]));
        $in = $this->withoutNoise($this->storage->edges->findByTargetIds([$id]));
        $truncated = count($out) > self::MAX_EDGES_PER_SIDE || count($in) > self::MAX_EDGES_PER_SIDE;
        $out = array_slice($out, 0, self::MAX_EDGES_PER_SIDE);
        $in = array_slice($in, 0, self::MAX_EDGES_PER_SIDE);

        $ids = [];
        foreach ($out as $edge) {
            $ids[] = $edge->getTargetId();
        }
        foreach ($in as $edge) {
            $ids[] = $edge->getSourceId();
        }
        $neighbours = $this->storage->nodes->findByIds($ids);

        return [
            'node' => $this->node($node),
            'gaps' => $this->gapsOf($node->getFile()),
            'fanIn' => count($in),
            'out' => $this->sides($out, $neighbours, outgoing: true),
            'in' => $this->sides($in, $neighbours, outgoing: false),
            'truncated' => $truncated,
        ];
    }

    /**
     * Everything reachable from $rootId within $depth steps, in the dependency
     * direction. Depth 1 is one level of a lazily expanded tree; a deeper walk
     * is the focus of the DAG view.
     *
     * @return array<string, mixed>|null null when the graph has no such node
     */
    public function subgraph(string $rootId, int $depth): ?array
    {
        $root = $this->storage->nodes->findById($rootId);
        if ($root === null) {
            return null;
        }

        $depth = max(1, min(self::MAX_DEPTH, $depth));
        $nodes = [$rootId => $root];
        $edges = [];
        $frontier = [$rootId];
        $truncated = false;

        for ($level = 0; $level < $depth && $frontier !== []; $level++) {
            $step = $this->step($frontier);
            $next = [];
            $missing = [];
            foreach ($step as $edge) {
                [$from, $to] = $edge;
                $edges[$from . '>' . $to[0] . '>' . $to[1]] = ['s' => $from, 't' => $to[0], 'k' => $to[1], 'c' => $to[2]];
                if (!isset($nodes[$to[0]])) {
                    $missing[$to[0]] = true;
                }
            }

            foreach ($this->storage->nodes->findByIds(array_keys($missing)) as $id => $node) {
                if (in_array($node->getType()->value, self::HIDDEN_NODES, true)) {
                    continue;
                }
                if (count($nodes) >= self::MAX_NODES) {
                    $truncated = true;
                    break;
                }
                $nodes[$id] = $node;
                $next[] = $id;
            }
            $frontier = $next;
        }

        // An edge is only kept when both of its ends made it into the slice.
        $edges = array_values(array_filter(
            $edges,
            static fn (array $e): bool => isset($nodes[$e['s']], $nodes[$e['t']]),
        ));

        $fanIn = $this->storage->edges->countInboundByTarget(array_keys($nodes), self::NOISE_EDGES);

        $rendered = [];
        foreach ($nodes as $id => $node) {
            $rendered[] = $this->node($node) + ['fanIn' => $fanIn[$id] ?? 0];

            // A reference the indexer could not resolve — a class name known only
            // at runtime — becomes a ghost: an unseen edge must not look like no edge.
            $dynamic = array_filter($this->gapsOf($node->getFile()), static fn (array $g): bool => $g['kind'] === 'dynamic_reference');
            if ($dynamic !== []) {
                $ghost = 'ghost:' . $id;
                $rendered[] = [
                    'id' => $ghost, 'name' => '? ' . count($dynamic) . ' runtime ' . (count($dynamic) === 1 ? 'class' : 'classes'),
                    'fqcn' => 'Resolved only at runtime — lines ' . implode(', ', array_map(static fn (array $g): int => $g['line'], $dynamic)),
                    'type' => 'unresolved', 'module' => '', 'file' => '', 'line' => 0, 'placeholder' => true, 'ghost' => true, 'gaps' => 0, 'fanIn' => 0,
                ];
                $edges[] = ['s' => $id, 't' => $ghost, 'k' => 'dynamic_reference', 'c' => 'inferred'];
            }
        }

        return [
            'root' => $rootId,
            'depth' => $depth,
            'nodes' => $rendered,
            'edges' => $edges,
            'truncated' => $truncated,
        ];
    }

    /**
     * The shortest walk from an entry point down to $id, entry first — what a
     * tree has to expand to reveal a node found by search. Walks parents
     * breadth-first: the reverse of {@see subgraph()}'s direction.
     *
     * @return list<string>|null null when no entry point reaches the node within the bounds
     */
    public function pathToEntry(string $id): ?array
    {
        $node = $this->storage->nodes->findById($id);
        if ($node === null) {
            return null;
        }
        if (in_array($node->getType()->value, self::ENTRY_TYPES, true)) {
            return [$id];
        }

        /** @var array<string, string> $childOf parent id => the child it was reached from */
        $childOf = [$id => ''];
        /** @var list<string> $frontier */
        $frontier = [$id];

        for ($level = 0; $level < self::PATH_MAX_DEPTH && $frontier !== []; $level++) {
            $parents = [];
            foreach ($this->storage->edges->findByTargetIds($frontier) as $edge) {
                $kind = $edge->getType()->value;
                if (!in_array($kind, self::NOISE_EDGES, true) && !in_array($kind, self::ENTRY_REVERSED, true)) {
                    $parents[] = [$edge->getSourceId(), $edge->getTargetId()];
                }
            }
            foreach ($this->storage->edges->findBySourceIds($frontier) as $edge) {
                if (in_array($edge->getType()->value, self::ENTRY_REVERSED, true)) {
                    $parents[] = [$edge->getTargetId(), $edge->getSourceId()];
                }
            }

            $fresh = [];
            foreach ($parents as [$parent, $child]) {
                if (!isset($childOf[$parent])) {
                    $childOf[$parent] = $child;
                    $fresh[] = $parent;
                }
            }
            if ($fresh === [] || count($childOf) > self::PATH_MAX_VISITED) {
                return null;
            }

            foreach ($this->storage->nodes->findByIds($fresh) as $parentId => $parentNode) {
                if (!$parentNode->getIsPlaceholder() && in_array($parentNode->getType()->value, self::ENTRY_TYPES, true)) {
                    $path = [$parentId];
                    for ($at = $childOf[$parentId]; $at !== ''; $at = $childOf[$at]) {
                        $path[] = $at;
                    }

                    return $path;
                }
            }
            $frontier = $fresh;
        }

        return null;
    }

    /**
     * Name or FQCN containing $query, real nodes before placeholders.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $hits = [];
        foreach ($this->storage->nodes->searchFull($query, self::SEARCH_LIMIT) as $node) {
            if (in_array($node->getType()->value, self::HIDDEN_NODES, true)) {
                continue;
            }
            $hits[] = $this->node($node);
        }

        usort($hits, static function (array $a, array $b) use ($query): int {
            return [$a['placeholder'], !str_starts_with(strtolower($a['name']), strtolower($query)), $a['name']]
                <=> [$b['placeholder'], !str_starts_with(strtolower($b['name']), strtolower($query)), $b['name']];
        });

        return $hits;
    }

    /**
     * The JSON shape of one node. Paths are made relative to the project, since
     * the indexer stored whatever absolute path its container saw.
     *
     * @return array{id: string, name: string, fqcn: string, type: string, module: string, file: string, line: int, placeholder: bool, gaps: int}
     */
    public function node(Node $node): array
    {
        $file = $node->getFile();
        $gaps = $file !== '' ? count($this->gapsOf($file)) : 0;
        if (str_starts_with($file, $this->projectRoot . '/')) {
            $file = substr($file, strlen($this->projectRoot) + 1);
        }

        return [
            'id' => $node->getId(),
            'name' => $node->name(),
            'fqcn' => $node->getFqcn(),
            'type' => $node->getType()->value,
            'module' => $node->getModule(),
            'file' => $file,
            'line' => $node->getLine(),
            'placeholder' => $node->getIsPlaceholder(),
            'gaps' => $gaps,
        ];
    }

    /**
     * Unused classes (every confidence, so the view can filter) and loops,
     * with their nodes resolved, plus the coverage summary that says how far
     * an absence of findings can be trusted.
     *
     * @return array<string, mixed>
     */
    public function findings(): array
    {
        $report = (new FindingsReport())->collect($this->storage, 'all', UnusedClassFinder::LOW);

        $ids = array_column($report['unused'], 'node');
        foreach ($report['cycles'] as $cycle) {
            $ids = [...$ids, ...$cycle['members']];
        }
        $nodes = $this->storage->nodes->findByIds($ids);

        $unused = [];
        foreach ($report['unused'] as $finding) {
            $node = $nodes[$finding['node']] ?? null;
            if ($node !== null) {
                $unused[] = ['finding' => $finding['id'], 'confidence' => $finding['confidence'], 'evidence' => $finding['evidence'], 'node' => $this->node($node)];
            }
        }

        $cycles = [];
        foreach ($report['cycles'] as $cycle) {
            $members = [];
            foreach ($cycle['members'] as $id) {
                if (isset($nodes[$id])) {
                    $members[] = $this->node($nodes[$id]);
                }
            }
            $cycles[] = ['finding' => $cycle['id'], 'members' => $members, 'cycle' => $cycle['cycle']];
        }

        return ['unused' => $unused, 'cycles' => $cycles, 'coverage' => $report['coverage']];
    }

    /**
     * The coverage gaps recorded against one file.
     *
     * @return list<array{kind: string, line: int, subject: string, detail: string}>
     */
    private function gapsOf(string $file): array
    {
        if ($this->gapsByFile === null) {
            $this->gapsByFile = [];
            foreach ($this->storage->gaps->findAll() as $gap) {
                $this->gapsByFile[$gap->getFile()][] = [
                    'kind' => $gap->getKind()->value,
                    'line' => $gap->getLine(),
                    'subject' => $gap->getSubject(),
                    'detail' => $gap->getDetail(),
                ];
            }
        }

        return $this->gapsByFile[$file] ?? [];
    }

    /**
     * One step outward from every node in $frontier.
     *
     * @param list<string> $frontier
     * @return list<array{0: string, 1: array{0: string, 1: string, 2: string}}> [from, [to, kind, class]]
     */
    private function step(array $frontier): array
    {
        $steps = [];
        foreach ($this->withoutNoise($this->storage->edges->findBySourceIds($frontier)) as $edge) {
            if (in_array($edge->getType()->value, self::ENTRY_REVERSED, true)) {
                continue;
            }
            $steps[] = [$edge->getSourceId(), [$edge->getTargetId(), $edge->getType()->value, $edge->getType()->edgeClass()->value]];
        }
        foreach ($this->storage->edges->findByTargetIds($frontier) as $edge) {
            if (!in_array($edge->getType()->value, self::ENTRY_REVERSED, true)) {
                continue;
            }
            $steps[] = [$edge->getTargetId(), [$edge->getSourceId(), $edge->getType()->value, $edge->getType()->edgeClass()->value]];
        }

        return $steps;
    }

    /**
     * @param list<Edge> $edges
     * @return list<Edge>
     */
    private function withoutNoise(array $edges): array
    {
        return array_values(array_filter(
            $edges,
            static fn (Edge $edge): bool => !in_array($edge->getType()->value, self::NOISE_EDGES, true),
        ));
    }

    /**
     * @param list<Edge> $edges
     * @param array<string, Node> $neighbours
     * @return list<array{kind: string, class: string, node: array<string, mixed>}>
     */
    private function sides(array $edges, array $neighbours, bool $outgoing): array
    {
        $rows = [];
        foreach ($edges as $edge) {
            $other = $neighbours[$outgoing ? $edge->getTargetId() : $edge->getSourceId()] ?? null;
            if ($other === null || in_array($other->getType()->value, self::HIDDEN_NODES, true)) {
                continue;
            }
            $rows[] = [
                'kind' => $edge->getType()->value,
                'class' => $edge->getType()->edgeClass()->value,
                'node' => $this->node($other),
            ];
        }

        return $rows;
    }
}
