<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class HandlerExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

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
            $asHandler = $this->safeNewInstance($attr);
            if ($asHandler === null) {
                continue;
            }

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
                    sourceId: $handlerNode->getId(),
                    targetId: NodeId::forClass($payloadClass),
                    type:     EdgeType::Handles,
                    metadata: [],
                ));
            }

            $resourceClass = $asHandler->resource ?? null;
            if ($resourceClass !== null) {
                // The resource class is usually declared in another file. This
                // file only says what ROLE it plays, so the node is a
                // placeholder: it must not claim the class for this file — that
                // made re-indexing the handler remove the resource, and kept the
                // resource's own declaration out of the graph. Declared here, it
                // is the resource's OWN declaration that gives the lines: the
                // handler's were used (reproduced 2026-10-02), and the resource
                // node won the merge with them.
                $declaration = null;
                foreach ($file->getClasses() as $declared) {
                    if ($declared->fqcn === $resourceClass) {
                        $declaration = $declared;
                    }
                }
                $resourceNode = new Node(
                    id:            NodeId::forClass($resourceClass),
                    type:          NodeType::Resource,
                    fqcn:          $resourceClass,
                    file:          $declaration !== null ? $file->path : '',
                    line:          $declaration?->startLine ?? 0,
                    endLine:       $declaration?->endLine ?? 0,
                    module:        $declaration !== null ? $file->module : '',
                    metadata:      [],
                    isPlaceholder: $declaration === null,
                );
                $result->addNode($resourceNode);

                $result->addEdge(new Edge(
                    sourceId: $handlerNode->getId(),
                    targetId: $resourceNode->getId(),
                    type:     EdgeType::Produces,
                    metadata: [],
                ));
            }

            foreach ($classInfo->usedTraits as $traitFqcn) {
                $result->addEdge(new Edge(
                    sourceId: $handlerNode->getId(),
                    targetId: NodeId::forClass($traitFqcn),
                    type:     EdgeType::ComposedOf,
                    metadata: [],
                ));
            }
        }

        return $result;
    }
}
