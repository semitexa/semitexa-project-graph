<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

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
        /** The walk stopped at {@see GraphQueryService::VIEW_MAX_NODES}: the view is a part. */
        public bool  $truncated = false,
    ) {}
}
