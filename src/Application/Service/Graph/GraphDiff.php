<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * What one apply() actually changed.
 *
 * An edge is identified by (type, source, target): a re-read file whose code
 * did not change adds and removes nothing, however many edges it re-emits.
 * This used to count every edge of a re-read file as added and every removal
 * as zero.
 */
final class GraphDiff
{
    /** @var list<Node> */
    private array $addedNodes = [];

    /** @var list<string> */
    private array $removedNodeIds = [];

    /** @var list<Edge> */
    private array $addedEdges = [];

    /** @var list<Edge> */
    private array $removedEdges = [];

    public function addNode(Node $node): void
    {
        $this->addedNodes[] = $node;
    }

    public function removeNode(string $nodeId): void
    {
        $this->removedNodeIds[] = $nodeId;
    }

    public function addEdge(Edge $edge): void
    {
        $this->addedEdges[] = $edge;
    }

    public function removeEdge(Edge $edge): void
    {
        $this->removedEdges[] = $edge;
    }

    public function addedNodeCount(): int
    {
        return count($this->addedNodes);
    }

    public function removedNodeCount(): int
    {
        return count($this->removedNodeIds);
    }

    public function addedEdgeCount(): int
    {
        return count($this->addedEdges);
    }

    public function removedEdgeCount(): int
    {
        return count($this->removedEdges);
    }

    /** @return list<Node> */
    public function addedNodes(): array
    {
        return $this->addedNodes;
    }

    /** @return list<string> */
    public function removedNodeIds(): array
    {
        return $this->removedNodeIds;
    }

    /** @return list<Edge> */
    public function addedEdges(): array
    {
        return $this->addedEdges;
    }

    /** @return list<Edge> */
    public function removedEdges(): array
    {
        return $this->removedEdges;
    }

    /** The key an edge is identified by across builds. */
    public static function edgeKey(Edge $edge): string
    {
        return $edge->getType()->value . "\0" . $edge->getSourceId() . "\0" . $edge->getTargetId();
    }
}
