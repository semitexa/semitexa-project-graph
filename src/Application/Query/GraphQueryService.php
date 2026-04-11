<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Query;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

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
            $results = $this->storage->nodes->searchFull($namePattern);
            if ($type !== null) {
                $results = array_filter($results, fn(Node $n) => $n->type->value === $type);
            }
            if ($module !== null) {
                $results = array_filter($results, fn(Node $n) => $n->module === $module);
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
                    if (!isset($visited[$edge->sourceId])) {
                        $visited[$edge->sourceId] = true;
                        $nextLevel[] = $edge->sourceId;
                        $targetNode = $this->storage->nodes->findById($edge->sourceId);
                        if ($targetNode !== null) {
                            $impacted[$edge->sourceId] = new ImpactedNode(
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

        if ($node->type === NodeType::Route) {
            $payloadEdges = $this->storage->edges->findByTarget($node->id, EdgeType::ServesRoute);
            foreach ($payloadEdges as $edge) {
                $payload = $this->storage->nodes->findById($edge->sourceId);
                if ($payload !== null) {
                    $chain[] = $payload;
                    $handlerEdges = $this->storage->edges->findByTarget($payload->id, EdgeType::Handles);
                    foreach ($handlerEdges as $hEdge) {
                        $handler = $this->storage->nodes->findById($hEdge->sourceId);
                        if ($handler !== null) {
                            $chain[] = $handler;
                            $resourceEdges = $this->storage->edges->findBySource($handler->id, EdgeType::Produces);
                            foreach ($resourceEdges as $rEdge) {
                                $resource = $this->storage->nodes->findById($rEdge->targetId);
                                if ($resource !== null) {
                                    $chain[] = $resource;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($node->type === NodeType::Payload) {
            $handlerEdges = $this->storage->edges->findByTarget($node->id, EdgeType::Handles);
            foreach ($handlerEdges as $hEdge) {
                $handler = $this->storage->nodes->findById($hEdge->sourceId);
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
            $impl = $this->storage->nodes->findById($edge->sourceId);
            if ($impl !== null) {
                $implementors[] = $impl;
            }
        }
        return $implementors;
    }

    public function getCrossModuleEdges(?string $moduleA = null, ?string $moduleB = null): array
    {
        $allEdges = $this->storage->edges->findByType(EdgeType::InjectsReadonly);
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

        $crossModule = [];
        foreach ($edgeTypes as $edgeType) {
            $edges = $this->storage->edges->findByType($edgeType, 100_000);
            foreach ($edges as $edge) {
                $source = $this->storage->nodes->findById($edge->sourceId);
                $target = $this->storage->nodes->findById($edge->targetId);
                if ($source !== null && $target !== null
                    && $source->module !== '' && $target->module !== ''
                    && $source->module !== $target->module
                ) {
                    if ($moduleA !== null && $source->module !== $moduleA) {
                        continue;
                    }
                    if ($moduleB !== null && $target->module !== $moduleB) {
                        continue;
                    }
                    $crossModule[] = $edge;
                }
            }
        }

        return $crossModule;
    }

    public function search(string $query, int $limit = 20): array
    {
        return $this->storage->nodes->searchFull($query, $limit);
    }

    public function buildView(?string $module = null, ?array $types = null, ?string $focus = null, int $depth = 3): GraphView
    {
        $nodes = [];
        $edges = [];

        if ($focus !== null) {
            $focusNode = $this->getNode($focus);
            if ($focusNode !== null) {
                $nodes[$focusNode->id] = $focusNode;
                $visited = [$focusNode->id => true];
                $currentLevel = [$focusNode->id];

                for ($d = 0; $d < $depth; $d++) {
                    $nextLevel = [];
                    foreach ($currentLevel as $nodeId) {
                        $nodeEdges = $this->storage->edges->findByNode($nodeId);
                        foreach ($nodeEdges as $edge) {
                            $edges[] = $edge;
                            foreach ([$edge->sourceId, $edge->targetId] as $neighborId) {
                                if (!isset($visited[$neighborId])) {
                                    $visited[$neighborId] = true;
                                    $nextLevel[] = $neighborId;
                                    $neighbor = $this->storage->nodes->findById($neighborId);
                                    if ($neighbor !== null) {
                                        $nodes[$neighborId] = $neighbor;
                                    }
                                }
                            }
                        }
                    }
                    $currentLevel = $nextLevel;
                }
            }
        } elseif ($module !== null || $types !== null) {
            if ($types !== null) {
                foreach ($types as $type) {
                    $typeNodes = $this->storage->nodes->findByType($type, $module);
                    foreach ($typeNodes as $node) {
                        $nodes[$node->id] = $node;
                    }
                }
            } else {
                foreach ($this->storage->nodes->findByModule($module) as $node) {
                    $nodes[$node->id] = $node;
                }
            }
        } else {
            $allNodes = $this->storage->nodes->findByType('class');
            foreach ($allNodes as $node) {
                $nodes[$node->id] = $node;
            }
        }

        if (empty($focus) && !empty($nodes)) {
            foreach ($nodes as $nodeId => $node) {
                $nodeEdges = $this->storage->edges->findByNode($nodeId);
                foreach ($nodeEdges as $edge) {
                    if (isset($nodes[$edge->sourceId]) && isset($nodes[$edge->targetId])) {
                        $edges[] = $edge;
                    }
                }
            }
        }

        $nodeTypeCounts = [];
        $moduleCounts = [];
        foreach ($nodes as $node) {
            $nodeTypeCounts[$node->type->value] = ($nodeTypeCounts[$node->type->value] ?? 0) + 1;
            if ($node->module !== '') {
                $moduleCounts[$node->module] = ($moduleCounts[$node->module] ?? 0) + 1;
            }
        }

        $edgeTypeCounts = [];
        $crossModule = 0;
        $placeholders = 0;
        $orphans = 0;
        $uniqueEdges = [];
        $edgeIdSet = [];
        foreach ($edges as $edge) {
            $edgeTypeCounts[$edge->type->value] = ($edgeTypeCounts[$edge->type->value] ?? 0) + 1;
            $edgeKey = $edge->sourceId . '|' . $edge->targetId . '|' . $edge->type->value;
            if (!isset($edgeIdSet[$edgeKey])) {
                $edgeIdSet[$edgeKey] = true;
                $uniqueEdges[] = $edge;
            }
            $source = $this->storage->nodes->findById($edge->sourceId);
            $target = $this->storage->nodes->findById($edge->targetId);
            if ($source !== null && $target !== null
                && $source->module !== '' && $target->module !== ''
                && $source->module !== $target->module
            ) {
                $crossModule++;
            }
        }

        $edgeIdLookup = [];
        foreach ($uniqueEdges as $edge) {
            $edgeIdLookup[$edge->sourceId] = true;
            $edgeIdLookup[$edge->targetId] = true;
        }

        foreach ($nodes as $node) {
            if ($node->isPlaceholder) {
                $placeholders++;
            }
            if (!isset($edgeIdLookup[$node->id]) && $node->type !== NodeType::Route) {
                $orphans++;
            }
        }

        return new GraphView(
            nodes:            array_values($nodes),
            edges:            $uniqueEdges,
            nodeTypeCounts:   $nodeTypeCounts,
            edgeTypeCounts:   $edgeTypeCounts,
            totalNodes:       count($nodes),
            totalEdges:       count($uniqueEdges),
            crossModuleEdges: $crossModule,
            orphanNodes:      $orphans,
            placeholderNodes: $placeholders,
            moduleCounts:     $moduleCounts,
        );
    }

    public function getModuleSummaries(?string $module = null): array
    {
        $allNodes = $module !== null
            ? $this->storage->nodes->findByModule($module)
            : $this->storage->nodes->findByType('class');

        $modules = [];
        foreach ($allNodes as $node) {
            if ($node->module === '') {
                continue;
            }

            $modules[$node->module] = ($modules[$node->module] ?? 0) + 1;
        }

        arsort($modules);
        return $modules;
    }

    public function getRouteSummary(?string $module = null): array
    {
        $routes = $this->storage->nodes->findByType('route', $module);
        $summary = [];
        foreach ($routes as $route) {
            $method = $route->metadata['method'] ?? 'GET';
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
        $edges = $this->storage->edges->findByType($edgeType, 100_000);

        if ($module === null) {
            return count($edges);
        }

        $count = 0;
        foreach ($edges as $edge) {
            $source = $this->storage->nodes->findById($edge->sourceId);
            $target = $this->storage->nodes->findById($edge->targetId);

            if (($source?->module === $module) || ($target?->module === $module)) {
                $count++;
            }
        }

        return $count;
    }

    public function countSatisfiedContracts(?string $module = null): int
    {
        $edges = $this->storage->edges->findByType(EdgeType::SatisfiesContract, 100_000);
        $contracts = [];

        foreach ($edges as $edge) {
            $source = $this->storage->nodes->findById($edge->sourceId);
            $target = $this->storage->nodes->findById($edge->targetId);

            if ($module !== null && ($source?->module !== $module) && ($target?->module !== $module)) {
                continue;
            }

            $contracts[$edge->targetId] = true;
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
                    $neighborId = $direction === Direction::Outgoing ? $edge->targetId : $edge->sourceId;
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
