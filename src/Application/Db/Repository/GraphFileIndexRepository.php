<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\TableModelHydrator;
use Semitexa\Orm\Hydration\TableModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\TableModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\TableModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphFileIndexTableModel;
use Semitexa\ProjectGraph\Domain\Model\FileIndexEntry;

final class GraphFileIndexRepository
{
    private TableModelQuery $query;

    public function __construct(
        private readonly DatabaseAdapterInterface $adapter,
        private readonly MapperRegistry $mapperRegistry,
        private readonly TableModelHydrator $hydrator,
        private readonly TableModelMetadataRegistry $metadataRegistry,
        private readonly TableModelRelationLoader $relationLoader,
        private readonly AggregateWriteEngine $writeEngine,
    ) {
        $this->query = new TableModelQuery(
            GraphFileIndexTableModel::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    public function findByPath(string $path): ?FileIndexEntry
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphFileIndexTableModel::class, 'path'), Operator::Equals, $path)
            ->fetchOneAs(FileIndexEntry::class, $this->mapperRegistry) ?: null;
    }

    public function getHash(string $path): ?string
    {
        $entry = $this->findByPath($path);
        return $entry?->contentHash;
    }

    /** @return array<string, string> path => hash */
    public function getAll(): array
    {
        $rows = $this->adapter->execute('SELECT path, content_hash FROM graph_file_index')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['path']] = $row['content_hash'];
        }
        return $result;
    }

    public function upsert(string $path, string $hash, string $module = '', int $lineCount = 0): void
    {
        $entry = new FileIndexEntry(
            path:        $path,
            contentHash: $hash,
            indexedAt:   time(),
            module:      $module,
            lineCount:   $lineCount,
            isDirty:     false,
        );

        $existing = $this->findByPath($path);
        if ($existing !== null) {
            $this->writeEngine->update($entry, GraphFileIndexTableModel::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($entry, GraphFileIndexTableModel::class, $this->mapperRegistry);
        }
    }

    public function remove(string $path): void
    {
        $this->adapter->execute(
            'DELETE FROM graph_file_index WHERE path = :path',
            ['path' => $path],
        );
    }

    public function markDirty(string $path): void
    {
        $this->adapter->execute(
            'UPDATE graph_file_index SET is_dirty = 1 WHERE path = :path',
            ['path' => $path],
        );
    }

    /** @return list<FileIndexEntry> */
    public function getDirtyFiles(): array
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphFileIndexTableModel::class, 'is_dirty'), Operator::Equals, true)
            ->fetchAllAs(FileIndexEntry::class, $this->mapperRegistry);
    }

    public function truncateAll(): void
    {
        $this->adapter->execute('DELETE FROM graph_file_index');
    }

    private function newQuery(): TableModelQuery
    {
        return clone $this->query;
    }
}
