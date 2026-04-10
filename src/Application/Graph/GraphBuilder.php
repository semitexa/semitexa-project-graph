<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Graph;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;

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

                foreach ($result->nodes as $node) {
                    $this->storage->upsertNode($node);
                    $diff->addNode($node);
                }

                foreach ($result->edges as $edge) {
                    $this->storage->upsertEdge($edge);
                    $diff->addEdge($edge);
                }
            }
        });

        return $diff;
    }
}
