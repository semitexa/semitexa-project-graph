<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Intelligence;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
use Semitexa\ProjectGraph\Application\Service\Query\QueryInterface;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class IntelligenceLayer
{
    public function __construct(
        private readonly QueryInterface $query,
    ) {}

    public function getDomainContext(string $nodeId): ?DomainContext
    {
        $edges = $this->query->getEdges($nodeId, EdgeType::BelongsToDomain->value, Direction::Outgoing);
        if ($edges === []) {
            return null;
        }

        $domainNode = $this->query->getNode($edges[0]->getTargetId());
        if ($domainNode === null) {
            return null;
        }

        return DomainContext::fromNode($domainNode);
    }

    public function getExecutionFlow(string $flowName): ?ExecutionFlow
    {
        $flowId = NodeId::forFlow($flowName);
        $flowNode = $this->query->getNode($flowId);
        if ($flowNode === null) {
            return null;
        }

        $steps = $flowNode->getMetadata()['steps'] ?? [];
        $eventsEmitted = $flowNode->getMetadata()['events_emitted'] ?? [];

        $stepNodes = [];
        foreach ($steps as $step) {
            $node = $this->query->getNode($step['node'] ?? '');
            if ($node !== null) {
                $stepNodes[] = [
                    'order' => $step['order'] ?? 0,
                    'node' => $node->getFqcn() ?: $step['node'],
                    'role' => $step['role'] ?? '',
                ];
            }
        }

        return new ExecutionFlow(
            id: $flowId,
            name: $flowName,
            entryPoint: $flowNode->getMetadata()['entry_point'] ?? '',
            steps: $stepNodes,
            storageTouches: $flowNode->getMetadata()['storage_touches'] ?? [],
            externalCalls: $flowNode->getMetadata()['external_calls'] ?? [],
            syncBoundary: $flowNode->getMetadata()['sync_boundary'] ?? null,
            eventsEmitted: $eventsEmitted,
        );
    }

    public function getEventLifecycle(string $eventClass): ?EventLifecycle
    {
        $eventId = NodeId::forClass($eventClass);
        $eventNode = $this->query->getNode($eventId);
        if ($eventNode === null) {
            return null;
        }

        $emitters = [];
        $emitEdges = $this->query->getEdges($eventId, EdgeType::Emits->value, Direction::Incoming);
        foreach ($emitEdges as $edge) {
            $emitters[] = $edge->getSourceId();
        }

        $syncListeners = [];
        $asyncListeners = [];
        $queuedListeners = [];
        $listenEdges = $this->query->getEdges($eventId, EdgeType::ListensTo->value, Direction::Incoming);
        foreach ($listenEdges as $edge) {
            $execMode = $edge->getMetadata()['executionMode'] ?? 'sync';
            $listenerNode = $this->query->getNode($edge->getSourceId());
            $listenerName = $listenerNode?->getFqcn() ?: $edge->getSourceId();

            match ($execMode) {
                'async' => $asyncListeners[] = $listenerName,
                'queued' => $queuedListeners[] = [
                    'class' => $listenerName,
                    'queue' => $listenerNode?->getMetadata()['queue'] ?? null,
                ],
                default => $syncListeners[] = $listenerName,
            };
        }

        $natsSubject = null;
        $jetstream = null;
        $replayHandlers = [];
        $publishEdges = $this->query->getEdges($eventId, EdgeType::PublishesTo->value, Direction::Outgoing);
        if ($publishEdges !== []) {
            $subjectNode = $this->query->getNode($publishEdges[0]->getTargetId());
            $natsSubject = $subjectNode?->getMetadata()['pattern'] ?? $publishEdges[0]->getTargetId();
            $jetstream = $subjectNode?->getMetadata()['stream'] ?? 'EVENTS';

            $consumerEdges = $this->query->getEdges($publishEdges[0]->getTargetId(), EdgeType::ConsumesFrom->value, Direction::Incoming);
            foreach ($consumerEdges as $edge) {
                $consumerNode = $this->query->getNode($edge->getSourceId());
                if ($consumerNode !== null) {
                    $replayHandlers[] = $consumerNode->getFqcn() ?: $edge->getSourceId();
                }
            }
        }

        return new EventLifecycle(
            eventClass: $eventClass,
            emitters: $emitters,
            syncListeners: $syncListeners,
            asyncListeners: $asyncListeners,
            queuedListeners: $queuedListeners,
            natsSubject: $natsSubject,
            jetstream: $jetstream,
            replayHandlers: $replayHandlers,
            dlqPath: null,
            retryConfig: null,
            idempotencyKey: 'event_id',
        );
    }

    public function getHotspots(int $limit = 10): array
    {
        $hotspotNodes = $this->query->findNodes(type: NodeType::Hotspot->value);

        $hotspots = [];
        foreach ($hotspotNodes as $node) {
            $hotspots[] = new Hotspot(
                nodeId: $node->getMetadata()['target_node_id'] ?? $node->getId(),
                riskScore: (float) ($node->getMetadata()['risk_score'] ?? 0),
                incomingEdges: (int) ($node->getMetadata()['incoming_edges'] ?? 0),
                crossModuleDeps: (int) ($node->getMetadata()['cross_module_deps'] ?? 0),
                isCriticalPath: (bool) ($node->getMetadata()['is_critical_path'] ?? false),
                complexityScore: (float) ($node->getMetadata()['complexity_score'] ?? 0),
                recommendation: $node->getMetadata()['recommendation'] ?? null,
            );
        }

        usort($hotspots, fn($a, $b) => $b->riskScore <=> $a->riskScore);
        return array_slice($hotspots, 0, $limit);
    }

    public function getIntent(string $nodeId): ?IntentInference
    {
        $docEdges = $this->query->getEdges($nodeId, EdgeType::IntentFor->value, Direction::Outgoing);
        if ($docEdges === []) {
            return null;
        }

        $docNode = $this->query->getNode($docEdges[0]->getTargetId());
        if ($docNode === null) {
            return null;
        }

        return new IntentInference(
            nodeId: $nodeId,
            purpose: $docNode->getMetadata()['purpose'] ?? '',
            responsibilities: $docNode->getMetadata()['responsibilities'] ?? [],
            inferredFrom: $docNode->getMetadata()['inferred_from'] ?? [],
            confidence: (float) ($docNode->getMetadata()['confidence'] ?? 0),
        );
    }

    public function getPublishedSubjects(string $nodeId): array
    {
        $edges = $this->query->getEdges($nodeId, EdgeType::PublishesTo->value, Direction::Outgoing);
        $subjects = [];
        foreach ($edges as $edge) {
            $subjectNode = $this->query->getNode($edge->getTargetId());
            $subjects[] = [
                'id' => $edge->getTargetId(),
                'pattern' => $subjectNode?->getMetadata()['pattern'] ?? $edge->getTargetId(),
                'domain' => $subjectNode?->getMetadata()['domain'] ?? null,
                'event_type' => $subjectNode?->getMetadata()['event_type'] ?? null,
            ];
        }
        return $subjects;
    }

    public function getSubjectConsumers(string $subjectPattern): array
    {
        $subjectId = NodeId::forSubject($subjectPattern);
        $edges = $this->query->getEdges($subjectId, EdgeType::ConsumesFrom->value, Direction::Incoming);
        $consumers = [];
        foreach ($edges as $edge) {
            $consumerNode = $this->query->getNode($edge->getSourceId());
            $consumers[] = [
                'id' => $edge->getSourceId(),
                'fqcn' => $consumerNode?->getFqcn() ?? '',
                'metadata' => $consumerNode?->getMetadata() ?? [],
            ];
        }
        return $consumers;
    }

    public function getFlowsForModule(string $module): array
    {
        $flowNodes = $this->query->findNodes(type: NodeType::ExecutionFlow->value, module: $module);
        $flows = [];
        foreach ($flowNodes as $node) {
            $flows[] = [
                'id' => $node->getId(),
                'name' => $node->getMetadata()['name'] ?? $node->getId(),
                'entry_point' => $node->getMetadata()['entry_point'] ?? '',
            ];
        }
        return $flows;
    }

    public function getDocGaps(?string $module = null): array
    {
        $nodes = $module !== null
            ? $this->query->findNodes(module: $module)
            : $this->query->findNodes();

        $gaps = [];
        foreach ($nodes as $node) {
            if ($node->getType() === NodeType::DocNode || $node->getType() === NodeType::Hotspot) {
                continue;
            }

            $hasIntent = $this->query->getEdges($node->getId(), EdgeType::IntentFor->value, Direction::Outgoing);
            if ($hasIntent !== []) {
                continue;
            }

            $score = $this->scoreDocGap($node);
            if ($score > 20) {
                $gaps[] = ['node' => $node, 'score' => $score];
            }
        }

        usort($gaps, fn($a, $b) => $b['score'] <=> $a['score']);
        return $gaps;
    }

    private function scoreDocGap(Node $node): int
    {
        $score = 0;

        if (str_starts_with($node->getFqcn(), 'App\\Api\\')) $score += 30;

        $publicAttrs = ['AsPayload', 'AsPayloadHandler', 'AsService', 'AsEvent'];
        foreach ($publicAttrs as $attr) {
            if (str_contains($node->getId(), $attr)) $score += 20;
        }

        $deps = $this->query->getEdges($node->getId());
        $score += min(count($deps) * 2, 20);

        if ($node->getModule() !== '') {
            $crossModule = $this->query->getCrossModuleEdges($node->getModule());
            $score += min(count($crossModule), 15);
        }

        return $score;
    }
}
