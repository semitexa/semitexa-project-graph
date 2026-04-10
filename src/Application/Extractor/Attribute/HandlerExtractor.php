<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsResourcePart;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class HandlerExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsPayloadHandler::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClassesWithAttribute(AsPayloadHandler::class) as $classInfo) {
            $attr = $classInfo->getAttribute(AsPayloadHandler::class);
            if ($attr === null) {
                continue;
            }
            $asHandler = $attr->newInstance();

            $handlerNode = new Node(
                id:       NodeId::forClass($classInfo->fqcn),
                type:     NodeType::Handler,
                fqcn:     $classInfo->fqcn,
                file:     $file->path,
                line:     $classInfo->startLine,
                endLine:  $classInfo->endLine,
                module:   $file->module,
                metadata: [
                    'execution' => $asHandler->execution ?? 'sync',
                ],
            );
            $result->addNode($handlerNode);

            $payloadClass = $asHandler->payload ?? null;
            if ($payloadClass !== null) {
                $result->addEdge(new Edge(
                    sourceId: $handlerNode->id,
                    targetId: NodeId::forClass($payloadClass),
                    type:     EdgeType::Handles,
                    metadata: [],
                ));
            }

            $resourceClass = $asHandler->resource ?? null;
            if ($resourceClass !== null) {
                $resourceNode = new Node(
                    id:       NodeId::forClass($resourceClass),
                    type:     NodeType::Resource,
                    fqcn:     $resourceClass,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [],
                );
                $result->addNode($resourceNode);

                $result->addEdge(new Edge(
                    sourceId: $handlerNode->id,
                    targetId: $resourceNode->id,
                    type:     EdgeType::Produces,
                    metadata: [],
                ));
            }

            foreach ($classInfo->usedTraits as $traitFqcn) {
                $result->addEdge(new Edge(
                    sourceId: $handlerNode->id,
                    targetId: NodeId::forClass($traitFqcn),
                    type:     EdgeType::ComposedOf,
                    metadata: [],
                ));
            }
        }

        return $result;
    }
}
