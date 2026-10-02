<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

final class GraphBuilder
{
    /** @var array<string, true> files that lost a class to a smaller path in this run, to be read again */
    private array $rereads = [];

    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    /**
     * Files that must be read again after apply(): they held a class a file
     * with a smaller path now declares too.
     *
     * @return list<string>
     */
    public function takeRereads(): array
    {
        $files = array_keys($this->rereads);
        $this->rereads = [];

        return $files;
    }

    /** @param array<string, ExtractionResult> $fileResults by file path */
    public function apply(array $fileResults): GraphDiff
    {
        $diff = new GraphDiff();

        $this->storage->transaction(function () use ($fileResults, $diff) {
            foreach ($fileResults as $filePath => $result) {
                // What the file owned before it is re-read: its nodes and the
                // edges leaving them, keyed so an unchanged edge is not
                // reported as removed and added again.
                $this->takeOver($filePath, $result, $diff);
                $oldNodeIds = $this->storage->nodes->getNodeIdsByFile($filePath);
                $oldEdges = [];
                foreach ($this->storage->edges->findBySourceIds($oldNodeIds) as $edge) {
                    $oldEdges[GraphDiff::edgeKey($edge)] = $edge;
                }

                $this->storage->removeByFile($filePath);

                $gaps = $result->gaps;
                $duplicates = [];
                /** @var array<string, true> $heldElsewhere a class this file declares again, owned by another file */
                $heldElsewhere = [];
                // What this file derives from a class another file holds — its
                // route, flow, doc node — is the losing copy's too: stored, it
                // was a route nothing serves (round 2 fuzzing, seed 1081).
                $derived = $this->derivedFromHeldElsewhere($filePath, $result);
                foreach ($result->nodes as $node) {
                    if (isset($derived[$node->getId()])) {
                        continue;
                    }
                    // Existed as a DECLARATION. A placeholder another file's edge
                    // created moments earlier is not one: counting it undercounted
                    // a full build (16 of 19 fixture nodes, 5469 of 7493 workspace).
                    $existed = in_array($node->getId(), $oldNodeIds, true) || $this->storage->nodes->existsDeclared($node->getId());
                    $heldBy = $this->storage->upsertNode($node);
                    // One gap per class, however many of its nodes the file emitted.
                    if ($heldBy !== null && in_array($node->getFqcn(), $result->declaredClasses, true)) {
                        $heldElsewhere[$node->getId()] = true;
                    }
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
                    // A second declaration's edges would hang off a node this file
                    // does not own: nothing would remove them when the copy goes.
                    if (isset($heldElsewhere[$edge->getSourceId()])) {
                        continue;
                    }
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

    /**
     * Non-class nodes this file emits only because of a class another file
     * already declares.
     *
     * @return array<string, true>
     */
    private function derivedFromHeldElsewhere(string $filePath, ExtractionResult $result): array
    {
        $held = [];
        foreach ($result->declaredClasses as $fqcn) {
            $id = NodeId::forClass($fqcn);
            $existing = $this->storage->nodes->findById($id);
            if ($existing !== null && !$existing->getIsPlaceholder() && $existing->getFile() !== '' && $existing->getFile() !== $filePath && strcmp($existing->getFile(), $filePath) < 0) {
                $held[$id] = true;
            }
        }
        if ($held === []) {
            return [];
        }
        $own = [];
        foreach ($result->nodes as $node) {
            if (!str_starts_with($node->getId(), 'class:')) {
                $own[$node->getId()] = true;
            }
        }
        $derived = [];
        $needed = [];
        foreach ($result->edges as $edge) {
            $touchesHeld = isset($held[$edge->getSourceId()]) || isset($held[$edge->getTargetId()]);
            foreach ([$edge->getSourceId(), $edge->getTargetId()] as $end) {
                if (!isset($own[$end])) {
                    continue;
                }
                $touchesHeld ? $derived[$end] = true : $needed[$end] = true;
            }
        }

        // A node the file's other classes also need stays.
        return array_diff_key($derived, $needed);
    }

    /**
     * When two files declare one class, the smaller path holds it. The rule
     * used to be "whichever file was read first", which a full build and a
     * refresh answer differently — a refresh kept the old copy, a rebuild
     * the new one, and their graphs differed in more than the owner (round-2
     * fuzzing, 2026-10-02). A full build meets files sorted, so the smaller
     * path comes first anyway; a refresh that reads a smaller copy later
     * takes the class over and has the old holder read again.
     */
    private function takeOver(string $filePath, ExtractionResult $result, GraphDiff $diff): void
    {
        foreach ($result->declaredClasses as $fqcn) {
            $existing = $this->storage->nodes->findById(NodeId::forClass($fqcn));
            $holder = $existing?->getFile() ?? '';
            if ($existing !== null && !$existing->getIsPlaceholder() && $holder !== '' && $holder !== $filePath && strcmp($filePath, $holder) < 0) {
                // Recorded as removed, so the re-read that puts the rest back
                // nets to nothing in the counts (round-2 fuzzing, seed 2150).
                $ids = $this->storage->nodes->getNodeIdsByFile($holder);
                foreach ($ids as $id) {
                    $diff->removeNode($id);
                }
                foreach ($this->storage->edges->findBySourceIds($ids) as $edge) {
                    $diff->removeEdge($edge);
                }
                $this->storage->removeByFile($holder);
                $this->rereads[$holder] = true;
            }
        }
    }
}
