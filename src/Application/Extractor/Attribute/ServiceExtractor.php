<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class ServiceExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsService::class)
            || $file->hasAttribute(SatisfiesServiceContract::class)
            || $file->hasAttribute(SatisfiesRepositoryContract::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(AsService::class)) {
                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Service,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: ['scope' => 'worker'],
                ));
            }

            foreach ($classInfo->getAttributes(SatisfiesServiceContract::class) as $attr) {
                $instance = $attr->newInstance();
                $contractFqcn = $instance->contract ?? null;
                if ($contractFqcn !== null) {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($classInfo->fqcn),
                        targetId: NodeId::forClass($contractFqcn),
                        type:     EdgeType::SatisfiesContract,
                        metadata: ['contractType' => 'service'],
                    ));
                }
            }

            foreach ($classInfo->getAttributes(SatisfiesRepositoryContract::class) as $attr) {
                $instance = $attr->newInstance();
                $contractFqcn = $instance->contract ?? null;
                if ($contractFqcn !== null) {
                    $result->addEdge(new Edge(
                        sourceId: NodeId::forClass($classInfo->fqcn),
                        targetId: NodeId::forClass($contractFqcn),
                        type:     EdgeType::SatisfiesContract,
                        metadata: ['contractType' => 'repository'],
                    ));
                }
            }
        }

        return $result;
    }
}
