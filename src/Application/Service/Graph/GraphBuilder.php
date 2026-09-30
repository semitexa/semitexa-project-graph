<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

final class GraphBuilder
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    public function apply(array $fileResults): GraphDiff
    {
        $diff = new GraphDiff();

        $this->storage->transaction(function () use ($fileResults, $diff) {
            foreach ($fileResults as $filePath => $result) {
                // What the file owned before it is re-read: its nodes and the
                // edges leaving them, keyed so an unchanged edge is not
                // reported as removed and added again.
                $oldNodeIds = $this->storage->nodes->getNodeIdsByFile($filePath);
                $oldEdges = [];
                foreach ($this->storage->edges->findBySourceIds($oldNodeIds) as $edge) {
                    $oldEdges[GraphDiff::edgeKey($edge)] = $edge;
                }

                $this->storage->removeByFile($filePath);

                $gaps = $result->gaps;
                $duplicates = [];
                foreach ($result->nodes as $node) {
                    $existed = in_array($node->getId(), $oldNodeIds, true) || $this->storage->nodes->exists($node->getId());
                    $heldBy = $this->storage->upsertNode($node);
                    // One gap per class, however many of its nodes the file emitted.
                    if ($heldBy !== null
                        && in_array($node->getFqcn(), $result->declaredClasses, true)
                        && !isset($duplicates[$node->getFqcn()])
                    ) {
                        $duplicates[$node->getFqcn()] = true;
                        $gaps[] = new CoverageGap(
                            CoverageGapKind::DuplicateClass,
                            $filePath,
                            'The graph already holds this class from ' . $heldBy,
                            $node->getFqcn(),
                            $node->getLine(),
                        );
                    }
                    // Added means new to the graph: not one of this file's
                    // own nodes before, not already held from elsewhere (a
                    // shared concept node), and a declaration rather than a
                    // placeholder mention of a class declared elsewhere.
                    if (!$existed && !$node->getIsPlaceholder()) {
                        $diff->addNode($node);
                    }
                }
                $newNodeIds = array_map(
                    static fn ($node): string => $node->getId(),
                    array_filter($result->nodes, static fn ($node): bool => !$node->getIsPlaceholder()),
                );
                foreach (array_diff($oldNodeIds, $newNodeIds) as $removedId) {
                    $diff->removeNode($removedId);
                }
                $this->storage->gaps->replaceForFile($filePath, $gaps);

                $newKeys = [];
                foreach ($result->edges as $edge) {
                    $key = GraphDiff::edgeKey($edge);
                    $inserted = $this->storage->upsertEdge($edge);
                    // New to the file, and not already in the graph from
                    // another file: a genuinely added edge.
                    if ($inserted && !isset($oldEdges[$key]) && !isset($newKeys[$key])) {
                        $diff->addEdge($edge);
                    }
                    $newKeys[$key] = true;
                }
                foreach ($oldEdges as $key => $edge) {
                    if (!isset($newKeys[$key])) {
                        $diff->removeEdge($edge);
                    }
                }
            }

            $this->storage->sweepPlaceholders();
        });

        return $diff;
    }
}
