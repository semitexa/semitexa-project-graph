<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Intelligence\NaturalLanguageQueryResolver;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:intelligence', description: 'Query the project graph intelligence layer')]
final class ReviewGraphIntelligenceCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::OPTIONAL, 'Natural language query');
        $this->addOption('hotspots', null, InputOption::VALUE_NONE, 'Show hotspot analysis');
        $this->addOption('doc-gaps', null, InputOption::VALUE_NONE, 'Show documentation gaps');
        $this->addOption('flows', null, InputOption::VALUE_OPTIONAL, 'Show flows for module');
        $this->addOption('event-lifecycle', null, InputOption::VALUE_OPTIONAL, 'Trace event lifecycle');
        $this->addOption('intent', null, InputOption::VALUE_OPTIONAL, 'Show intent for class');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (text|markdown)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query);
        $resolver = new NaturalLanguageQueryResolver($this->query, $intelligence);

        if ($input->getOption('hotspots')) {
            $this->showHotspots($intelligence, $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('doc-gaps')) {
            $this->showDocGaps($intelligence, $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('flows') !== null) {
            $this->showFlows($intelligence, (string) $input->getOption('flows'), $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('event-lifecycle') !== null) {
            $this->showEventLifecycle($intelligence, (string) $input->getOption('event-lifecycle'), $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('intent') !== null) {
            $this->showIntent($intelligence, (string) $input->getOption('intent'), $output);
            return Command::SUCCESS;
        }

        $userQuery = $input->getArgument('query');
        if ($userQuery === null) {
            $output->writeln('<error>Provide a query or use --hotspots, --doc-gaps, --flows, --event-lifecycle, or --intent</error>');
            $output->writeln('');
            $output->writeln('Examples:');
            $output->writeln('  ai:review-graph:intelligence "how does checkout work"');
            $output->writeln('  ai:review-graph:intelligence "what happens when OrderCreated is emitted"');
            $output->writeln('  ai:review-graph:intelligence --hotspots');
            $output->writeln('  ai:review-graph:intelligence --event-lifecycle DemoItemCreated');
            return Command::FAILURE;
        }

        $result = $resolver->resolve($userQuery);
        $this->renderResult($result, $output);

        return Command::SUCCESS;
    }

    private function showHotspots(IntelligenceLayer $intelligence, OutputInterface $output): void
    {
        $hotspots = $intelligence->getHotspots(15);

        $output->writeln('<comment>=== Hotspot Analysis ===</comment>');
        $output->writeln('');

        foreach ($hotspots as $h) {
            $level = $h->riskLevel();
            $color = match ($level) {
                'CRITICAL' => 'red',
                'HIGH' => 'yellow',
                'MEDIUM' => 'blue',
                default => 'green',
            };
            $output->writeln("<{$color}>[{$level}]</{$color}> {$h->nodeId} (score: {$h->riskScore})");
            $output->writeln("  Incoming: {$h->incomingEdges} | Cross-module: {$h->crossModuleDeps} | Complexity: {$h->complexityScore}");
            if ($h->recommendation !== null) {
                $output->writeln("  → {$h->recommendation}");
            }
            $output->writeln('');
        }
    }

    private function showDocGaps(IntelligenceLayer $intelligence, OutputInterface $output): void
    {
        $gaps = $intelligence->getDocGaps();

        $output->writeln('<comment>=== Documentation Gaps ===</comment>');
        $output->writeln('');

        foreach (array_slice($gaps, 0, 20) as $gap) {
            $node = $gap['node'];
            $output->writeln("[{$gap['score']}] {$node->fqcn} ({$node->type->value})");
        }

        $output->writeln('');
        $output->writeln("Total gaps: " . count($gaps));
    }

    private function showFlows(IntelligenceLayer $intelligence, string $module, OutputInterface $output): void
    {
        $flows = $intelligence->getFlowsForModule($module);

        $output->writeln("<comment>=== Execution Flows: {$module} ===</comment>");
        $output->writeln('');

        foreach ($flows as $flow) {
            $output->writeln("- {$flow['name']} (entry: {$flow['entry_point']})");
        }

        if ($flows === []) {
            $output->writeln('No flows found for this module.');
        }
    }

    private function showEventLifecycle(IntelligenceLayer $intelligence, string $eventClass, OutputInterface $output): void
    {
        $lifecycle = $intelligence->getEventLifecycle($eventClass);

        if ($lifecycle === null) {
            $output->writeln("<error>Event not found: {$eventClass}</error>");
            return;
        }

        $output->writeln('<comment>=== Event Lifecycle ===</comment>');
        $output->writeln('');
        $output->write($lifecycle->toMarkdown());
    }

    private function showIntent(IntelligenceLayer $intelligence, string $nodeId, OutputInterface $output): void
    {
        $intent = $intelligence->getIntent($nodeId);

        if ($intent === null) {
            $output->writeln("<error>No intent inference for: {$nodeId}</error>");
            return;
        }

        $output->writeln('<comment>=== Intent Inference ===</comment>');
        $output->writeln('');
        $output->write($intent->toMarkdown());
    }

    private function renderResult(mixed $result, OutputInterface $output): void
    {
        if ($result === null) {
            $output->writeln('<comment>No results found.</comment>');
            return;
        }

        if (is_object($result) && method_exists($result, 'toMarkdown')) {
            $output->write($result->toMarkdown());
            return;
        }

        if (is_array($result)) {
            foreach ($result as $item) {
                if (is_object($item) && method_exists($item, 'toMarkdown')) {
                    $output->write($item->toMarkdown());
                    $output->writeln('');
                } else {
                    $output->writeln(print_r($item, true));
                }
            }
            return;
        }

        $output->writeln(print_r($result, true));
    }
}
