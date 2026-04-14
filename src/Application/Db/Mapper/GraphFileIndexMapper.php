<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\ResourceModelMapperInterface;
use Semitexa\ProjectGraph\Application\Db\Model\GraphFileIndexResource;
use Semitexa\ProjectGraph\Domain\Model\FileIndexEntry;

#[AsMapper(
    resourceModel: GraphFileIndexResource::class,
    domainModel: FileIndexEntry::class,
)]
final class GraphFileIndexMapper implements ResourceModelMapperInterface
{
    public function toDomain(object $resourceModel): FileIndexEntry
    {
        assert($resourceModel instanceof GraphFileIndexResource);

        return new FileIndexEntry(
            path:        $resourceModel->path,
            contentHash: $resourceModel->content_hash,
            indexedAt:   $resourceModel->indexed_at,
            module:      $resourceModel->module,
            lineCount:   $resourceModel->line_count,
            isDirty:     $resourceModel->is_dirty,
        );
    }

    public function toSourceModel(object $domainModel): GraphFileIndexResource
    {
        assert($domainModel instanceof FileIndexEntry);

        return new GraphFileIndexResource(
            path:         $domainModel->path,
            content_hash: $domainModel->contentHash,
            indexed_at:   $domainModel->indexedAt,
            module:       $domainModel->module,
            line_count:   $domainModel->lineCount,
            is_dirty:     $domainModel->isDirty,
        );
    }
}
