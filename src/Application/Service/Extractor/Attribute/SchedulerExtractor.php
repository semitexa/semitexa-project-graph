<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Scheduler\Attribute\AsScheduledJob;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

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
