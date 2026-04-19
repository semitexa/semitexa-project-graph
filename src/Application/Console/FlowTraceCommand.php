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

#[AsCommand(name: 'ai:review-graph:flow-trace', description: 'Trace an execution flow end-to-end')]
final class FlowTraceCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('flow', InputArgument::REQUIRED, 'Flow name or payload class');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json, markdown', 'text');
        $this->addOption('include-code', null, InputOption::VALUE_NONE, 'Include source file paths');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query);
        $flowArg = $input->getArgument('flow');
        $format = $input->getOption('format') ?? 'text';

        $flowName = $this->resolveFlowName($flowArg);
        if ($flowName === null) {
            $output->writeln("<error>Flow not found: {$flowArg}</error>");
            $output->writeln('');
            $output->writeln('Available flows:');
            $flows = $this->query->findNodes(type: 'execution_flow');
            foreach ($flows as $node) {
                $name = $node->metadata['name'] ?? $node->id;
                $output->writeln("  - {$name}");
            }
            return Command::FAILURE;
        }

        $flow = $intelligence->getExecutionFlow($flowName);

        if ($flow === null) {
            $output->writeln("<comment>No flow data for: {$flowName}</comment>");
            return Command::FAILURE;
        }

        if ($format === 'json') {
            $data = [
                'flow' => $flow->name,
                'entry_point' => $flow->entryPoint,
                'steps' => $flow->steps,
                'storage_touches' => $flow->storageTouches,
                'external_calls' => $flow->externalCalls,
                'sync_boundary' => $flow->syncBoundary,
                'events_emitted' => $flow->eventsEmitted,
            ];
            $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Execution Flow Trace ===</comment>');
        $output->writeln('');
        $output->writeln("<info>Flow:</info> {$flow->name}");
        $output->writeln("<info>Entry:</info> {$flow->entryPoint}");
        $output->writeln('');

        $output->writeln('<info>Steps:</info>');
        foreach ($flow->steps as $i => $step) {
            $num = $i + 1;
            $role = $step['role'] ?? '';
            $node = $step['node'] ?? 'unknown';
            $shortName = $this->shortName($node);

            $isAsync = $flow->syncBoundary !== null && $i >= $flow->syncBoundary;
            $marker = $isAsync ? ' [ASYNC]' : '';

            $output->writeln("  {$num}. {$shortName} ({$role}){$marker}");

            if ($input->getOption('include-code')) {
                $graphNode = $this->query->getNode($node);
                if ($graphNode !== null && $graphNode->file !== '') {
                    $output->writeln("     file: {$graphNode->file}");
                }
            }
        }

        if ($flow->eventsEmitted !== []) {
            $output->writeln('');
            $output->writeln('<info>Events Emitted:</info>');
            foreach ($flow->eventsEmitted as $event) {
                $output->writeln("  → {$event}");
            }
        }

        if ($flow->storageTouches !== []) {
            $output->writeln('');
            $output->writeln('<info>Storage Touches:</info>');
            foreach ($flow->storageTouches as $storage) {
                $output->writeln("  → {$storage}");
            }
        }

        if ($flow->externalCalls !== []) {
            $output->writeln('');
            $output->writeln('<info>External Calls:</info>');
            foreach ($flow->externalCalls as $ext) {
                $output->writeln("  → {$ext}");
            }
        }

        return Command::SUCCESS;
    }

    private function resolveFlowName(string $flowArg): ?string
    {
        $flows = $this->query->findNodes(type: 'execution_flow');
        foreach ($flows as $node) {
            $name = $node->metadata['name'] ?? '';
            if (stripos($name, $flowArg) !== false) {
                return $name;
            }
            $entryPoint = $node->metadata['entry_point'] ?? '';
            if (stripos($entryPoint, $flowArg) !== false) {
                return $name;
            }
        }

        if (stripos($flowArg, 'Flow') !== false) {
            return $flowArg;
        }

        return $flowArg . 'Flow';
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
