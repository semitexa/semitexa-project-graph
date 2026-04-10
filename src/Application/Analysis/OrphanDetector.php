<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class OrphanDetector
{
    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    /** @return list<Node> */
    public function findOrphans(?string $module = null): array
    {
        $nodes = $module !== null
            ? $this->storage->nodes->findByModule($module)
            : $this->storage->nodes->findByType('class');

        $orphans = [];
        foreach ($nodes as $node) {
            if ($node->isPlaceholder) {
                continue;
            }
            $outgoing = $this->storage->edges->findBySource($node->id);
            $incoming = $this->storage->edges->findByTarget($node->id);
            if (empty($outgoing) && empty($incoming)) {
                $orphans[] = $node;
            }
        }

        return $orphans;
    }

    /** @return list<Node> */
    public function findDisconnectedSubgraphs(?string $module = null): array
    {
        $nodes = $module !== null
            ? $this->storage->nodes->findByModule($module)
            : $this->storage->nodes->findByType('class');

        $visited = [];
        $disconnected = [];

        foreach ($nodes as $node) {
            if (!isset($visited[$node->id])) {
                $component = [];
                $this->bfsComponent($node->id, $visited, $component);
                if (count($component) === 1) {
                    $disconnected[] = $node;
                }
            }
        }

        return $disconnected;
    }

    private function bfsComponent(string $startId, array &$visited, array &$component): void
    {
        $queue = [$startId];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $component[] = $current;

            $node = $this->storage->nodes->findById($current);
            if ($node !== null) {
                $edges = $this->storage->edges->findByNode($current);
                foreach ($edges as $edge) {
                    $neighbor = $edge->sourceId === $current ? $edge->targetId : $edge->sourceId;
                    if (!isset($visited[$neighbor])) {
                        $queue[] = $neighbor;
                    }
                }
            }
        }
    }
}
