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
        if (isset($this->nodeIndex[$node->id])) {
            $idx = $this->nodeIndex[$node->id];
            $existing = $this->nodes[$idx];
            $this->nodes[$idx] = $this->mergeNode($existing, $node);

            return $this;
        }

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
        $winner = $this->typePriority($incoming->type->value) >= $this->typePriority($existing->type->value)
            ? $incoming
            : $existing;
        $loser = $winner === $incoming ? $existing : $incoming;

        return new Node(
            id:            $winner->id,
            type:          $winner->type,
            fqcn:          $winner->fqcn !== '' ? $winner->fqcn : $loser->fqcn,
            file:          $winner->file !== '' ? $winner->file : $loser->file,
            line:          $winner->line !== 0 ? $winner->line : $loser->line,
            endLine:       $winner->endLine !== 0 ? $winner->endLine : $loser->endLine,
            module:        $winner->module !== '' ? $winner->module : $loser->module,
            metadata:      array_merge($loser->metadata, $winner->metadata),
            isPlaceholder: $existing->isPlaceholder && $incoming->isPlaceholder,
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
