<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * What {@see MovePairing} made of an edge diff.
 */
final readonly class MovedEdges
{
    /**
     * @param list<array{removed: Edge, added: Edge}> $pairs         the same edge, moved; out of the gate
     * @param list<array{removed: Edge, added: Edge}> $farEndChanged a moved class whose edge also changed its far end; a warning
     * @param list<array{from: string, to: string, from_module: string, to_module: string, kept: list<string>}> $movedNodes
     * @param EdgeSetDiff $remaining the unpaired edges: what the orphan check and the reviewer see as changed
     * @param list<array{removed: Edge, added: Edge}> $replaced wiring whose class was replaced by a new one: out of the gate, but shown
     */
    public function __construct(
        public array $pairs,
        public array $farEndChanged,
        public array $movedNodes,
        public EdgeSetDiff $remaining,
        public array $replaced = [],
    ) {}
}
