<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Query\Direction;

final class ImpactAnalyzer
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    public function analyze(array $changedNodeIds, int $maxDepth = 5): ImpactResult
    {
        $impacted = [];
        $visited = array_fill_keys($changedNodeIds, true);
        $currentLevel = $changedNodeIds;

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

    public function analyzeForward(array $startNodeIds, int $maxDepth = 3): array
    {
        $allEdges = [];
        $visited = array_fill_keys($startNodeIds, true);
        $currentLevel = $startNodeIds;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
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

    public function getDependencyTree(string $nodeId, int $maxDepth = 3): array
    {
        return $this->buildTree($nodeId, Direction::Outgoing, $maxDepth, []);
    }

    public function getUsageTree(string $nodeId, int $maxDepth = 3): array
    {
        return $this->buildTree($nodeId, Direction::Incoming, $maxDepth, []);
    }

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
        };

        $children = [];
        foreach ($edges as $edge) {
            $neighborId = $direction === Direction::Outgoing ? $edge->targetId : $edge->sourceId;
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

final readonly class ImpactResult
{
    public function __construct(
        /** @var list<string> */
        public array $changed,
        /** @var array<string, ImpactedNode> */
        public array $impacted,
    ) {}

    public function totalImpacted(): int
    {
        return count($this->impacted);
    }

    public function maxDepth(): int
    {
        $max = 0;
        foreach ($this->impacted as $node) {
            if ($node->distance > $max) {
                $max = $node->distance;
            }
        }
        return $max;
    }

    public function getNodesByDepth(): array
    {
        $grouped = [];
        foreach ($this->impacted as $id => $node) {
            $grouped[$node->distance][] = $node;
        }
        ksort($grouped);
        return $grouped;
    }

    public function getModulesAffected(): array
    {
        $modules = [];
        foreach ($this->impacted as $node) {
            if ($node->node->module !== '') {
                $modules[$node->node->module] = ($modules[$node->node->module] ?? 0) + 1;
            }
        }
        arsort($modules);
        return $modules;
    }
}

final readonly class ImpactedNode
{
    public function __construct(
        public Node   $node,
        public int    $distance,
        /** @var list<list<Edge>> */
        public array  $paths,
    ) {}
}
