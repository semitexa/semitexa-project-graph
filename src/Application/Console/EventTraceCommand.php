<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:event-trace', description: 'Trace the full lifecycle of an event')]
final class EventTraceCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('event', InputArgument::REQUIRED, 'Event class name (short or FQCN)');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json, markdown', 'text');
        $this->addOption('include-code', null, InputOption::VALUE_NONE, 'Include source file paths');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query);
        $eventArg = $input->getArgument('event');
        $format = $input->getOption('format') ?? 'text';
        $includeCode = $input->getOption('include-code');

        $eventClass = $this->resolveEventClass($eventArg);
        if ($eventClass === null) {
            $output->writeln("<error>Event not found: {$eventArg}</error>");
            $output->writeln('');
            $output->writeln('Search results:');
            $results = $this->query->search($eventArg);
            foreach ($results as $node) {
                if ($node->type->value === 'event' || str_ends_with($node->fqcn, 'Event')) {
                    $output->writeln("  - {$node->fqcn}");
                }
            }
            return Command::FAILURE;
        }

        $lifecycle = $intelligence->getEventLifecycle($eventClass);

        if ($lifecycle === null) {
            $output->writeln("<comment>No lifecycle data for: {$eventClass}</comment>");
            $output->writeln('');
            $output->writeln('Showing basic event info:');
            $this->showBasicEventInfo($eventClass, $output);
            return Command::SUCCESS;
        }

        if ($format === 'json') {
            $data = [
                'event' => $lifecycle->eventClass,
                'emitters' => $lifecycle->emitters,
                'sync_listeners' => $lifecycle->syncListeners,
                'async_listeners' => $lifecycle->asyncListeners,
                'queued_listeners' => $lifecycle->queuedListeners,
                'nats_subject' => $lifecycle->natsSubject,
                'jetstream' => $lifecycle->jetstream,
                'replay_handlers' => $lifecycle->replayHandlers,
                'dlq_path' => $lifecycle->dlqPath,
                'retry_config' => $lifecycle->retryConfig,
                'idempotency_key' => $lifecycle->idempotencyKey,
            ];
            $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Event Lifecycle Trace ===</comment>');
        $output->writeln('');
        $output->writeln("<info>Event:</info> {$lifecycle->eventClass}");
        $output->writeln('');

        if ($lifecycle->emitters !== []) {
            $output->writeln('<info>Emitted by:</info>');
            foreach ($lifecycle->emitters as $emitter) {
                $output->writeln("  → {$emitter}");
                if ($includeCode) {
                    $node = $this->query->getNode($emitter);
                    if ($node !== null && $node->file !== '') {
                        $output->writeln("    file: {$node->file}");
                    }
                }
            }
            $output->writeln('');
        }

        if ($lifecycle->syncListeners !== []) {
            $output->writeln('<info>Sync Listeners (inline):</info>');
            foreach ($lifecycle->syncListeners as $listener) {
                $output->writeln("  → {$listener}");
                if ($includeCode) {
                    $node = $this->query->getNode($listener);
                    if ($node !== null && $node->file !== '') {
                        $output->writeln("    file: {$node->file}");
                    }
                }
            }
            $output->writeln('');
        }

        if ($lifecycle->asyncListeners !== []) {
            $output->writeln('<info>Async Listeners (Swoole defer):</info>');
            foreach ($lifecycle->asyncListeners as $listener) {
                $output->writeln("  → {$listener}");
            }
            $output->writeln('');
        }

        if ($lifecycle->queuedListeners !== []) {
            $output->writeln('<info>Queued Listeners:</info>');
            foreach ($lifecycle->queuedListeners as $ql) {
                $queue = $ql['queue'] ?? 'default';
                $output->writeln("  → {$ql['class']} (queue: {$queue})");
            }
            $output->writeln('');
        }

        if ($lifecycle->natsSubject !== null) {
            $output->writeln('<info>Cross-Node Propagation (NATS):</info>');
            $output->writeln("  Subject: {$lifecycle->natsSubject}");
            if ($lifecycle->jetstream !== null) {
                $output->writeln("  JetStream: {$lifecycle->jetstream}");
            }
            if ($lifecycle->replayHandlers !== []) {
                $output->writeln('  Replay Handlers:');
                foreach ($lifecycle->replayHandlers as $handler) {
                    $output->writeln("    → {$handler}");
                    if ($includeCode) {
                        $node = $this->query->getNode($handler);
                        if ($node !== null && $node->file !== '') {
                            $output->writeln("      file: {$node->file}");
                        }
                    }
                }
            }
            if ($lifecycle->dlqPath !== null) {
                $output->writeln("  DLQ: {$lifecycle->dlqPath}");
            }
            if ($lifecycle->retryConfig !== null) {
                $output->writeln("  Retry: max {$lifecycle->retryConfig['maxRetries']}, delay {$lifecycle->retryConfig['retryDelay']}s");
            }
            $output->writeln("  Idempotency: by {$lifecycle->idempotencyKey}");
            $output->writeln('');
        }

        if ($lifecycle->syncListeners === [] && $lifecycle->asyncListeners === [] && $lifecycle->queuedListeners === [] && $lifecycle->replayHandlers === []) {
            $output->writeln('<comment>No listeners found. This event may be unused.</comment>');
        }

        return Command::SUCCESS;
    }

    private function resolveEventClass(string $eventArg): ?string
    {
        if (class_exists($eventArg)) {
            return $eventArg;
        }

        $results = $this->query->search($eventArg);
        foreach ($results as $node) {
            if ($node->type->value === 'event' || str_ends_with($node->fqcn, 'Event')) {
                return $node->fqcn;
            }
        }

        return null;
    }

    private function showBasicEventInfo(string $eventClass, OutputInterface $output): void
    {
        $edges = $this->query->getEdges($eventClass);
        $listeners = [];
        $emitters = [];

        foreach ($edges as $edge) {
            if ($edge->type->value === 'listens_to' && $edge->targetId === 'class:' . $eventClass) {
                $listeners[] = $edge->sourceId;
            }
            if ($edge->type->value === 'emits' && str_starts_with($edge->sourceId, 'class:')) {
                $emitters[] = $edge->sourceId;
            }
        }

        if ($emitters !== []) {
            $output->writeln('Emitters: ' . implode(', ', $emitters));
        }
        if ($listeners !== []) {
            $output->writeln('Listeners: ' . implode(', ', $listeners));
        }
        if ($emitters === [] && $listeners === []) {
            $output->writeln('No connections found.');
        }
    }
}
