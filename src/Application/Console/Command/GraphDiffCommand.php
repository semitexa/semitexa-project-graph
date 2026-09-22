<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:diff', description: 'Show how the graph changed since last scan')]
final class GraphDiffCommand extends BaseCommand
{
    private const META_KEY = 'graph_diff_last_scan';

    use UsesProjectGraphConnection;

    private ?GraphStorage $graphStorage = null;
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
        return $this->queryService ??= new GraphQueryService($this->storage());
    }

    private function storage(): GraphStorage
    {
        return $this->graphStorage ??= $this->createProjectGraphStorage($this->connections);
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
            $output->writeln(json_encode($diff, JSON_UNESCAPED_SLASHES));
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

    /**
     * A census of the graph, counted in the database rather than in PHP.
     *
     * This asked GraphQueryService::findNodes() with no filters, which returns an empty list
     * by contract — there is no "everything" branch — so an unscoped diff reported 0 no matter
     * what the graph held. It then summed getEdges() per node, one query each, to arrive at a
     * number the edge repository already knows. Neither was observable until the command could
     * be run at all; the first thing it said once registered was 6396 → 0.
     *
     * @return array{total_nodes: int, total_edges: int, by_type: array<string, int>, modules: list<string>}
     */
    private function getCurrentStats(?string $module): array
    {
        $nodes = $this->storage()->nodes;

        if ($module === null) {
            return [
                'total_nodes' => $nodes->countAll(),
                'total_edges' => $this->storage()->edges->countAll(),
                'by_type' => $nodes->countByType(),
                'modules' => $nodes->distinctModules(),
            ];
        }

        // Scoped run: the edge total has to be scoped too. Reporting the graph-wide count
        // beside a module's node count is not a smaller truth, it is a wrong one — a module
        // with no nodes would read as zero nodes and every edge in the project.
        $scoped = $nodes->findByModule($module);
        $byType = [];
        $modules = [];
        foreach ($scoped as $node) {
            $byType[$node->getType()->value] = ($byType[$node->getType()->value] ?? 0) + 1;
            if ($node->getModule() !== '') {
                $modules[$node->getModule()] = true;
            }
        }

        return [
            'total_nodes' => count($scoped),
            'total_edges' => $this->storage()->edges->countTouchingModule($module),
            'by_type' => $byType,
            'modules' => array_keys($modules),
        ];
    }
}
