<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Graph;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class GraphDiff
{
    /** @var list<Node> */
    private array $addedNodes = [];

    /** @var list<Node> */
    private array $removedNodes = [];

    /** @var list<Edge> */
    private array $addedEdges = [];

    /** @var list<Edge> */
    private array $removedEdges = [];

    private int $removedNodeCount = 0;
    private int $removedEdgeCount = 0;

    public function addNode(Node $node): void
    {
        $this->addedNodes[] = $node;
    }

    public function addEdge(Edge $edge): void
    {
        $this->addedEdges[] = $edge;
    }

    public function recordRemoved(int $removedNodeCount, int $removedEdgeCount): void
    {
        $this->removedNodeCount += $removedNodeCount;
        $this->removedEdgeCount += $removedEdgeCount;
    }

    public function addedNodeCount(): int
    {
        return count($this->addedNodes);
    }

    public function removedNodeCount(): int
    {
        return $this->removedNodeCount;
    }

    public function addedEdgeCount(): int
    {
        return count($this->addedEdges);
    }

    public function removedEdgeCount(): int
    {
        return $this->removedEdgeCount;
    }

    /** @return list<Node> */
    public function addedNodes(): array
    {
        return $this->addedNodes;
    }

    /** @return list<Edge> */
    public function addedEdges(): array
    {
        return $this->addedEdges;
    }
}
