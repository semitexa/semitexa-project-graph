<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Query\Direction;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:impact', description: 'Analyze impact of changing a component')]
final class ImpactAnalysisCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('component', InputArgument::REQUIRED, 'Component to analyze (class name, module, or file)');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json', 'text');
        $this->addOption('depth', null, InputOption::VALUE_OPTIONAL, 'Traversal depth (1-3)', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $component = $input->getArgument('component');
        $format = $input->getOption('format') ?? 'text';
        $depth = (int) ($input->getOption('depth') ?? 2);

        $nodeId = $this->resolveComponent($component);
        if ($nodeId === null) {
            $output->writeln("<error>Component not found: {$component}</error>");
            return Command::FAILURE;
        }

        $impact = $this->analyzeImpact($nodeId, $depth);

        if ($format === 'json') {
            $output->writeln(json_encode($impact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Impact Analysis ===</comment>');
        $output->writeln('');
        $output->writeln("<info>Component:</info> {$impact['component']}");
        $output->writeln("<info>Risk Level:</info> {$impact['risk_level']} (score: {$impact['risk_score']})");
        $output->writeln('');

        if ($impact['direct_dependents'] !== []) {
            $output->writeln('<info>Direct Dependents (' . count($impact['direct_dependents']) . '):</info>');
            foreach ($impact['direct_dependents'] as $dep) {
                $output->writeln("  → {$dep['id']} ({$dep['type']}) via {$dep['edge_type']}");
            }
            $output->writeln('');
        }

        if ($impact['transitive_dependents'] !== []) {
            $output->writeln('<info>Transitive Dependents (' . count($impact['transitive_dependents']) . '):</info>');
            foreach ($impact['transitive_dependents'] as $dep) {
                $output->writeln("  → {$dep['id']} ({$dep['type']}) [depth: {$dep['depth']}]");
            }
            $output->writeln('');
        }

        if ($impact['cross_module_impact'] !== []) {
            $output->writeln('<comment>⚠ Cross-Module Impact:</comment>');
            foreach ($impact['cross_module_impact'] as $module => $count) {
                $output->writeln("  ⚠ {$module}: {$count} components affected");
            }
            $output->writeln('');
        }

        if ($impact['event_impact'] !== []) {
            $output->writeln('<info>Event Impact:</info>');
            foreach ($impact['event_impact'] as $event) {
                $output->writeln("  → {$event['event']} (listeners: {$event['listener_count']})");
            }
            $output->writeln('');
        }

        $output->writeln("<info>Total Blast Radius:</info> {$impact['blast_radius']} components");

        return Command::SUCCESS;
    }

    private function resolveComponent(string $component): ?string
    {
        if (str_starts_with($component, 'class:') || str_starts_with($component, 'module:')) {
            return $component;
        }

        $node = $this->query->getNode('class:' . $component);
        if ($node !== null) {
            return $node->id;
        }

        $results = $this->query->search($component);
        foreach ($results as $r) {
            if (str_contains($r->fqcn, $component) || str_contains($r->id, $component)) {
                return $r->id;
            }
        }

        return null;
    }

    private function analyzeImpact(string $nodeId, int $depth): array
    {
        $node = $this->query->getNode($nodeId);
        $component = $node?->fqcn ?? $nodeId;

        $directDependents = [];
        $transitiveDependents = [];
        $crossModuleImpact = [];
        $eventImpact = [];
        $visited = [$nodeId => true];
        $riskScore = 0;

        $incomingEdges = $this->query->getEdges($nodeId, direction: Direction::Incoming);
        foreach ($incomingEdges as $edge) {
            if (in_array($edge->type, [EdgeType::Calls, EdgeType::Instantiates, EdgeType::InjectsReadonly, EdgeType::InjectsMutable, EdgeType::Extends, EdgeType::Implements, EdgeType::ListensTo], true)) {
                $directDependents[] = [
                    'id' => $edge->sourceId,
                    'type' => $this->getNodeType($edge->sourceId),
                    'edge_type' => $edge->type->value,
                ];

                $sourceNode = $this->query->getNode($edge->sourceId);
                if ($sourceNode !== null && $node !== null && $sourceNode->module !== $node->module) {
                    $crossModuleImpact[$sourceNode->module] = ($crossModuleImpact[$sourceNode->module] ?? 0) + 1;
                }

                if ($depth >= 2) {
                    $this->collectTransitive($edge->sourceId, $depth, 1, $transitiveDependents, $visited, $crossModuleImpact, $node?->module);
                }

                $riskScore += match ($edge->type) {
                    EdgeType::InjectsReadonly, EdgeType::InjectsMutable => 3,
                    EdgeType::Instantiates => 2,
                    EdgeType::Extends, EdgeType::Implements => 2,
                    default => 1,
                };
            }
        }

        $outgoingEdges = $this->query->getEdges($nodeId, direction: Direction::Outgoing);
        foreach ($outgoingEdges as $edge) {
            if ($edge->type === EdgeType::Emits) {
                $eventNode = $this->query->getNode($edge->targetId);
                if ($eventNode !== null) {
                    $listenerCount = count($this->query->getEdges($edge->targetId, EdgeType::ListensTo->value, Direction::Incoming));
                    $eventImpact[] = [
                        'event' => $eventNode->fqcn ?? $edge->targetId,
                        'listener_count' => $listenerCount,
                    ];
                    $riskScore += $listenerCount * 2;
                }
            }
        }

        $blastRadius = count($directDependents) + count($transitiveDependents);
        $riskLevel = $riskScore >= 20 ? 'CRITICAL' : ($riskScore >= 10 ? 'HIGH' : ($riskScore >= 5 ? 'MEDIUM' : 'LOW'));

        return [
            'component' => $component,
            'risk_level' => $riskLevel,
            'risk_score' => $riskScore,
            'direct_dependents' => $directDependents,
            'transitive_dependents' => $transitiveDependents,
            'cross_module_impact' => $crossModuleImpact,
            'event_impact' => $eventImpact,
            'blast_radius' => $blastRadius,
        ];
    }

    private function collectTransitive(string $nodeId, int $maxDepth, int $currentDepth, array &$transitive, array &$visited, array &$crossModule, ?string $originalModule): void
    {
        if ($currentDepth >= $maxDepth || isset($visited[$nodeId])) {
            return;
        }
        $visited[$nodeId] = true;

        $incomingEdges = $this->query->getEdges($nodeId, direction: Direction::Incoming);
        foreach ($incomingEdges as $edge) {
            if (in_array($edge->type, [EdgeType::Calls, EdgeType::Instantiates, EdgeType::InjectsReadonly, EdgeType::InjectsMutable], true)) {
                $transitive[] = [
                    'id' => $edge->sourceId,
                    'type' => $this->getNodeType($edge->sourceId),
                    'depth' => $currentDepth + 1,
                ];

                $sourceNode = $this->query->getNode($edge->sourceId);
                if ($sourceNode !== null && $originalModule !== null && $sourceNode->module !== $originalModule) {
                    $crossModule[$sourceNode->module] = ($crossModule[$sourceNode->module] ?? 0) + 1;
                }

                $this->collectTransitive($edge->sourceId, $maxDepth, $currentDepth + 1, $transitive, $visited, $crossModule, $originalModule);
            }
        }
    }

    private function getNodeType(string $id): string
    {
        $node = $this->query->getNode($id);
        return $node?->type->value ?? 'unknown';
    }
}
