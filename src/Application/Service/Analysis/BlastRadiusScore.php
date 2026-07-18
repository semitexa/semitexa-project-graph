<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Analysis;

final readonly class BlastRadiusScore
{
    /**
     * @param int $score 0..100
     * @param string $level 'low' | 'medium' | 'high'
     * @param list<string> $hotspots
     * @param list<string> $impactedModules
     * @param string $recommendation 'inline' | 'epic_required'
     * @param array<string, int> $edgeBreakdown
     */
    public function __construct(
        public int $score,
        public string $level,
        public array $hotspots,
        public array $impactedModules,
        public string $recommendation,
        public array $edgeBreakdown = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'level' => $this->level,
            'hotspots' => $this->hotspots,
            'impacted_modules' => $this->impactedModules,
            'recommendation' => $this->recommendation,
            'edge_breakdown' => $this->edgeBreakdown,
        ];
    }
}
