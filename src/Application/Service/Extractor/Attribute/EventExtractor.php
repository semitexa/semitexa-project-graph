<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Core\Attribute\AsEvent;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\EnumCaseReference;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class EventExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsEventListener::class)
            || $file->hasAttribute(AsEvent::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(AsEvent::class)) {
                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Event,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [],
                ));
            }

            if ($classInfo->hasAttribute(AsEventListener::class)) {
                $attr = $classInfo->getAttribute(AsEventListener::class);
                if ($attr === null) {
                    continue;
                }

                $instance = $this->safeNewInstance($attr);

                if ($instance !== null) {
                    $eventClass = $instance->event ?? null;
                    // A real EventExecution or, from an AttributeStandIn, an EnumCaseReference.
                    $executionMode = EnumCaseReference::scalarOf($instance->execution ?? 'sync');
                } else {
                    // `execution: EventExecution::Async` is an EnumCaseReference
                    // read from the enum's AST, never the loaded enum: when the
                    // attribute could not be built at all, the arguments still
                    // say which mode it is instead of a blanket 'sync'.
                    $args = $this->getAttributeArguments($attr);
                    $eventClass = $args['event'] ?? $args[0] ?? null;
                    $executionMode = EnumCaseReference::scalarOf($args['execution'] ?? $args[1] ?? 'sync');
                }
                $executionMode = is_string($executionMode) ? $executionMode : 'sync';

                $listenerNode = new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::EventListener,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [
                        'eventClass'    => $eventClass,
                        'executionMode' => $executionMode,
                    ],
                );
                $result->addNode($listenerNode);

                if ($eventClass !== null) {
                    $result->addEdge(new Edge(
                        sourceId: $listenerNode->getId(),
                        targetId: NodeId::forClass($eventClass),
                        type:     EdgeType::ListensTo,
                        metadata: ['executionMode' => $executionMode],
                    ));
                }
            }
        }

        return $result;
    }
}
