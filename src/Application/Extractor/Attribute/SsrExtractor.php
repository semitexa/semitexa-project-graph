<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsComponent;
use Semitexa\Core\Attribute\AsDataProvider;
use Semitexa\Core\Attribute\AsDeferred;
use Semitexa\Core\Attribute\AsLayoutSlot;
use Semitexa\Core\Attribute\AsSlotHandler;
use Semitexa\Core\Attribute\AsSlotResource;
use Semitexa\Core\Attribute\AsTwigExtension;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class SsrExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsComponent::class)
            || $file->hasAttribute(AsSlotHandler::class)
            || $file->hasAttribute(AsDataProvider::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(AsComponent::class)) {
                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Component,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [],
                ));
            }

            if ($classInfo->hasAttribute(AsSlotHandler::class)) {
                $attr = $classInfo->getAttribute(AsSlotHandler::class);
                $instance = $attr?->newInstance();
                $slotName = $instance?->slot ?? 'default';

                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::SlotHandler,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: ['slot' => $slotName],
                ));

                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'slot:' . $slotName,
                    type:     EdgeType::RendersSlot,
                    metadata: ['slotName' => $slotName],
                ));

                if ($classInfo->hasAttribute(AsLayoutSlot::class)) {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($classInfo->fqcn),
                        targetId: 'slot:' . $slotName,
                        type:     EdgeType::RendersSlot,
                        metadata: ['layout' => true],
                    ));
                }

                if ($classInfo->hasAttribute(AsDeferred::class)) {
                    $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'deferred', true);
                }
            }

            if ($classInfo->hasAttribute(AsDataProvider::class)) {
                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::DataProvider,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [],
                ));
            }
        }

        return $result;
    }
}
