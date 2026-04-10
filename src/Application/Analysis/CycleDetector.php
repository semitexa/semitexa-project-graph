<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;

final class CycleDetector
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    /** @return list<Cycle> */
    public function findCycles(?string $module = null, int $maxDepth = 10): array
    {
        $cycles = [];
        $color = [];
        $parent = [];
        $pathEdges = [];

        $nodes = $module !== null
            ? $this->storage->nodes->findByModule($module)
            : $this->storage->nodes->findByType('class');

        foreach ($nodes as $node) {
            $color[$node->id] = 'white';
        }

        foreach ($nodes as $node) {
            if ($color[$node->id] === 'white') {
                $this->dfs($node->id, $color, $parent, $pathEdges, $cycles, $maxDepth, 0);
            }
        }

        return $cycles;
    }

    private function dfs(string $nodeId, array &$color, array &$parent, array &$pathEdges, array &$cycles, int $maxDepth, int $depth): void
    {
        if ($depth > $maxDepth) {
            return;
        }

        $color[$nodeId] = 'gray';
        $pathEdges[$nodeId] = $parent[$nodeId] ?? null;

        $edges = $this->storage->edges->findBySource($nodeId);
        foreach ($edges as $edge) {
            $targetId = $edge->targetId;

            if (($color[$targetId] ?? 'white') === 'gray') {
                $cycleEdges = $this->reconstructCycle($targetId, $nodeId, $edge, $pathEdges);
                if (!empty($cycleEdges)) {
                    $cycleNodes = [];
                    foreach ($cycleEdges as $e) {
                        $cycleNodes[] = $e->sourceId;
                    }
                    $cycleNodes[] = $targetId;
                    $cycles[] = new Cycle(
                        nodes:  $cycleNodes,
                        edges:  $cycleEdges,
                        length: count($cycleEdges),
                    );
                }
            } elseif (($color[$targetId] ?? 'white') === 'white') {
                $parent[$targetId] = $edge;
                $this->dfs($targetId, $color, $parent, $pathEdges, $cycles, $maxDepth, $depth + 1);
            }
        }

        $color[$nodeId] = 'black';
        unset($pathEdges[$nodeId]);
    }

    /** @return list<Edge> */
    private function reconstructCycle(string $cycleStart, string $currentNode, Edge $backEdge, array $pathEdges): array
    {
        $cycleEdges = [$backEdge];
        $node = $currentNode;

        while ($node !== $cycleStart && isset($pathEdges[$node])) {
            $edge = $pathEdges[$node];
            array_unshift($cycleEdges, $edge);
            $node = $edge->sourceId;
        }

        if ($node !== $cycleStart) {
            return [];
        }

        return $cycleEdges;
    }
}

final readonly class Cycle
{
    public function __construct(
        /** @var list<string> */
        public array $nodes,
        /** @var list<Edge> */
        public array $edges,
        public int   $length,
    ) {}
}
