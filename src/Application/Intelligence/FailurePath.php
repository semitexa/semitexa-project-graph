<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

final readonly class FailurePath
{
    public function __construct(
        public string  $handlerId,
        public int     $maxRetries,
        public int     $retryDelay,
        public ?string $dlqSubject,
        public array   $downstreamFlows,
        public string  $consistencyImpact,
        public string  $manualRecovery,
    ) {}

    public function toMarkdown(): string
    {
        $md = "## Failure Path: `{$this->handlerId}`\n\n";
        $md .= "**Max Retries:** {$this->maxRetries}\n";
        $md .= "**Retry Delay:** {$this->retryDelay}s\n";
        if ($this->dlqSubject !== null) {
            $md .= "**DLQ:** `{$this->dlqSubject}`\n";
        }
        $md .= "\n**Consistency Impact:** {$this->consistencyImpact}\n\n";
        $md .= "**Manual Recovery:** {$this->manualRecovery}\n";

        if ($this->downstreamFlows !== []) {
            $md .= "\n**Downstream Flows Affected:**\n";
            foreach ($this->downstreamFlows as $flow) {
                $md .= "- `{$flow}`\n";
            }
        }

        return $md;
    }
}
