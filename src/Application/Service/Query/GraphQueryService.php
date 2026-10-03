<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;

final class GraphQueryService implements QueryInterface
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    public function getNode(string $idOrFqcn): ?Node
    {
        return $this->storage->nodes->findById($idOrFqcn)
            ?? $this->storage->nodes->findByFqcn($idOrFqcn);
    }

    public function findNodes(?string $type = null, ?string $module = null, ?string $namePattern = null): array
    {
        if ($namePattern !== null) {
            $results = $this->storage->nodes->searchText($namePattern, 20, [], $module);
            if ($type !== null) {
                $results = array_filter($results, fn(Node $n) => $n->getType()->value === $type);
            }
            if ($module !== null) {
                $results = array_filter($results, fn(Node $n) => $n->getModule() === $module);
            }
            return array_values($results);
        }

        if ($type !== null) {
            return $this->storage->nodes->findByType($type, $module);
        }

        if ($module !== null) {
            return $this->storage->nodes->findByModule($module);
        }

        return [];
    }

    public function getEdges(string $nodeId, ?string $edgeType = null, ?Direction $direction = null): array
    {
        $type = $edgeType !== null ? EdgeType::tryFrom($edgeType) : null;
        return match ($direction) {
            Direction::Outgoing => $this->storage->edges->findBySource($nodeId, $type),
            Direction::Incoming => $this->storage->edges->findByTarget($nodeId, $type),
            default             => $this->storage->edges->findByNode($nodeId),
        };
    }

    public function getDependencies(string $nodeId, int $maxDepth = 1): array
    {
        return $this->traverse([$nodeId], Direction::Outgoing, $maxDepth);
    }

    public function getUsages(string $nodeId, int $maxDepth = 1): array
    {
        return $this->traverse([$nodeId], Direction::Incoming, $maxDepth);
    }

    public function getImpact(array $nodeIds, int $maxDepth = 5): ImpactResult
    {
        $impacted = [];
        $visited = array_fill_keys($nodeIds, true);
        $currentLevel = $nodeIds;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $nextLevel = [];
            foreach ($currentLevel as $nodeId) {
                $edges = $this->storage->edges->findByTarget($nodeId);
                foreach ($edges as $edge) {
                    if (!isset($visited[$edge->getSourceId()])) {
                        $visited[$edge->getSourceId()] = true;
                        $nextLevel[] = $edge->getSourceId();
                        $targetNode = $this->storage->nodes->findById($edge->getSourceId());
                        if ($targetNode !== null) {
                            $impacted[$edge->getSourceId()] = new ImpactedNode(
                                node:     $targetNode,
                                distance: $depth,
                                paths:    [[$edge]],
                            );
                        }
                    }
                }
            }
            $currentLevel = $nextLevel;
            if (empty($currentLevel)) {
                break;
            }
        }

        return new ImpactResult(changed: $nodeIds, impacted: $impacted);
    }

    public function getRelatedTests(string $nodeId): array
    {
        return $this->storage->edges->findByTarget($nodeId, EdgeType::Tests);
    }

    public function getHandlerChain(string $routeOrPayloadId): array
    {
        $chain = [];
        $node = $this->getNode($routeOrPayloadId);
        if ($node === null) {
            return $chain;
        }

        $chain[] = $node;

        if ($node->getType() === NodeType::Route) {
            $payloadEdges = $this->storage->edges->findByTarget($node->getId(), EdgeType::ServesRoute);
            foreach ($payloadEdges as $edge) {
                $payload = $this->storage->nodes->findById($edge->getSourceId());
                if ($payload !== null) {
                    $chain[] = $payload;
                    $handlerEdges = $this->storage->edges->findByTarget($payload->getId(), EdgeType::Handles);
                    foreach ($handlerEdges as $hEdge) {
                        $handler = $this->storage->nodes->findById($hEdge->getSourceId());
                        if ($handler !== null) {
                            $chain[] = $handler;
                            $resourceEdges = $this->storage->edges->findBySource($handler->getId(), EdgeType::Produces);
                            foreach ($resourceEdges as $rEdge) {
                                $resource = $this->storage->nodes->findById($rEdge->getTargetId());
                                if ($resource !== null) {
                                    $chain[] = $resource;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($node->getType() === NodeType::Payload) {
            $handlerEdges = $this->storage->edges->findByTarget($node->getId(), EdgeType::Handles);
            foreach ($handlerEdges as $hEdge) {
                $handler = $this->storage->nodes->findById($hEdge->getSourceId());
                if ($handler !== null) {
                    $chain[] = $handler;
                }
            }
        }

        return $chain;
    }

    public function getContractImplementors(string $contractFqcn): array
    {
        $contractId = 'class:' . $contractFqcn;
        $edges = $this->storage->edges->findByTarget($contractId, EdgeType::SatisfiesContract);
        $implementors = [];
        foreach ($edges as $edge) {
            $impl = $this->storage->nodes->findById($edge->getSourceId());
            if ($impl !== null) {
                $implementors[] = $impl;
            }
        }
        return $implementors;
    }

    public function getCrossModuleEdges(?string $moduleA = null, ?string $moduleB = null): array
    {
        $edgeTypes = [
            EdgeType::InjectsReadonly,
            EdgeType::InjectsMutable,
            EdgeType::InjectsFactory,
            EdgeType::Calls,
            EdgeType::Extends,
            EdgeType::Implements,
            EdgeType::Handles,
            EdgeType::Produces,
            EdgeType::ListensTo,
            EdgeType::Emits,
        ];

        // One join instead of two node lookups per edge: --doc-gaps asked this
        // once per module and took 36 s (round 2).
        $crossModule = $this->storage->edges->crossModule(
            array_map(static fn (EdgeType $t): string => $t->value, $edgeTypes),
            $moduleA,
            $moduleB,
        );

        return $crossModule;
    }

    /**
     * Name and FQCN containing $query, literally — `%` and `_` used to be
     * LIKE wildcards here — exact-prefix names first, scoped to $module in
     * the query itself (filtering after the limit lost real matches).
     *
     * @return list<Node>
     */
    public function search(string $query, int $limit = 20, ?string $module = null, ?string $type = null): array
    {
        return $this->storage->nodes->searchText($query, max(1, $limit), [], $module, $type);
    }

    /** A view larger than this is cut and says so; the walk used to run out of memory instead. */
    public const VIEW_MAX_NODES = 2500;

    /**
     * $focus is a node id or FQCN; the caller resolves what a person typed
     * ({@see NodeResolver}). The walk follows wiring and code references, not
     * imports, domains, flows or attributes: walked undirected through those
     * hubs, depth 4 on GraphStorage reached the whole graph and exhausted 128M
     * (measured 2026-10-02). Edges are deduplicated as they are found and nodes
     * loaded in batches — it used to load both ends of every edge one query at
     * a time, twice.
     */
    public function buildView(?string $module = null, ?array $types = null, ?string $focus = null, int $depth = 3): GraphView
    {
        /** @var array<string, Node> $nodes */
        $nodes = [];
        /** @var array<string, Edge> $edges keyed by type|source|target */
        $edges = [];
        $truncated = false;
        $noise = array_flip(GraphBrowser::NOISE_EDGES);
        $hidden = array_flip(GraphBrowser::HIDDEN_NODES);
        $reversed = array_flip(['serves_route', 'handles']);

        $typeSet = $types === null ? null : array_flip($types);
        $fits = static fn (Node $n): bool => ($typeSet === null || isset($typeSet[$n->getType()->value]))
            && ($module === null || $n->getModule() === $module);

        if ($focus !== null) {
            $focusNode = $this->getNode($focus);
            if ($focusNode !== null) {
                // Directed the way the Observatory and the HTML export walk —
                // what the focus depends on, plus route ← payload ← handler —
                // so `show` and the viewer agree (round 2: 19 vs 5 nodes at
                // depth 1). --type and --module narrow the walk instead of
                // being ignored.
                $nodes[$focusNode->getId()] = $focusNode;
                $frontier = [$focusNode->getId()];
                for ($d = 0; $d < $depth && $frontier !== [] && !$truncated; $d++) {
                    $found = [];
                    foreach (array_chunk($frontier, 500) as $chunk) {
                        foreach ($this->storage->edges->findBySourceIds($chunk) as $edge) {
                            if (isset($noise[$edge->getType()->value]) || isset($reversed[$edge->getType()->value])) {
                                continue;
                            }
                            $edges[$edge->getType()->value . '|' . $edge->getSourceId() . '|' . $edge->getTargetId()] = $edge;
                            $found[$edge->getTargetId()] = true;
                        }
                        foreach ($this->storage->edges->findByTargetIds($chunk) as $edge) {
                            if (!isset($reversed[$edge->getType()->value])) {
                                continue;
                            }
                            $edges[$edge->getType()->value . '|' . $edge->getSourceId() . '|' . $edge->getTargetId()] = $edge;
                            $found[$edge->getSourceId()] = true;
                        }
                    }
                    $frontier = [];
                    foreach ($this->storage->nodes->findByIds(array_keys(array_diff_key($found, $nodes))) as $id => $neighbor) {
                        if (isset($hidden[$neighbor->getType()->value]) || !$fits($neighbor)) {
                            continue;
                        }
                        if (count($nodes) >= self::VIEW_MAX_NODES) {
                            $truncated = true;
                            break;
                        }
                        $nodes[$id] = $neighbor;
                        $frontier[] = $id;
                    }
                }
                // Only edges whose both ends made it into the view.
                $edges = array_filter($edges, static fn (Edge $e): bool => isset($nodes[$e->getSourceId()], $nodes[$e->getTargetId()]));
            }
        } else {
            if ($types === null && $module === null) {
                return $this->wholeGraphCensus();
            }
            $candidates = [];
            if ($types !== null) {
                foreach ($types as $type) {
                    foreach ($this->storage->nodes->findByType($type, $module) as $node) {
                        $candidates[$node->getId()] = $node;
                    }
                }
            } else {
                foreach ($this->storage->nodes->findByModule($module) as $node) {
                    $candidates[$node->getId()] = $node;
                }
            }
            // The same cap as a walk: `--type=class` held 4000 classes and
            // their edges and ran out of memory (round 2).
            ksort($candidates);
            if (count($candidates) > self::VIEW_MAX_NODES) {
                $truncated = true;
                $candidates = array_slice($candidates, 0, self::VIEW_MAX_NODES, true);
            }
            $nodes = $candidates;
            // The edges leaving the view's nodes, in batches, instead of a query per node.
            foreach (array_chunk(array_keys($nodes), 500) as $chunk) {
                foreach ($this->storage->edges->findBySourceIds($chunk) as $edge) {
                    if (isset($nodes[$edge->getTargetId()])) {
                        $edges[$edge->getType()->value . '|' . $edge->getSourceId() . '|' . $edge->getTargetId()] = $edge;
                    }
                }
            }
        }

        $nodeTypeCounts = [];
        $moduleCounts = [];
        $placeholders = 0;
        foreach ($nodes as $node) {
            $nodeTypeCounts[$node->getType()->value] = ($nodeTypeCounts[$node->getType()->value] ?? 0) + 1;
            if ($node->getModule() !== '') {
                $moduleCounts[$node->getModule()] = ($moduleCounts[$node->getModule()] ?? 0) + 1;
            }
            if ($node->getIsPlaceholder()) {
                $placeholders++;
            }
        }
        arsort($nodeTypeCounts);
        arsort($moduleCounts);

        // Counted once per edge: the type counts used to be taken before deduplication, so every type read double.
        $edgeTypeCounts = [];
        $crossModule = 0;
        $touched = [];
        foreach ($edges as $edge) {
            $edgeTypeCounts[$edge->getType()->value] = ($edgeTypeCounts[$edge->getType()->value] ?? 0) + 1;
            $source = $nodes[$edge->getSourceId()] ?? null;
            $target = $nodes[$edge->getTargetId()] ?? null;
            if ($source !== null && $target !== null
                && $source->getModule() !== '' && $target->getModule() !== ''
                && $source->getModule() !== $target->getModule()
            ) {
                $crossModule++;
            }
            $touched[$edge->getSourceId()] = true;
            $touched[$edge->getTargetId()] = true;
        }
        arsort($edgeTypeCounts);

        $orphans = 0;
        foreach ($nodes as $node) {
            if (!isset($touched[$node->getId()]) && $node->getType() !== NodeType::Route) {
                $orphans++;
            }
        }

        return new GraphView(
            nodes:            array_values($nodes),
            edges:            array_values($edges),
            nodeTypeCounts:   $nodeTypeCounts,
            edgeTypeCounts:   $edgeTypeCounts,
            totalNodes:       count($nodes),
            totalEdges:       count($edges),
            crossModuleEdges: $crossModule,
            orphanNodes:      $orphans,
            placeholderNodes: $placeholders,
            moduleCounts:     $moduleCounts,
            truncated:        $truncated,
        );
    }

    /**
     * @param list<string> $nodeIds
     * @return array<string, int>
     */
    public function inboundCounts(array $nodeIds): array
    {
        return $this->storage->edges->countInboundByTarget($nodeIds);
    }

    /** @return array<string, true> */
    public function sourcesOf(string $type): array
    {
        $sources = [];
        foreach ($this->storage->edges->findByType(EdgeType::from($type)) as $edge) {
            $sources[$edge->getSourceId()] = true;
        }

        return $sources;
    }

    public function resolver(string $projectRoot): NodeResolver
    {
        return new NodeResolver($this->storage, $projectRoot);
    }

    /** @return list<string> */
    public function knownModules(): array
    {
        return $this->storage->nodes->distinctModules();
    }

    /**
     * The unfiltered view: counts of everything (as `stats` reports them — the
     * view used to count classes only), and no node or edge lists.
     */
    public function wholeGraphCensus(): GraphView
    {
        $census = $this->storage->lookup->census();

        return new GraphView(
            nodes:            [],
            edges:            [],
            nodeTypeCounts:   $census['node_types'],
            edgeTypeCounts:   $census['edge_types'],
            totalNodes:       array_sum($census['node_types']),
            totalEdges:       array_sum($census['edge_types']),
            crossModuleEdges: $census['cross_module'],
            orphanNodes:      $census['orphans'],
            placeholderNodes: $census['placeholders'],
            moduleCounts:     $census['modules'],
        );
    }

    public function getModuleSummaries(?string $module = null): array
    {
        $allNodes = $module !== null
            ? $this->storage->nodes->findByModule($module)
            : $this->storage->nodes->findByType('class');

        $modules = [];
        foreach ($allNodes as $node) {
            if ($node->getModule() === '') {
                continue;
            }

            $modules[$node->getModule()] = ($modules[$node->getModule()] ?? 0) + 1;
        }

        arsort($modules);
        return $modules;
    }

    public function getRouteSummary(?string $module = null): array
    {
        $routes = $this->storage->nodes->findByType('route', $module);
        $summary = [];
        foreach ($routes as $route) {
            $method = $route->getMetadata()['method'] ?? 'GET';
            $summary[$method] = ($summary[$method] ?? 0) + 1;
        }
        return $summary;
    }

    public function countNodes(string $type, ?string $module = null): int
    {
        return count($this->storage->nodes->findByType($type, $module));
    }

    public function countEdges(string $type, ?string $module = null): int
    {
        $edgeType = EdgeType::tryFrom($type) ?? EdgeType::Calls;
        $edges = $this->storage->edges->findByType($edgeType);

        if ($module === null) {
            return count($edges);
        }

        $count = 0;
        foreach ($edges as $edge) {
            $source = $this->storage->nodes->findById($edge->getSourceId());
            $target = $this->storage->nodes->findById($edge->getTargetId());

            if (($source?->getModule() === $module) || ($target?->getModule() === $module)) {
                $count++;
            }
        }

        return $count;
    }

    public function countSatisfiedContracts(?string $module = null): int
    {
        $edges = $this->storage->edges->findByType(EdgeType::SatisfiesContract);
        $contracts = [];

        foreach ($edges as $edge) {
            $source = $this->storage->nodes->findById($edge->getSourceId());
            $target = $this->storage->nodes->findById($edge->getTargetId());

            if ($module !== null && ($source?->getModule() !== $module) && ($target?->getModule() !== $module)) {
                continue;
            }

            $contracts[$edge->getTargetId()] = true;
        }

        return count($contracts);
    }

    public function countCrossModuleEdges(): int
    {
        return count($this->getCrossModuleEdges());
    }

    /** @return list<Edge> */
    private function traverse(array $startNodeIds, Direction $direction, int $maxDepth): array
    {
        $allEdges = [];
        $visited = array_fill_keys($startNodeIds, true);
        $currentLevel = $startNodeIds;

        for ($d = 0; $d < $maxDepth; $d++) {
            $nextLevel = [];
            foreach ($currentLevel as $nodeId) {
                $edges = match ($direction) {
                    Direction::Outgoing => $this->storage->edges->findBySource($nodeId),
                    Direction::Incoming => $this->storage->edges->findByTarget($nodeId),
                    default             => $this->storage->edges->findByNode($nodeId),
                };

                foreach ($edges as $edge) {
                    $allEdges[] = $edge;
                    $neighborId = $direction === Direction::Outgoing ? $edge->getTargetId() : $edge->getSourceId();
                    if (!isset($visited[$neighborId])) {
                        $visited[$neighborId] = true;
                        $nextLevel[] = $neighborId;
                    }
                }
            }
            $currentLevel = $nextLevel;
            if (empty($currentLevel)) {
                break;
            }
        }

        return $allEdges;
    }
}
