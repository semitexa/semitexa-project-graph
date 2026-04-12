<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class ExecutionFlowExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsPayload::class)
            || $file->hasAttribute(AsPayloadHandler::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClassesWithAttribute(AsPayload::class) as $payload) {
            $attr = $payload->getAttribute(AsPayload::class);
            if ($attr === null) continue;
            $instance = $this->safeNewInstance($attr);
            if ($instance === null) continue;

            $path = $instance->path ?? '';
            $methods = $instance->methods ?? ['GET'];

            if ($path === '') continue;

            $routeId = NodeId::forRoute(implode(',', (array)$methods), $path);
            $payloadId = NodeId::forClass($payload->fqcn);

            $result->addEdge(new Edge(
                sourceId: $payloadId,
                targetId: $routeId,
                type: EdgeType::ServesRoute,
            ));

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
