<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Query;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final readonly class ImpactResult
{
    public function __construct(
        /** @var list<string> */
        public array $changed,
        /** @var array<string, ImpactedNode> */
        public array $impacted,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }
}

final readonly class ImpactedNode
{
    public function __construct(
        public Node   $node,
        public int    $distance,
        /** @var list<list<Edge>> paths from changed node to this node */
        public array  $paths,
    ) {}
}
