<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

final readonly class Hotspot
{
    public function __construct(
        public string  $nodeId,
        public float   $riskScore,
        public int     $incomingEdges,
        public int     $crossModuleDeps,
        public bool    $isCriticalPath,
        public float   $complexityScore,
        public ?string $recommendation,
    ) {}

    public function riskLevel(): string
    {
        if ($this->riskScore >= 0.8) return 'CRITICAL';
        if ($this->riskScore >= 0.6) return 'HIGH';
        if ($this->riskScore >= 0.4) return 'MEDIUM';
        return 'LOW';
    }

    public function toMarkdown(): string
    {
        $level = $this->riskLevel();
        return "**Hotspot:** `{$this->nodeId}` — {$level} ({$this->riskScore})\n"
            . "- Incoming deps: {$this->incomingEdges}\n"
            . "- Cross-module: {$this->crossModuleDeps}\n"
            . "- Critical path: " . ($this->isCriticalPath ? 'Yes' : 'No') . "\n"
            . "- Complexity: {$this->complexityScore}\n"
            . ($this->recommendation !== null ? "- Recommendation: {$this->recommendation}\n" : '');
    }
}
