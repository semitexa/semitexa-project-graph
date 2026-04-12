<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

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
    ) {}

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
            $md .= "**Storage:** " . implode(', ', $this->storageTouches) . "\n\n";
        }
        if ($this->externalCalls !== []) {
            $md .= "**External:** " . implode(', ', $this->externalCalls) . "\n\n";
        }

        return $md;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
