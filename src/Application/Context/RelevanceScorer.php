<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Context;

use Semitexa\ProjectGraph\Application\Analysis\ImpactedNode;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

final class RelevanceScorer
{
    private const TYPE_WEIGHTS = [
        NodeType::Payload->value       => 10,
        NodeType::Handler->value       => 10,
        NodeType::Service->value       => 8,
        NodeType::Entity->value        => 7,
        NodeType::Resource->value      => 7,
        NodeType::EventListener->value => 6,
        NodeType::Event->value         => 5,
        NodeType::Command->value       => 5,
        NodeType::Component->value     => 4,
        NodeType::Repository->value    => 6,
        NodeType::Job->value           => 4,
        NodeType::Route->value         => 8,
    ];

    private const EDGE_WEIGHTS = [
        EdgeType::Handles->value           => 10,
        EdgeType::Produces->value          => 8,
        EdgeType::InjectsReadonly->value   => 7,
        EdgeType::InjectsMutable->value    => 9,
        EdgeType::SatisfiesContract->value => 8,
        EdgeType::Extends->value           => 6,
        EdgeType::Implements->value        => 5,
        EdgeType::Calls->value             => 4,
        EdgeType::ListensTo->value         => 6,
        EdgeType::Emits->value             => 7,
        EdgeType::HasRelation->value       => 7,
        EdgeType::MapsToTable->value       => 6,
    ];

    public function score(Node $node, int $distance = 1): float
    {
        $typeWeight = self::TYPE_WEIGHTS[$node->type->value] ?? 3;
        $distanceFactor = 1.0 / max(1, $distance);
        return $typeWeight * $distanceFactor;
    }

    public function scoreEdge(Edge $edge): float
    {
        return self::EDGE_WEIGHTS[$edge->type->value] ?? 3;
    }

    public function rank(array $impactedNodes): array
    {
        $scored = [];
        foreach ($impactedNodes as $id => $impacted) {
            assert($impacted instanceof ImpactedNode);
            $score = $this->score($impacted->node, $impacted->distance);
            $scored[] = ['node' => $impacted->node, 'score' => $score, 'distance' => $impacted->distance];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }
}
