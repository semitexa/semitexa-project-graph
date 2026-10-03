<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Ssr\Attribute\AsComponent;
use Semitexa\Ssr\Attribute\AsDataProvider;
use Semitexa\Ssr\Attribute\AsDeferred;
use Semitexa\Ssr\Attribute\AsLayoutSlot;
use Semitexa\Ssr\Attribute\AsSlotHandler;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

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

                // #[AsComponent(event: X::class)] names the event the component
                // raises: it emits X, though no dispatch() call says so (round
                // 2, 2026-10-02: DisclosurePromptComponent emitting
                // DemoDisclosureExpanded was missing from "who emits X"). A
                // plain DOM event name ('click') is not a class: only a
                // namespaced name counts.
                $arguments = $classInfo->getAttribute(AsComponent::class)?->unreadableReason() === null
                    ? $classInfo->getAttribute(AsComponent::class)?->getArguments() ?? []
                    : [];
                $event = $arguments['event'] ?? $arguments[4] ?? null;
                if (is_string($event) && str_contains(ltrim($event, '\\'), '\\')) {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($classInfo->fqcn),
                        targetId: NodeId::forClass(ltrim($event, '\\')),
                        type:     EdgeType::Emits,
                        metadata: ['via' => 'component'],
                    ));
                }
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
