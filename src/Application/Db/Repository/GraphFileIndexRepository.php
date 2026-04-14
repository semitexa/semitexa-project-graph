<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db\Repository;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ColumnRef;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Query\Operator;
use Semitexa\Orm\Query\ResourceModelQuery;
use Semitexa\ProjectGraph\Application\Db\Model\GraphFileIndexResource;
use Semitexa\ProjectGraph\Domain\Model\FileIndexEntry;

final class GraphFileIndexRepository
{
    private ResourceModelQuery $query;

    public function __construct(
        private readonly DatabaseAdapterInterface      $adapter,
        private readonly MapperRegistry                $mapperRegistry,
        private readonly ResourceModelHydrator         $hydrator,
        private readonly ResourceModelMetadataRegistry $metadataRegistry,
        private readonly ResourceModelRelationLoader   $relationLoader,
        private readonly AggregateWriteEngine          $writeEngine,
    ) {
        $this->query = new ResourceModelQuery(
            GraphFileIndexResource::class,
            $adapter,
            $hydrator,
            $relationLoader,
            $metadataRegistry,
        );
    }

    public function findByPath(string $path): ?FileIndexEntry
    {
        return $this->newQuery()
            ->where(ColumnRef::for(GraphFileIndexResource::class, 'path'), Operator::Equals, $path)
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
            $this->writeEngine->update($entry, GraphFileIndexResource::class, $this->mapperRegistry);
        } else {
            $this->writeEngine->insert($entry, GraphFileIndexResource::class, $this->mapperRegistry);
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
            ->where(ColumnRef::for(GraphFileIndexResource::class, 'is_dirty'), Operator::Equals, true)
            ->fetchAllAs(FileIndexEntry::class, $this->mapperRegistry);
    }

    public function truncateAll(): void
    {
        $this->adapter->execute('DELETE FROM graph_file_index');
    }

    private function newQuery(): ResourceModelQuery
    {
        return clone $this->query;
    }
}
