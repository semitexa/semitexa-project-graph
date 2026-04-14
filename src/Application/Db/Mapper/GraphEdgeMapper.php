<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\ProjectGraph\Application\Db\Model\GraphEdgeResource;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;

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
            id:        $domainModel->id,
            source_id: $domainModel->sourceId,
            target_id: $domainModel->targetId,
            type:      $domainModel->type->value,
            metadata:  json_encode($domainModel->metadata),
        );
    }
}
