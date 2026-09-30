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
                $removed = $this->storage->removeByFile($filePath);
                $diff->recordRemoved($removed, 0);

                $gaps = $result->gaps;
                $duplicates = [];
                foreach ($result->nodes as $node) {
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
                    $diff->addNode($node);
                }
                $this->storage->gaps->replaceForFile($filePath, $gaps);

                foreach ($result->edges as $edge) {
                    $this->storage->upsertEdge($edge);
                    $diff->addEdge($edge);
                }
            }

            $this->storage->sweepPlaceholders();
        });

        return $diff;
    }
}
