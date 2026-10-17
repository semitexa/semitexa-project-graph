<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Intelligence;

final readonly class ExecutionFlow
{
    public function __construct(
        public string  $id,
        public string  $name,
        public string  $entryPoint,
        public array   $steps,
        public array   $storageTouches,
        public array   $externalCalls,
        public ?int    $syncBoundary,
        public array   $eventsEmitted,
        /**
         * Where the walk could not vouch for what comes next (FlowTracer::GAP_*).
         *
         * @var list<array{kind: string, at: string, detail: string, candidates?: list<string>}>
         */
        public array   $gaps = [],
        /**
         * Framework classes the flow passes through and is not followed into
         * for tables or gaps (FlowTracer::INFRASTRUCTURE_FAN_IN).
         *
         * @var list<string>
         */
        public array   $infrastructure = [],
    ) {}

    /** True only when nothing on the way was out of the graph's sight. */
    public function isComplete(): bool
    {
        return $this->gaps === [];
    }

    public function toMarkdown(): string
    {
        $md = "## Execution Flow: {$this->name}\n\n";
        $md .= "**Entry:** `{$this->entryPoint}`\n\n";
        $md .= "```mermaid\ngraph LR\n";

        foreach ($this->steps as $i => $step) {
            $node = $step['node'] ?? 'unknown';
            $role = $step['role'] ?? '';
            $short = $this->shortName($node);

            if ($this->syncBoundary !== null && $i === $this->syncBoundary) {
                $md .= "    {$short}[\"{$short}\\n({$role})\"]:::async\n";
            } else {
                $md .= "    {$short}[\"{$short}\\n({$role})\"]\n";
            }

            if ($i > 0) {
                $prevShort = $this->shortName($this->steps[$i - 1]['node'] ?? 'unknown');
                $md .= "    {$prevShort} --> {$short}\n";
            }
        }

        foreach ($this->eventsEmitted as $event) {
            $eventShort = $this->shortName($event);
            $md .= "    {$eventShort}{\"{$eventShort}\"}\n";
            $lastStep = $this->shortName(end($this->steps)['node'] ?? 'unknown');
            $md .= "    {$lastStep} -. emit .-> {$eventShort}\n";
        }

        $md .= "```\n\n";

        if ($this->storageTouches !== []) {
            $md .= "**Tables it can reach:** " . implode(', ', $this->storageTouches) . "\n\n";
        }
        if ($this->externalCalls !== []) {
            $md .= "**External:** " . implode(', ', $this->externalCalls) . "\n\n";
        }
        if ($this->infrastructure !== []) {
            $md .= "**Through framework infrastructure** (followed for events only): " . implode(', ', array_map(fn (string $c): string => $this->shortName($c), $this->infrastructure)) . "\n\n";
        }
        if ($this->gaps !== []) {
            $md .= "**Not seen past:**\n\n";
            foreach ($this->gaps as $gap) {
                $md .= "- `{$gap['kind']}` at `{$gap['at']}`: {$gap['detail']}\n";
            }
            $md .= "\n";
        }

        return $md;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
