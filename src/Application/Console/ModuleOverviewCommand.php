<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:module', description: 'Overview of a module with full context')]
final class ModuleOverviewCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('module', InputArgument::REQUIRED, 'Module name');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json', 'text');
        $this->addOption('include-events', null, InputOption::VALUE_NONE, 'Include event details');
        $this->addOption('include-flows', null, InputOption::VALUE_NONE, 'Include execution flows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = $input->getArgument('module');
        $format = $input->getOption('format') ?? 'text';
        $includeEvents = $input->getOption('include-events');
        $includeFlows = $input->getOption('include-flows');

        $overview = $this->buildOverview($module, $includeEvents, $includeFlows);

        if ($format === 'json') {
            $output->writeln(json_encode($overview, JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Module Overview: ' . $module . ' ===</comment>');
        $output->writeln('');

        $output->writeln('<info>Summary:</info>');
        $output->writeln("  Classes: {$overview['summary']['classes']}");
        $output->writeln("  Events: {$overview['summary']['events']}");
        $output->writeln("  Handlers: {$overview['summary']['handlers']}");
        $output->writeln("  Services: {$overview['summary']['services']}");
        $output->writeln("  Routes: {$overview['summary']['routes']}");
        $output->writeln("  External deps: {$overview['summary']['external_deps']}");
        $output->writeln('');

        if ($overview['domain_context'] !== null) {
            $output->writeln('<info>Domain:</info> ' . $overview['domain_context']['name']);
            $output->writeln('  ' . $overview['domain_context']['description']);
            $output->writeln('  Criticality: ' . $overview['domain_context']['criticality']);
            $output->writeln('');
        }

        if ($includeFlows && $overview['flows'] !== []) {
            $output->writeln('<info>Execution Flows:</info>');
            foreach ($overview['flows'] as $flow) {
                $output->writeln("  → {$flow['name']} (entry: {$flow['entry_point']})");
                foreach ($flow['steps'] as $step) {
                    $output->writeln("    {$step['order']}. {$step['node']} ({$step['role']})");
                }
            }
            $output->writeln('');
        }

        if ($includeEvents && $overview['events'] !== []) {
            $output->writeln('<info>Events:</info>');
            foreach ($overview['events'] as $event) {
                $output->writeln("  → {$event['class']}");
                if ($event['nats_subject'] !== null) {
                    $output->writeln("    subject: {$event['nats_subject']}");
                }
                if ($event['listeners'] !== []) {
                    $output->writeln("    listeners: " . implode(', ', $event['listeners']));
                }
            }
            $output->writeln('');
        }

        if ($overview['hotspots'] !== []) {
            $output->writeln('<comment>⚠ Hotspots:</comment>');
            foreach ($overview['hotspots'] as $h) {
                $output->writeln("  ⚠ {$h['node_id']} (risk: {$h['risk_score']})");
            }
            $output->writeln('');
        }

        if ($overview['cross_module_deps'] !== []) {
            $output->writeln('<info>Cross-Module Dependencies:</info>');
            foreach ($overview['cross_module_deps'] as $dep) {
                $output->writeln("  → {$dep['source']} → {$dep['target']} ({$dep['type']})");
            }
        }

        return Command::SUCCESS;
    }

    private function buildOverview(string $module, bool $includeEvents, bool $includeFlows): array
    {
        $intelligence = new IntelligenceLayer($this->query);
        $overview = [
            'module' => $module,
            'summary' => [
                'classes' => 0,
                'events' => 0,
                'handlers' => 0,
                'services' => 0,
                'routes' => 0,
                'external_deps' => 0,
            ],
            'domain_context' => null,
            'flows' => [],
            'events' => [],
            'hotspots' => [],
            'cross_module_deps' => [],
        ];

        $nodes = $this->query->findNodes(module: $module);
        foreach ($nodes as $node) {
            match ($node->type) {
                NodeType::Class_ => $overview['summary']['classes']++,
                NodeType::Event => $overview['summary']['events']++,
                NodeType::Handler => $overview['summary']['handlers']++,
                NodeType::Service => $overview['summary']['services']++,
                NodeType::Route => $overview['summary']['routes']++,
                default => null,
            };
        }

        $domainContext = $intelligence->getDomainContext('module:' . $module);
        if ($domainContext !== null) {
            $overview['domain_context'] = [
                'name' => $domainContext->name,
                'description' => $domainContext->description,
                'criticality' => $domainContext->criticality,
            ];
        }

        if ($includeFlows) {
            $overview['flows'] = $intelligence->getFlowsForModule($module);
        }

        if ($includeEvents) {
            $eventNodes = $this->query->findNodes(type: NodeType::Event->value, module: $module);
            foreach ($eventNodes as $eventNode) {
                $lifecycle = $intelligence->getEventLifecycle($eventNode->fqcn);
                $overview['events'][] = [
                    'class' => $eventNode->fqcn,
                    'nats_subject' => $lifecycle?->natsSubject,
                    'listeners' => array_merge(
                        $lifecycle?->syncListeners ?? [],
                        $lifecycle?->asyncListeners ?? [],
                        array_map(fn($q) => $q['class'], $lifecycle?->queuedListeners ?? []),
                    ),
                ];
            }
        }

        $allHotspots = $intelligence->getHotspots(20);
        foreach ($allHotspots as $h) {
            $node = $this->query->getNode($h->nodeId);
            if ($node !== null && $node->module === $module) {
                $overview['hotspots'][] = [
                    'node_id' => $h->nodeId,
                    'risk_score' => $h->riskScore,
                ];
            }
        }

        $crossModuleEdges = $this->query->getCrossModuleEdges($module);
        foreach ($crossModuleEdges as $edge) {
            $overview['cross_module_deps'][] = [
                'source' => $edge->sourceId,
                'target' => $edge->targetId,
                'type' => $edge->type->value,
            ];
            if (!str_starts_with($edge->targetId, 'module:') && !str_starts_with($edge->targetId, 'class:')) {
                $overview['summary']['external_deps']++;
            }
        }

        return $overview;
    }
}
