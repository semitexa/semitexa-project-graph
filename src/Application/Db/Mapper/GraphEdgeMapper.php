<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\ProjectGraph\Application\Db\Model\GraphEdgeTableModel;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;

#[AsMapper(
    tableModel: GraphEdgeTableModel::class,
    domainModel: Edge::class,
)]
final class GraphEdgeMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): Edge
    {
        assert($tableModel instanceof GraphEdgeTableModel);

        return new Edge(
            id:       $tableModel->id,
            sourceId: $tableModel->source_id,
            targetId: $tableModel->target_id,
            type:     EdgeType::from($tableModel->type),
            metadata: json_decode($tableModel->metadata, true) ?: [],
        );
    }

    public function toTableModel(object $domainModel): GraphEdgeTableModel
    {
        assert($domainModel instanceof Edge);

        return new GraphEdgeTableModel(
            id:        $domainModel->id ?? 0,
            source_id: $domainModel->sourceId,
            target_id: $domainModel->targetId,
            type:      $domainModel->type->value,
            metadata:  json_encode($domainModel->metadata),
        );
    }
}
