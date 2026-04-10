<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsPipelineListener;
use Semitexa\Core\Attribute\AsServerLifecycleListener;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class PipelineExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsPipelineListener::class)
            || $file->hasAttribute(AsServerLifecycleListener::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(AsPipelineListener::class)) {
                $attr = $classInfo->getAttribute(AsPipelineListener::class);
                $instance = $attr?->newInstance();
                $phase = $instance?->phase ?? 'default';

                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::PipelinePhase,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: ['phase' => $phase],
                ));

                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'pipeline:' . $phase,
                    type:     EdgeType::PipelinePhase,
                    metadata: ['phase' => $phase],
                ));
            }

            if ($classInfo->hasAttribute(AsServerLifecycleListener::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'lifecycleHook', true);
            }
        }

        return $result;
    }
}
