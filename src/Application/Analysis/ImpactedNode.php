<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final readonly class ImpactedNode
{
    /**
     * @param list<list<Edge>> $paths
     */
    public function __construct(
        public Node $node,
        public int $distance,
        public array $paths,
    ) {}
}
