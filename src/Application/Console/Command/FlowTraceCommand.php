<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:flow-trace', description: 'Trace an execution flow end-to-end')]
final class FlowTraceCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    private ?GraphQueryService $queryService = null;

    /**
     * Property injection, not a constructor parameter: #[AsCommand] classes are
     * container-managed, and semitexa.injectionViaConstructor makes that the only DI channel.
     * The rule fires per changed file, so a constructor here is a violation waiting for the
     * next person to edit the file rather than a clean build.
     */
    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    private function query(): GraphQueryService
    {
        return $this->queryService ??= new GraphQueryService(
            $this->createProjectGraphStorage($this->connections),
        );
    }

    protected function configure(): void
    {
        $this->addArgument('flow', InputArgument::REQUIRED, 'Flow name or payload class');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json, markdown', 'text');
        $this->addOption('include-code', null, InputOption::VALUE_NONE, 'Include source file paths');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query());
        $flowArg = $input->getArgument('flow');
        $format = $input->getOption('format') ?? 'text';

        $flowId = $this->resolveFlowId($flowArg);
        if ($flowId === null) {
            $output->writeln('<error>' . OutputFormatter::escape(sprintf('No single flow matches "%s".', (string) $flowArg)) . '</error>');
            $output->writeln('');
            // The matches when there are some — all 330 flows were listed for "Sitemap".
            // Each with its id: two modules can hold flows of the same name.
            $flows = $this->query()->findNodes(type: 'execution_flow');
            $names = array_map(static fn ($node): string => (string) ($node->getMetadata()['name'] ?? $node->getId()) . '  ' . $node->getId(), $flows);
            $matching = array_values(array_filter($names, static fn (string $n): bool => trim((string) $flowArg) !== '' && stripos($n, trim((string) $flowArg)) !== false));
            $output->writeln($matching !== [] ? 'Flows matching it:' : 'Available flows:');
            foreach ($matching !== [] ? $matching : $names as $name) {
                $output->writeln('  - ' . $name, OutputInterface::OUTPUT_RAW);
            }
            return Command::FAILURE;
        }

        $flow = $intelligence->getExecutionFlow($flowId);

        if ($flow === null) {
            $output->writeln('<comment>' . OutputFormatter::escape("No flow data for: {$flowId}") . '</comment>');
            return Command::FAILURE;
        }

        if ($format === 'json') {
            $data = [
                'id' => $flow->id,
                'flow' => $flow->name,
                'entry_point' => $flow->entryPoint,
                'steps' => $flow->steps,
                'storage_touches' => $flow->storageTouches,
                'external_calls' => $flow->externalCalls,
                'sync_boundary' => $flow->syncBoundary,
                'events_emitted' => $flow->eventsEmitted,
                'infrastructure' => $flow->infrastructure,
                'complete' => $flow->isComplete(),
                'gaps' => $flow->gaps,
            ];
            $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        if ($format === 'markdown') {
            $output->writeln($flow->toMarkdown(), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Execution Flow Trace ===</comment>');
        $output->writeln('');
        $output->writeln('<info>Flow:</info> ' . OutputFormatter::escape("{$flow->name}  ({$flow->id})"));
        $output->writeln("<info>Entry:</info> {$flow->entryPoint}");
        $output->writeln('');

        $output->writeln('<info>Steps:</info>');
        foreach ($flow->steps as $i => $step) {
            $num = $i + 1;
            $role = $step['role'] ?? '';
            $node = $step['node'] ?? 'unknown';
            $shortName = $this->shortName($node);

            $isAsync = $flow->syncBoundary !== null && $i >= $flow->syncBoundary;
            $marker = ($isAsync ? ' [ASYNC]' : '') . (($step['certain'] ?? true) === false ? ' [MAY]' : '');

            $via = isset($step['event']) ? ' ← ' . $this->shortName((string) $step['event']) : '';
            $output->writeln("  {$num}. {$shortName} ({$role}){$via}{$marker}");

            if ($input->getOption('include-code')) {
                $graphNode = $this->query()->getNode($node);
                if ($graphNode !== null && $graphNode->getFile() !== '') {
                    $output->writeln("     file: {$graphNode->getFile()}");
                }
            }
        }

        if ($flow->eventsEmitted !== []) {
            $output->writeln('');
            $output->writeln('<info>Events it may fire:</info>');
            foreach ($flow->eventsEmitted as $event) {
                $output->writeln("  → {$event}");
            }
        }

        if ($flow->storageTouches !== []) {
            $output->writeln('');
            $output->writeln('<info>Tables it can reach:</info>');
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

        if ($flow->infrastructure !== []) {
            $output->writeln('');
            $output->writeln('<info>Through framework infrastructure</info> (followed for events only):');
            $output->writeln('  ' . OutputFormatter::escape(implode(', ', array_map(fn (string $c): string => $this->shortName($c), $flow->infrastructure))));
        }

        $output->writeln('');
        // Measured 2026-10-16: two GET flows of Tasks were credited with writes their handlers never call.
        $output->writeln('<comment>Tables and events are what the flow\'s classes can reach, not what one request does: the graph holds no method calls.</comment>');
        if ($flow->isComplete()) {
            $output->writeln('<info>Complete:</info> yes — nothing on the way was out of the graph\'s sight');
        } else {
            $output->writeln('<comment>Not seen past (' . count($flow->gaps) . '):</comment>');
            foreach ($flow->gaps as $gap) {
                $output->writeln(OutputFormatter::escape("  ! {$gap['kind']} at {$this->shortName($gap['at'])}: {$gap['detail']}"));
                foreach ($gap['candidates'] ?? [] as $candidate) {
                    $output->writeln(OutputFormatter::escape("      - {$candidate}"));
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * The id of the flow a person meant, or null. `stripos($name, '')` is 0, so
     * an empty argument matched the first flow; any substring took the first
     * hit in table order; and a miss was turned into "<arg>Flow" and passed on,
     * so "Available flows" could never be shown. A payload class — documented
     * as an input — never matched, because flows are entered by route, not
     * class. A name two flows share answers null too: the list then shows both.
     */
    private function resolveFlowId(string $flowArg): ?string
    {
        $flowArg = trim($flowArg);
        if ($flowArg === '') {
            return null;
        }
        $flows = [];
        foreach ($this->query()->findNodes(type: 'execution_flow') as $node) {
            $flows[] = ['id' => $node->getId(), 'name' => (string) ($node->getMetadata()['name'] ?? ''), 'entries' => (array) ($node->getMetadata()['entry_points'] ?? [$node->getMetadata()['entry_point'] ?? ''])];
        }

        $exact = array_values(array_filter($flows, static fn (array $flow): bool => strcasecmp($flow['id'], $flowArg) === 0
            || strcasecmp($flow['name'], $flowArg) === 0
            || strcasecmp($flow['name'], $flowArg . 'Flow') === 0
            || in_array($flowArg, $flow['entries'], true)));
        if ($exact !== []) {
            return count($exact) === 1 ? $exact[0]['id'] : null;
        }

        // A class in the flow (its payload or handler), by any name the resolver accepts.
        $node = $this->query()->resolver($this->getProjectRoot())->resolve($flowArg);
        if ($node !== null) {
            $hits = [];
            foreach ($this->query()->getEdges($node->getId(), 'participates_in_flow', Direction::Outgoing) as $edge) {
                $hits[$edge->getTargetId()] = true;
            }
            if (count($hits) === 1) {
                return array_key_first($hits);
            }
        }

        $partial = array_values(array_filter($flows, static fn (array $f): bool => stripos($f['name'], $flowArg) !== false));

        return count($partial) === 1 ? $partial[0]['id'] : null;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
