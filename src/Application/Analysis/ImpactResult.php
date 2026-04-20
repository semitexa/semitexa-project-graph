<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Analysis;

final readonly class ImpactResult
{
    /**
     * @param list<string> $changed
     * @param array<string, ImpactedNode> $impacted
     */
    public function __construct(
        public array $changed,
        public array $impacted,
    ) {}

    public function totalImpacted(): int
    {
        return count($this->impacted);
    }

    public function maxDepth(): int
    {
        $max = 0;
        foreach ($this->impacted as $node) {
            if ($node->distance > $max) {
                $max = $node->distance;
            }
        }

        return $max;
    }

    /**
     * @return array<int, list<ImpactedNode>>
     */
    public function getNodesByDepth(): array
    {
        $grouped = [];
        foreach ($this->impacted as $node) {
            $grouped[$node->distance][] = $node;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<string, int>
     */
    public function getModulesAffected(): array
    {
        $modules = [];
        foreach ($this->impacted as $node) {
            if ($node->node->module !== '') {
                $modules[$node->node->module] = ($modules[$node->node->module] ?? 0) + 1;
            }
        }

        arsort($modules);

        return $modules;
    }
}
