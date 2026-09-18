<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

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
        if (isset($this->nodeIndex[$node->getId()])) {
            $idx = $this->nodeIndex[$node->getId()];
            $existing = $this->nodes[$idx];
            $this->nodes[$idx] = $this->mergeNode($existing, $node);

            return $this;
        }

        $this->nodeIndex[$node->getId()] = count($this->nodes);
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
            id:            $node->getId(),
            type:          $node->getType(),
            fqcn:          $node->getFqcn(),
            file:          $node->getFile(),
            line:          $node->getLine(),
            endLine:       $node->getEndLine(),
            module:        $node->getModule(),
            metadata:      array_merge($node->getMetadata(), [$key => $value]),
            isPlaceholder: $node->getIsPlaceholder(),
        );
        return $this;
    }

    public function merge(self $other): self
    {
        $result = new self();

        foreach ($this->nodes as $node) {
            $result->addNode($node);
        }

        foreach ($other->nodes as $node) {
            $result->addNode($node);
        }

        foreach ($this->edges as $edge) {
            $result->addEdge($edge);
        }

        foreach ($other->edges as $edge) {
            $result->addEdge($edge);
        }

        return $result;
    }

    public static function empty(): self
    {
        return new self();
    }

    private function mergeNode(Node $existing, Node $incoming): Node
    {
        $winner = $this->typePriority($incoming->getType()->value) >= $this->typePriority($existing->getType()->value)
            ? $incoming
            : $existing;
        $loser = $winner === $incoming ? $existing : $incoming;

        return new Node(
            id:            $winner->getId(),
            type:          $winner->getType(),
            fqcn:          $winner->getFqcn() !== '' ? $winner->getFqcn() : $loser->getFqcn(),
            file:          $winner->getFile() !== '' ? $winner->getFile() : $loser->getFile(),
            line:          $winner->getLine() !== 0 ? $winner->getLine() : $loser->getLine(),
            endLine:       $winner->getEndLine() !== 0 ? $winner->getEndLine() : $loser->getEndLine(),
            module:        $winner->getModule() !== '' ? $winner->getModule() : $loser->getModule(),
            metadata:      array_merge($loser->getMetadata(), $winner->getMetadata()),
            isPlaceholder: $existing->getIsPlaceholder() && $incoming->getIsPlaceholder(),
        );
    }

    private function typePriority(string $type): int
    {
        return match ($type) {
            'command' => 220,
            'payload', 'handler', 'service', 'event_listener', 'event', 'entity', 'repository', 'job',
            'workflow', 'ai_skill', 'contract', 'pipeline_phase', 'slot_handler', 'auth_handler',
            'data_provider', 'resource', 'component', 'module', 'namespace', 'file' => 180,
            'route', 'method', 'property', 'constant', 'enum_case' => 140,
            'class', 'interface', 'trait', 'enum' => 20,
            'domain_context', 'execution_flow', 'event_flow', 'data_lifecycle',
            'system_boundary', 'hotspot', 'jetstream', 'nats_subject', 'consumer',
            'event_schema', 'aggregate_root', 'replay_path', 'doc_node',
            'usage_example', 'architectural_decision' => 160,
            default => 100,
        };
    }
}
