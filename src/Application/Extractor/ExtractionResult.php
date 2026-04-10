<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor;

use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class ExtractionResult
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<Edge> */
    public array $edges = [];

    /** @var array<string, int> nodeId => index in $nodes */
    private array $nodeIndex = [];

    public function addNode(Node $node): self
    {
        $this->nodeIndex[$node->id] = count($this->nodes);
        $this->nodes[] = $node;
        return $this;
    }

    public function addEdge(Edge $edge): self
    {
        $this->edges[] = $edge;
        return $this;
    }

    public function addNodeMetadata(string $nodeId, string $key, mixed $value): self
    {
        if (!isset($this->nodeIndex[$nodeId])) {
            return $this;
        }
        $idx = $this->nodeIndex[$nodeId];
        $node = $this->nodes[$idx];
        $this->nodes[$idx] = new Node(
            id:            $node->id,
            type:          $node->type,
            fqcn:          $node->fqcn,
            file:          $node->file,
            line:          $node->line,
            endLine:       $node->endLine,
            module:        $node->module,
            metadata:      array_merge($node->metadata, [$key => $value]),
            isPlaceholder: $node->isPlaceholder,
        );
        return $this;
    }

    public function merge(self $other): self
    {
        $result = new self();
        $result->nodes = [...$this->nodes, ...$other->nodes];
        $result->edges = [...$this->edges, ...$other->edges];
        $result->nodeIndex = $this->nodeIndex;
        $offset = count($this->nodes);
        foreach ($other->nodeIndex as $id => $idx) {
            $result->nodeIndex[$id] = $offset + $idx;
        }
        return $result;
    }

    public static function empty(): self
    {
        return new self();
    }
}
