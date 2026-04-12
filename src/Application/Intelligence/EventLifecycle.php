<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Intelligence;

final readonly class EventLifecycle
{
    public function __construct(
        public string  $eventClass,
        public array   $emitters,
        public array   $syncListeners,
        public array   $asyncListeners,
        public array   $queuedListeners,
        public ?string $natsSubject,
        public ?string $jetstream,
        public array   $replayHandlers,
        public ?string $dlqPath,
        public ?array  $retryConfig,
        public string  $idempotencyKey,
    ) {}

    public function toMarkdown(): string
    {
        $md = "## Event Lifecycle: `{$this->eventClass}`\n\n";

        if ($this->emitters !== []) {
            $md .= "**Emitted by:** " . implode(', ', array_map(fn($e) => "`{$e}`", $this->emitters)) . "\n\n";
        }

        if ($this->syncListeners !== []) {
            $md .= "### Sync Listeners\n";
            foreach ($this->syncListeners as $l) {
                $md .= "- `{$l}`\n";
            }
            $md .= "\n";
        }

        if ($this->asyncListeners !== []) {
            $md .= "### Async Listeners (Swoole defer)\n";
            foreach ($this->asyncListeners as $l) {
                $md .= "- `{$l}`\n";
            }
            $md .= "\n";
        }

        if ($this->queuedListeners !== []) {
            $md .= "### Queued Listeners\n";
            foreach ($this->queuedListeners as $l) {
                $queue = $l['queue'] ?? 'default';
                $md .= "- `{$l['class']}` (queue: `{$queue}`)\n";
            }
            $md .= "\n";
        }

        if ($this->natsSubject !== null) {
            $md .= "### Cross-Node Propagation\n";
            $md .= "- **Subject:** `{$this->natsSubject}`\n";
            if ($this->jetstream !== null) {
                $md .= "- **JetStream:** `{$this->jetstream}`\n";
            }
            if ($this->replayHandlers !== []) {
                $md .= "- **Replay Handlers:** " . implode(', ', array_map(fn($h) => "`{$h}`", $this->replayHandlers)) . "\n";
            }
            if ($this->dlqPath !== null) {
                $md .= "- **DLQ:** `{$this->dlqPath}`\n";
            }
            if ($this->retryConfig !== null) {
                $md .= "- **Retry:** max {$this->retryConfig['maxRetries']}, delay {$this->retryConfig['retryDelay']}s\n";
            }
            $md .= "- **Idempotency:** by `{$this->idempotencyKey}`\n";
        }

        return $md;
    }
}
