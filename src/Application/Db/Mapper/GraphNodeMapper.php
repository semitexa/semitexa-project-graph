<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\ProjectGraph\Application\Db\Model\GraphNodeResource;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

#[AsMapper(
    resourceModel: GraphNodeResource::class,
    domainModel: Node::class,
)]
final class GraphNodeMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): Node
    {
        assert($resourceModel instanceof GraphNodeResource);

        return new Node(
            id:            $resourceModel->id,
            type:          NodeType::from($resourceModel->type),
            fqcn:          $resourceModel->fqcn,
            file:          $resourceModel->file,
            line:          $resourceModel->line,
            endLine:       $resourceModel->end_line,
            module:        $resourceModel->module,
            metadata:      json_decode($resourceModel->metadata, true) ?: [],
            isPlaceholder: $resourceModel->is_placeholder,
        );
    }

    public function toSourceModel(object $domainModel): GraphNodeResource
    {
        assert($domainModel instanceof Node);

        return new GraphNodeResource(
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
