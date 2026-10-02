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
use Semitexa\ProjectGraph\Application\Db\SQLite\Repository\GraphNodeLookupRepository;
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
    public readonly GraphNodeLookupRepository $lookup;

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
        $this->lookup    = new GraphNodeLookupRepository($this->adapter);
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
     * Store a node, reconciling it with what the graph already holds.
     *
     * A placeholder is a MENTION (another file's edge points here, or an
     * extractor records the role a class plays elsewhere); a real node is a
     * DECLARATION. They merge in either order, and the more specific type wins
     * — a class a handler names as its resource stays a resource whether the
     * handler or the class's own file is indexed first.
     *
     * @return ?string the file that already declares this node when a
     *                 different file declares it too (the node is NOT stored
     *                 again), null when it was stored or merged
     */
    public function upsertNode(Node $node): ?string
    {
        $existing = $this->nodes->findById($node->getId());

        if ($existing === null) {
            $this->nodes->upsert($node);
            return null;
        }

        if ($node->getIsPlaceholder()) {
            $type = self::moreSpecific($existing->getType(), $node->getType());
            if ($type !== $existing->getType()) {
                $this->nodes->upsert(self::withType($existing, $type, $existing->getIsPlaceholder() ? null : $existing->getType()));
            }
            return null;
        }

        if ($existing->getIsPlaceholder()) {
            $type = self::moreSpecific($node->getType(), $existing->getType());
            // The type the class declared is kept beside a role another file
            // gave it, so the role can be taken back (reconcileResourceRoles).
            $this->nodes->upsert(self::withType($node, $type, $type !== $node->getType() ? $node->getType() : null));
            return null;
        }

        if ($existing->getFile() !== $node->getFile() && $existing->getFile() !== '') {
            // A node many files emit (route, flow, domain) is held by the
            // smallest path, as a full build — which reads files sorted —
            // holds it; first-read-wins gave refreshes and rebuilds different
            // owners (round-3 fuzzing: ~80 refreshes). Classes are taken over
            // in GraphBuilder, where the old holder can be read again.
            if (!str_starts_with($node->getId(), 'class:') && strcmp($node->getFile(), $existing->getFile()) < 0) {
                $this->nodes->upsert($node);

                return null;
            }

            return $existing->getFile();
        }

        $this->nodes->upsert($node);
        return null;
    }

    private static function moreSpecific(NodeType $held, NodeType $incoming): NodeType
    {
        $generic = [NodeType::Class_, NodeType::Interface_, NodeType::Trait_, NodeType::Enum_];

        return in_array($held, $generic, true) && !in_array($incoming, $generic, true) ? $incoming : $held;
    }

    private static function withType(Node $node, NodeType $type, ?NodeType $declared = null): Node
    {
        if ($node->getType() === $type) {
            return $node;
        }
        $metadata = $node->getMetadata();
        if ($declared !== null) {
            $metadata['declared_type'] = $declared->value;
        }

        return new Node(
            id:            $node->getId(),
            type:          $type,
            fqcn:          $node->getFqcn(),
            file:          $node->getFile(),
            line:          $node->getLine(),
            endLine:       $node->getEndLine(),
            module:        $node->getModule(),
            metadata:      $metadata,
            isPlaceholder: $node->getIsPlaceholder(),
        );
    }

    /** @return bool true when the edge was not in the graph before */
    public function upsertEdge(Edge $edge): bool
    {
        $this->nodes->insertPlaceholder($edge->getTargetId());
        return $this->edges->upsert($edge);
    }

    public function nodeExists(string $nodeId): bool
    {
        return $this->nodes->exists($nodeId);
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
