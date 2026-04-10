<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Mapper;

use Semitexa\Orm\Attribute\AsMapper;
use Semitexa\Orm\Contract\TableModelMapper;
use Semitexa\ProjectGraph\Application\Db\Model\GraphFileIndexTableModel;
use Semitexa\ProjectGraph\Domain\Model\FileIndexEntry;

#[AsMapper(
    tableModel: GraphFileIndexTableModel::class,
    domainModel: FileIndexEntry::class,
)]
final class GraphFileIndexMapper implements TableModelMapper
{
    public function toDomain(object $tableModel): FileIndexEntry
    {
        assert($tableModel instanceof GraphFileIndexTableModel);

        return new FileIndexEntry(
            path:        $tableModel->path,
            contentHash: $tableModel->content_hash,
            indexedAt:   $tableModel->indexed_at,
            module:      $tableModel->module,
            lineCount:   $tableModel->line_count,
            isDirty:     $tableModel->is_dirty,
        );
    }

    public function toTableModel(object $domainModel): GraphFileIndexTableModel
    {
        assert($domainModel instanceof FileIndexEntry);

        return new GraphFileIndexTableModel(
            path:         $domainModel->path,
            content_hash: $domainModel->contentHash,
            indexed_at:   $domainModel->indexedAt,
            module:       $domainModel->module,
            line_count:   $domainModel->lineCount,
            is_dirty:     $domainModel->isDirty,
        );
    }
}
