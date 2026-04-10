<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsScheduledJob;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class SchedulerExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsScheduledJob::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClassesWithAttribute(AsScheduledJob::class) as $classInfo) {
            $attr = $classInfo->getAttribute(AsScheduledJob::class);
            $instance = $attr?->newInstance();

            $result->addNode(new Node(
                id:       NodeId::forClass($classInfo->fqcn),
                type:     NodeType::Job,
                fqcn:     $classInfo->fqcn,
                file:     $file->path,
                line:     $classInfo->startLine,
                endLine:  $classInfo->endLine,
                module:   $file->module,
                metadata: [
                    'cron' => $instance?->cron ?? '',
                ],
            ));

            $cron = $instance?->cron ?? '';
            if ($cron !== '') {
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'cron:' . $cron,
                    type:     EdgeType::ScheduledAs,
                    metadata: ['cron' => $cron],
                ));
            }
        }

        return $result;
    }
}
