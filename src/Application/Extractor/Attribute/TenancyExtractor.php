<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsTenancyLayersProvider;
use Semitexa\Core\Attribute\AsTenantLayerStrategy;
use Semitexa\Core\Attribute\TenantIsolated;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class TenancyExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(TenantIsolated::class)
            || $file->hasAttribute(AsTenantLayerStrategy::class)
            || $file->hasAttribute(AsTenancyLayersProvider::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            foreach ($classInfo->getAttributes(TenantIsolated::class) as $attr) {
                $instance = $attr->newInstance();
                $constraint = $instance->constraint ?? 'default';
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'constraint:' . $constraint,
                    type:     EdgeType::TenantIsolated,
                    metadata: ['constraint' => $constraint],
                ));
            }

            if ($classInfo->hasAttribute(AsTenantLayerStrategy::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'tenantLayerStrategy', true);
            }

            if ($classInfo->hasAttribute(AsTenancyLayersProvider::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'tenancyLayersProvider', true);
            }
        }

        return $result;
    }
}
