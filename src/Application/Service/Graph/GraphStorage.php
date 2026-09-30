<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelHydrator;
use Semitexa\Orm\Application\Service\Hydration\ResourceModelRelationLoader;
use Semitexa\Orm\Application\Service\Mapping\MapperRegistry;
use Semitexa\Orm\Metadata\ResourceModelMetadataRegistry;
use Semitexa\Orm\Application\Service\Persistence\AggregateWriteEngine;
use Semitexa\Orm\Application\Service\Transaction\TransactionManager;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphCoverageGapRepository;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphEdgeRepository;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphFileIndexRepository;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphMetaRepository;
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphNodeRepository;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class GraphStorage
{
    public readonly GraphNodeRepository $nodes;
    public readonly GraphEdgeRepository $edges;
    public readonly GraphFileIndexRepository $fileIndex;
    public readonly GraphMetaRepository $meta;
    public readonly GraphCoverageGapRepository $gaps;

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
        $this->gaps      = new GraphCoverageGapRepository($this->adapter);
    }

    public function transaction(callable $callback): mixed
    {
        return $this->txManager->run($callback);
    }

    /**
     * Forget what a file declared, before it is re-extracted (or because it
     * was deleted).
     *
     * A file owns its nodes and the edges LEAVING them. Edges other files have
     * into its nodes belong to those files, which this refresh does not
     * re-read — so they stay. A node that disappears while such an edge still
     * points at it becomes a placeholder: re-extraction turns it back into a
     * real node if the file still declares it, and otherwise it remains as the
     * visible target of a dangling reference.
     *
     * @return int count of nodes removed or demoted
     */
    public function removeByFile(string $filePath): int
    {
        $nodeIds = $this->nodes->getNodeIdsByFile($filePath);
        if ($nodeIds === []) {
            return 0;
        }

        $this->edges->deleteBySourceIds($nodeIds);

        $stillReferenced = $this->edges->referencedAmong($nodeIds);
        $this->nodes->demoteToPlaceholders($stillReferenced);
        $this->nodes->deleteByIds(array_values(array_diff($nodeIds, $stillReferenced)));

        return count($nodeIds);
    }

    /** Drop placeholders nothing points at any more. */
    public function sweepPlaceholders(): int
    {
        return $this->nodes->deleteUnreferencedPlaceholders();
    }

    /**
     * @return ?string the file that already declares this node when a
     *                 different file claims it too (the node is NOT stored
     *                 again), null when it was stored
     */
    public function upsertNode(Node $node): ?string
    {
        $existing = $this->nodes->findById($node->getId());
        if ($existing !== null && $existing->getIsPlaceholder() && !$node->getIsPlaceholder()) {
            $this->nodes->upsert($node);
        } elseif ($existing !== null && $existing->getFile() !== $node->getFile() && $existing->getFile() !== '') {
            return $existing->getFile();
        } else {
            $this->nodes->upsert($node);
        }

        return null;
    }

    public function upsertEdge(Edge $edge): void
    {
        if ($this->nodes->findById($edge->getTargetId()) === null) {
            $this->nodes->insertPlaceholder($edge->getTargetId());
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
            $this->gaps->truncate();
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
