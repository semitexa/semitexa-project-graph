<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\SQLite\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Domain\Contract\ResourceModelMapperInterface;
use Semitexa\ProjectGraph\Application\Db\SQLite\Model\GraphNodeResource;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;

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
            id:             $domainModel->getId(),
            type:           $domainModel->getType()->value,
            fqcn:           $domainModel->getFqcn(),
            name:           $this->extractShortName($domainModel->getFqcn()),
            file:           $domainModel->getFile(),
            line:           $domainModel->getLine(),
            end_line:       $domainModel->getEndLine(),
            module:         $domainModel->getModule(),
            metadata:       json_encode($domainModel->getMetadata()),
            is_placeholder: $domainModel->getIsPlaceholder(),
        );
    }

    private function extractShortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos !== false ? substr($fqcn, $pos + 1) : $fqcn;
    }
}
