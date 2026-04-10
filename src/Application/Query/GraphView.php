<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Query;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final readonly class GraphView
{
    public function __construct(
        /** @var list<Node> */
        public array $nodes,
        /** @var list<Edge> */
        public array $edges,
        /** @var array<string, int> */
        public array $nodeTypeCounts,
        /** @var array<string, int> */
        public array $edgeTypeCounts,
        public int   $totalNodes,
        public int   $totalEdges,
        public int   $crossModuleEdges,
        public int   $orphanNodes,
        public int   $placeholderNodes,
        /** @var array<string, int> */
        public array $moduleCounts,
    ) {}
}
