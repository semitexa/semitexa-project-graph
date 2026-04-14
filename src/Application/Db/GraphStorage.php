<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Db;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Transaction\TransactionManager;
use Semitexa\ProjectGraph\Application\Db\Repository\GraphEdgeRepository;
use Semitexa\ProjectGraph\Application\Db\Repository\GraphFileIndexRepository;
use Semitexa\ProjectGraph\Application\Db\Repository\GraphMetaRepository;
use Semitexa\ProjectGraph\Application\Db\Repository\GraphNodeRepository;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class GraphStorage
{
    public readonly GraphNodeRepository $nodes;
    public readonly GraphEdgeRepository $edges;
    public readonly GraphFileIndexRepository $fileIndex;
    public readonly GraphMetaRepository $meta;

    public function __construct(
        private readonly DatabaseAdapterInterface      $adapter,
        private readonly TransactionManager            $txManager,
        private readonly MapperRegistry                $mapperRegistry,
        private readonly ResourceModelHydrator         $hydrator,
        private readonly ResourceModelMetadataRegistry $metadataRegistry,
        private readonly ResourceModelRelationLoader   $relationLoader,
        private readonly AggregateWriteEngine          $writeEngine,
    ) {
        $this->nodes     = $this->createNodeRepository();
        $this->edges     = $this->createEdgeRepository();
        $this->fileIndex = $this->createFileIndexRepository();
        $this->meta      = $this->createMetaRepository();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->txManager->run($callback);
    }

    public function removeByFile(string $filePath): int
    {
        $nodeIds = $this->nodes->getNodeIdsByFile($filePath);
        if (empty($nodeIds)) {
            return 0;
        }
        $this->edges->deleteByNodeIds($nodeIds);
        return $this->nodes->deleteByFile($filePath);
    }

    public function upsertNode(Node $node): void
    {
        $existing = $this->nodes->findById($node->id);
        if ($existing !== null && $existing->isPlaceholder && !$node->isPlaceholder) {
            $this->nodes->upsert($node);
        } elseif ($existing !== null && $existing->file !== $node->file && $existing->file !== '') {
            return;
        } else {
            $this->nodes->upsert($node);
        }
    }

    public function upsertEdge(Edge $edge): void
    {
        if ($this->nodes->findById($edge->targetId) === null) {
            $this->nodes->insertPlaceholder($edge->targetId);
        }
        $this->edges->upsert($edge);
    }

    public function nodeExists(string $nodeId): bool
    {
        return $this->nodes->findById($nodeId) !== null;
    }

    public function getMeta(string $key): ?string
    {
        return $this->meta->get($key);
    }

    public function setMeta(string $key, string $value): void
    {
        $this->meta->set($key, $value);
    }

    public function truncate(): void
    {
        $this->transaction(function () {
            $this->edges->truncate();
            $this->nodes->truncate();
            $this->fileIndex->truncateAll();
            $this->meta->truncate();
        });
    }

    private function createNodeRepository(): GraphNodeRepository
    {
        return new GraphNodeRepository(
            $this->adapter,
            $this->mapperRegistry,
            $this->hydrator,
            $this->metadataRegistry,
            $this->relationLoader,
            $this->writeEngine,
        );
    }

    private function createEdgeRepository(): GraphEdgeRepository
    {
        return new GraphEdgeRepository(
            $this->adapter,
            $this->mapperRegistry,
            $this->hydrator,
            $this->metadataRegistry,
            $this->relationLoader,
            $this->writeEngine,
        );
    }

    private function createFileIndexRepository(): GraphFileIndexRepository
    {
        return new GraphFileIndexRepository(
            $this->adapter,
            $this->mapperRegistry,
            $this->hydrator,
            $this->metadataRegistry,
            $this->relationLoader,
            $this->writeEngine,
        );
    }

    private function createMetaRepository(): GraphMetaRepository
    {
        return new GraphMetaRepository(
            $this->adapter,
            $this->mapperRegistry,
            $this->hydrator,
            $this->metadataRegistry,
            $this->relationLoader,
            $this->writeEngine,
        );
    }
}
