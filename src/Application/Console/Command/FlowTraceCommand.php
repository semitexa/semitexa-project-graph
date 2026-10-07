<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
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

        $flowName = $this->resolveFlowName($flowArg);
        if ($flowName === null) {
            $output->writeln('<error>' . OutputFormatter::escape(sprintf('No single flow matches "%s".', (string) $flowArg)) . '</error>');
            $output->writeln('');
            // The matches when there are some — all 330 flows were listed for "Sitemap".
            $flows = $this->query()->findNodes(type: 'execution_flow');
            $names = array_map(static fn ($node): string => (string) ($node->getMetadata()['name'] ?? $node->getId()), $flows);
            $matching = array_values(array_filter($names, static fn (string $n): bool => trim((string) $flowArg) !== '' && stripos($n, trim((string) $flowArg)) !== false));
            $output->writeln($matching !== [] ? 'Flows matching it:' : 'Available flows:');
            foreach ($matching !== [] ? $matching : $names as $name) {
                $output->writeln('  - ' . $name, OutputInterface::OUTPUT_RAW);
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
            $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
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
                $graphNode = $this->query()->getNode($node);
                if ($graphNode !== null && $graphNode->getFile() !== '') {
                    $output->writeln("     file: {$graphNode->getFile()}");
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

    /**
     * The flow a person meant, or null. `stripos($name, '')` is 0, so an empty
     * argument matched the first flow; any substring took the first hit in
     * table order; and a miss was turned into "<arg>Flow" and passed on, so
     * "Available flows" could never be shown. A payload class — documented as
     * an input — never matched, because flows are entered by route, not class.
     */
    private function resolveFlowName(string $flowArg): ?string
    {
        $flowArg = trim($flowArg);
        if (str_starts_with($flowArg, 'flow:')) {
            $flowArg = substr($flowArg, 5); // the node id, as the graph and the viewer show it
        }
        if ($flowArg === '') {
            return null;
        }
        $flows = [];
        foreach ($this->query()->findNodes(type: 'execution_flow') as $node) {
            $flows[] = ['name' => (string) ($node->getMetadata()['name'] ?? ''), 'entry' => (string) ($node->getMetadata()['entry_point'] ?? ''), 'steps' => (array) ($node->getMetadata()['steps'] ?? [])];
        }

        foreach ($flows as $flow) {
            if (strcasecmp($flow['name'], $flowArg) === 0 || strcasecmp($flow['name'], $flowArg . 'Flow') === 0 || strcasecmp($flow['entry'], $flowArg) === 0) {
                return $flow['name'];
            }
        }

        // A class in the flow (its payload or handler), by any name the resolver accepts.
        $node = $this->query()->resolver($this->getProjectRoot())->resolve($flowArg);
        if ($node !== null) {
            $hits = array_values(array_filter($flows, static function (array $flow) use ($node): bool {
                foreach ($flow['steps'] as $step) {
                    if (is_array($step) && ($step['node'] ?? null) === $node->getId()) {
                        return true;
                    }
                }
                return false;
            }));
            if (count($hits) === 1) {
                return $hits[0]['name'];
            }
        }

        $partial = array_values(array_filter($flows, static fn (array $f): bool => stripos($f['name'], $flowArg) !== false));

        return count($partial) === 1 ? $partial[0]['name'] : null;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
