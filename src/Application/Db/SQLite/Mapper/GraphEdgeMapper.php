<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\ProjectGraph\Application\Db\SQLite\Model\GraphEdgeResource;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;

#[AsMapper(
    resourceModel: GraphEdgeResource::class,
    domainModel: Edge::class,
)]
final class GraphEdgeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): Edge
    {
        assert($resourceModel instanceof GraphEdgeResource);

        return new Edge(
            id:       $resourceModel->id,
            sourceId: $resourceModel->source_id,
            targetId: $resourceModel->target_id,
            type:     EdgeType::from($resourceModel->type),
            metadata: json_decode($resourceModel->metadata, true) ?: [],
        );
    }

    public function toSourceModel(object $domainModel): GraphEdgeResource
    {
        assert($domainModel instanceof Edge);

        return new GraphEdgeResource(
            id:        $domainModel->getId(),
            source_id: $domainModel->getSourceId(),
            target_id: $domainModel->getTargetId(),
            type:      $domainModel->getType()->value,
            metadata:  json_encode($domainModel->getMetadata()),
        );
    }
}
