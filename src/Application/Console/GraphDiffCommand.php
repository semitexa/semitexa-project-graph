<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:diff', description: 'Show how the graph changed since last scan')]
final class GraphDiffCommand extends Command
{
    private const META_KEY = 'graph_diff_last_scan';

    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json', 'text');
        $this->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Limit to module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format') ?? 'text';
        $module = $input->getOption('module');

        $previousStats = $this->loadPreviousStats();
        $currentStats = $this->getCurrentStats($module);

        $diff = [
            'previous_scan' => $previousStats['timestamp'] ?? 'never',
            'current_scan' => date('Y-m-d H:i:s'),
            'nodes' => [
                'previous' => $previousStats['total_nodes'] ?? 0,
                'current' => $currentStats['total_nodes'],
                'delta' => ($currentStats['total_nodes'] - ($previousStats['total_nodes'] ?? 0)),
            ],
            'edges' => [
                'previous' => $previousStats['total_edges'] ?? 0,
                'current' => $currentStats['total_edges'],
                'delta' => ($currentStats['total_edges'] - ($previousStats['total_edges'] ?? 0)),
            ],
            'by_type' => [],
            'new_modules' => [],
            'removed_modules' => [],
        ];

        if ($previousStats !== []) {
            $prevByType = $previousStats['by_type'] ?? [];
            foreach ($currentStats['by_type'] as $type => $count) {
                $prevCount = $prevByType[$type] ?? 0;
                if ($count !== $prevCount) {
                    $diff['by_type'][$type] = [
                        'previous' => $prevCount,
                        'current' => $count,
                        'delta' => $count - $prevCount,
                    ];
                }
            }

            $prevModules = $previousStats['modules'] ?? [];
            $currentModules = $currentStats['modules'] ?? [];
            $diff['new_modules'] = array_diff($currentModules, $prevModules);
            $diff['removed_modules'] = array_diff($prevModules, $currentModules);
        } else {
            foreach ($currentStats['by_type'] as $type => $count) {
                $diff['by_type'][$type] = ['previous' => 0, 'current' => $count, 'delta' => $count];
            }
            $diff['new_modules'] = $currentStats['modules'] ?? [];
        }

        $this->saveCurrentStats($currentStats);

        if ($format === 'json') {
            $output->writeln(json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Graph Diff ===</comment>');
        $output->writeln('');
        $output->writeln("Previous scan: {$diff['previous_scan']}");
        $output->writeln("Current scan:  {$diff['current_scan']}");
        $output->writeln('');

        $nodeDelta = $diff['nodes']['delta'];
        $edgeDelta = $diff['edges']['delta'];
        $nodeSign = $nodeDelta >= 0 ? '+' : '';
        $edgeSign = $edgeDelta >= 0 ? '+' : '';

        $output->writeln("<info>Nodes:</info> {$diff['nodes']['previous']} → {$diff['nodes']['current']} ({$nodeSign}{$nodeDelta})");
        $output->writeln("<info>Edges:</info> {$diff['edges']['previous']} → {$diff['edges']['current']} ({$edgeSign}{$edgeDelta})");
        $output->writeln('');

        if ($diff['by_type'] !== []) {
            $output->writeln('<info>Changes by Type:</info>');
            foreach ($diff['by_type'] as $type => $change) {
                $sign = $change['delta'] >= 0 ? '+' : '';
                $output->writeln("  {$type}: {$change['previous']} → {$change['current']} ({$sign}{$change['delta']})");
            }
            $output->writeln('');
        }

        if ($diff['new_modules'] !== []) {
            $output->writeln('<info>New Modules:</info>');
            foreach ($diff['new_modules'] as $m) {
                $output->writeln("  + {$m}");
            }
            $output->writeln('');
        }

        if ($diff['removed_modules'] !== []) {
            $output->writeln('<comment>Removed Modules:</comment>');
            foreach ($diff['removed_modules'] as $m) {
                $output->writeln("  - {$m}");
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function loadPreviousStats(): array
    {
        $metaFile = sys_get_temp_dir() . '/semitexa-graph-diff.json';
        if (!file_exists($metaFile)) {
            return [];
        }
        $content = file_get_contents($metaFile);
        return $content !== false ? json_decode($content, true) : [];
    }

    private function saveCurrentStats(array $stats): void
    {
        $metaFile = sys_get_temp_dir() . '/semitexa-graph-diff.json';
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_nodes' => $stats['total_nodes'],
            'total_edges' => $stats['total_edges'],
            'by_type' => $stats['by_type'],
            'modules' => $stats['modules'],
        ];
        file_put_contents($metaFile, json_encode($data));
    }

    private function getCurrentStats(?string $module): array
    {
        $nodes = $module !== null ? $this->query->findNodes(module: $module) : $this->query->findNodes();
        $allNodes = $this->query->findNodes();
        $edgeCount = 0;
        foreach ($allNodes as $node) {
            $edgeCount += count($this->query->getEdges($node->id));
        }
        $edgeCount = (int) ($edgeCount / 2);

        $byType = [];
        $modules = [];
        foreach ($nodes as $node) {
            $type = $node->type->value;
            $byType[$type] = ($byType[$type] ?? 0) + 1;
            if ($node->module !== '') {
                $modules[$node->module] = true;
            }
        }

        return [
            'total_nodes' => count($nodes),
            'total_edges' => $edgeCount,
            'by_type' => $byType,
            'modules' => array_keys($modules),
        ];
    }
}
