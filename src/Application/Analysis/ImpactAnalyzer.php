<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Query\Direction;

final class ImpactAnalyzer
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    /**
     * @param list<string> $changedNodeIds
     */
    public function analyze(array $changedNodeIds, int $maxDepth = 5): ImpactResult
    {
        /** @var array<string, ImpactedNode> $impacted */
        $impacted = [];
        /** @var array<string, true> $visited */
        $visited = array_fill_keys($changedNodeIds, true);
        /** @var list<string> $currentLevel */
        $currentLevel = $changedNodeIds;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            /** @var list<string> $nextLevel */
            $nextLevel = [];
            foreach ($currentLevel as $nodeId) {
                $edges = $this->storage->edges->findByTarget($nodeId);
                foreach ($edges as $edge) {
                    if (!isset($visited[$edge->sourceId])) {
                        $visited[$edge->sourceId] = true;
                        $nextLevel[] = $edge->sourceId;
                        $targetNode = $this->storage->nodes->findById($edge->sourceId);
                        if ($targetNode !== null) {
                            if (!isset($impacted[$edge->sourceId])) {
                                $impacted[$edge->sourceId] = new ImpactedNode(
                                    node:     $targetNode,
                                    distance: $depth,
                                    paths:    [[$edge]],
                                );
                            } else {
                                $existing = $impacted[$edge->sourceId];
                                $impacted[$edge->sourceId] = new ImpactedNode(
                                    node:     $existing->node,
                                    distance: min($existing->distance, $depth),
                                    paths:    [...$existing->paths, [$edge]],
                                );
                            }
                        }
                    }
                }
            }
            $currentLevel = $nextLevel;
            if (empty($currentLevel)) {
                break;
            }
        }

        return new ImpactResult(
            changed:  $changedNodeIds,
            impacted: $impacted,
        );
    }

    /**
     * @param list<string> $startNodeIds
     * @return list<Edge>
     */
    public function analyzeForward(array $startNodeIds, int $maxDepth = 3): array
    {
        /** @var list<Edge> $allEdges */
        $allEdges = [];
        /** @var array<string, true> $visited */
        $visited = array_fill_keys($startNodeIds, true);
        /** @var list<string> $currentLevel */
        $currentLevel = $startNodeIds;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            /** @var list<string> $nextLevel */
            $nextLevel = [];
            foreach ($currentLevel as $nodeId) {
                $edges = $this->storage->edges->findBySource($nodeId);
                foreach ($edges as $edge) {
                    $allEdges[] = $edge;
                    if (!isset($visited[$edge->targetId])) {
                        $visited[$edge->targetId] = true;
                        $nextLevel[] = $edge->targetId;
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

    /**
     * @return array{node: Node|null, edges: list<Edge>, children: list<array{edge: Edge, child: array{node: Node|null, edges: list<Edge>, children: list<mixed>}}>}
     */
    public function getDependencyTree(string $nodeId, int $maxDepth = 3): array
    {
        return $this->buildTree($nodeId, Direction::Outgoing, $maxDepth, []);
    }

    /**
     * @return array{node: Node|null, edges: list<Edge>, children: list<array{edge: Edge, child: array{node: Node|null, edges: list<Edge>, children: list<mixed>}}>}
     */
    public function getUsageTree(string $nodeId, int $maxDepth = 3): array
    {
        return $this->buildTree($nodeId, Direction::Incoming, $maxDepth, []);
    }

    /**
     * @param array<string, true> $visited
     * @return array{node: Node|null, edges: list<Edge>, children: list<array{edge: Edge, child: array{node: Node|null, edges: list<Edge>, children: list<mixed>}}>}
     */
    private function buildTree(string $nodeId, Direction $direction, int $maxDepth, array $visited): array
    {
        if (isset($visited[$nodeId]) || $maxDepth <= 0) {
            return ['node' => null, 'edges' => [], 'children' => []];
        }

        $visited[$nodeId] = true;
        $node = $this->storage->nodes->findById($nodeId);

        $edges = match ($direction) {
            Direction::Outgoing => $this->storage->edges->findBySource($nodeId),
            Direction::Incoming => $this->storage->edges->findByTarget($nodeId),
            Direction::Both => [
                ...$this->storage->edges->findBySource($nodeId),
                ...$this->storage->edges->findByTarget($nodeId),
            ],
        };

        /** @var list<array{edge: Edge, child: array{node: Node|null, edges: list<Edge>, children: list<mixed>}}> $children */
        $children = [];
        foreach ($edges as $edge) {
            $neighborId = match ($direction) {
                Direction::Outgoing => $edge->targetId,
                Direction::Incoming => $edge->sourceId,
                Direction::Both => $edge->sourceId === $nodeId ? $edge->targetId : $edge->sourceId,
            };
            $children[] = [
                'edge'   => $edge,
                'child'  => $this->buildTree($neighborId, $direction, $maxDepth - 1, $visited),
            ];
        }

        return [
            'node'     => $node,
            'edges'    => $edges,
            'children' => $children,
        ];
    }
}
