<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * The structural difference between two graphs: which edges, identified by
 * (type, source, target), one has and the other does not. Node ids are FQCN-
 * or name-based, so graphs built from different checkouts of the same code
 * compare edge for edge.
 */
final readonly class EdgeSetDiff
{
    /**
     * @param list<Edge> $added   in head, not in base
     * @param list<Edge> $removed in base, not in head
     */
    public function __construct(
        public array $added,
        public array $removed,
    ) {}

    public static function between(GraphStorage $base, GraphStorage $head): self
    {
        $baseEdges = self::edges($base);
        $headEdges = self::edges($head);

        return new self(
            array_values(array_diff_key($headEdges, $baseEdges)),
            array_values(array_diff_key($baseEdges, $headEdges)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [];
    }

    /** @return array<string, Edge> keyed by GraphDiff::edgeKey, sorted */
    private static function edges(GraphStorage $storage): array
    {
        $edges = [];
        foreach ($storage->edges->all() as $edge) {
            $edges[GraphDiff::edgeKey($edge)] = $edge;
        }
        ksort($edges);

        return $edges;
    }
}
