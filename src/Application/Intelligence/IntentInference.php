<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

final readonly class IntentInference
{
    public function __construct(
        public string  $nodeId,
        public string  $purpose,
        public array   $responsibilities,
        public array   $inferredFrom,
        public float   $confidence,
    ) {}

    public function toMarkdown(): string
    {
        $md = "## Intent: `{$this->nodeId}`\n\n";
        $md .= "**Purpose:** {$this->purpose}\n\n";
        if ($this->responsibilities !== []) {
            $md .= "**Responsibilities:**\n";
            foreach ($this->responsibilities as $r) {
                $md .= "- {$r}\n";
            }
            $md .= "\n";
        }
        $md .= "**Confidence:** " . round($this->confidence * 100) . "%\n";
        $md .= "**Inferred from:** " . implode(', ', $this->inferredFrom) . "\n";
        return $md;
    }
}
