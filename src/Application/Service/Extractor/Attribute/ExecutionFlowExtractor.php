<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class ExecutionFlowExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    private const PAYLOAD_ROUTE_ATTRIBUTES = [
        AsPublicPayload::class,
        AsProtectedPayload::class,
        AsServicePayload::class,
        'Semitexa\Core\Attribute\AsPayload',
    ];

    public function supports(ParsedFile $file): bool
    {
        if ($file->hasAttribute(AsPayloadHandler::class)) {
            return true;
        }
        foreach (self::PAYLOAD_ROUTE_ATTRIBUTES as $attributeClass) {
            if ($file->hasAttribute($attributeClass)) {
                return true;
            }
        }
        return false;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach (self::PAYLOAD_ROUTE_ATTRIBUTES as $attributeClass) {
        foreach ($file->getClassesWithAttribute($attributeClass) as $payload) {
            $attr = $payload->getAttribute($attributeClass);
            if ($attr === null) continue;
            $instance = $this->safeNewInstance($attr);
            if ($instance === null) continue;

            $path = $instance->path ?? '';
            $methods = $instance->methods ?? ['GET'];

            if ($path === '') continue;

            // One route per method, as PayloadExtractor makes them. The methods
            // used to be joined into one id: 37 phantom route:GET,POST:/x
            // nodes on the workspace (measured 2026-10-02) that no request
            // can match, and the flow's entry point was one of them.
            $routeIds = [];
            foreach ((array) $methods as $method) {
                $routeIds[] = NodeId::forRoute((string) $method, $path);
            }
            if ($routeIds === []) continue;
            $routeId = $routeIds[0];
            $payloadId = NodeId::forClass($payload->fqcn);

            foreach ($routeIds as $methodRouteId) {
                $result->addEdge(new Edge(
                    sourceId: $payloadId,
                    targetId: $methodRouteId,
                    type: EdgeType::ServesRoute,
                ));
            }

            $flowName = $this->deriveFlowName($payload->fqcn, $path);
            $flowId = NodeId::forFlow($flowName);

            $flowNode = new Node(
                id: $flowId,
                type: NodeType::ExecutionFlow,
                fqcn: '',
                file: $file->path,
                line: $payload->startLine,
                endLine: $payload->endLine,
                module: $file->module,
                metadata: [
                    'name' => $flowName,
                    'entry_point' => $routeId,
                    'entry_points' => $routeIds,
                    'steps' => [
                        ['order' => 1, 'node' => $payloadId, 'role' => 'payload'],
                    ],
                    'events_emitted' => [],
                ],
            );
            $result->addNode($flowNode);

            $result->addEdge(new Edge(
                sourceId: $payloadId,
                targetId: $flowId,
                type: EdgeType::ParticipatesInFlow,
                metadata: ['role' => 'entry'],
            ));
        }
        }

        foreach ($file->getClassesWithAttribute(AsPayloadHandler::class) as $handler) {
            $attr = $handler->getAttribute(AsPayloadHandler::class);
            if ($attr === null) continue;
            $instance = $this->safeNewInstance($attr);
            if ($instance === null) continue;

            $payloadClass = $instance->payload ?? null;
            $resourceClass = $instance->resource ?? null;
            $execution = $instance->execution ?? 'sync';

            $handlerId = NodeId::forClass($handler->fqcn);

            if ($payloadClass !== null) {
                $result->addEdge(new Edge(
                    sourceId: $handlerId,
                    targetId: NodeId::forClass($payloadClass),
                    type: EdgeType::Handles,
                    metadata: ['execution' => $execution],
                ));

                $flowName = $this->deriveFlowName($payloadClass, null);
                $flowId = NodeId::forFlow($flowName);

                $result->addEdge(new Edge(
                    sourceId: $handlerId,
                    targetId: $flowId,
                    type: EdgeType::ParticipatesInFlow,
                    metadata: ['role' => 'handler', 'execution' => $execution],
                ));
            }

            if ($resourceClass !== null) {
                $result->addEdge(new Edge(
                    sourceId: $handlerId,
                    targetId: NodeId::forClass($resourceClass),
                    type: EdgeType::Produces,
                ));
            }
        }

        return $result;
    }

    private function deriveFlowName(string $fqcn, ?string $path): string
    {
        $shortName = array_slice(explode('\\', $fqcn), -1)[0] ?? 'Unknown';
        $base = preg_replace('/(Payload|Request|Command)$/', '', $shortName) ?? $shortName;
        return $base . 'Flow';
    }
}
