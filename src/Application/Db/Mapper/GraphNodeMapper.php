<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\ProjectGraph\Application\Db\Model\GraphNodeTableModel;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

#[AsMapper(
    tableModel: GraphNodeTableModel::class,
    domainModel: Node::class,
)]
final class GraphNodeMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): Node
    {
        assert($tableModel instanceof GraphNodeTableModel);

        return new Node(
            id:            $tableModel->id,
            type:          NodeType::from($tableModel->type),
            fqcn:          $tableModel->fqcn,
            file:          $tableModel->file,
            line:          $tableModel->line,
            endLine:       $tableModel->end_line,
            module:        $tableModel->module,
            metadata:      json_decode($tableModel->metadata, true) ?: [],
            isPlaceholder: $tableModel->is_placeholder,
        );
    }

    public function toTableModel(object $domainModel): GraphNodeTableModel
    {
        assert($domainModel instanceof Node);

        return new GraphNodeTableModel(
            id:             $domainModel->id,
            type:           $domainModel->type->value,
            fqcn:           $domainModel->fqcn,
            name:           $this->extractShortName($domainModel->fqcn),
            file:           $domainModel->file,
            line:           $domainModel->line,
            end_line:       $domainModel->endLine,
            module:         $domainModel->module,
            metadata:       json_encode($domainModel->metadata),
            is_placeholder: $domainModel->isPlaceholder,
        );
    }

    private function extractShortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
