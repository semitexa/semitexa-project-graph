<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeDiffMarkdown;
use Semitexa\ProjectGraph\Application\Service\Diff\OrphanedRemovals;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
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
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json, markdown (markdown: with --base, a pull-request comment)', 'text');
        $this->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Limit to module (counts mode only)');
        $this->addOption('base', null, InputOption::VALUE_REQUIRED, 'Git ref to compare the working tree with, edge by edge (e.g. origin/develop)');
        $this->addOption('path', null, InputOption::VALUE_REQUIRED, 'With --base: directory to compare, inside a git repository (default: the project root)');
        $this->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'With --base: "orphaned-removals" exits non-zero when a removal leaves something pointing at nothing, or when the head has files the graph could not parse');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format') ?? 'text';
        $module = $input->getOption('module');

        $base = $input->getOption('base');
        if (is_string($base) && $base !== '') {
            $path = $input->getOption('path');
            $failOn = $input->getOption('fail-on');
            if ($failOn !== null && $failOn !== 'orphaned-removals') {
                $output->writeln('<error>--fail-on accepts only "orphaned-removals".</error>');
                return Command::FAILURE;
            }

            return $this->diffAgainstRef($output, $base, is_string($path) && $path !== '' ? $path : $this->getProjectRoot(), (string) $format, $failOn !== null);
        }

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

    /**
     * The working tree against $baseRef: both graphs built fresh over the same
     * directory, compared edge by edge. The project graph is not touched.
     */
    private function diffAgainstRef(OutputInterface $output, string $baseRef, string $path, string $format, bool $gate): int
    {
        if (!str_starts_with($path, '/')) {
            $path = $this->getProjectRoot() . '/' . $path;
        }

        try {
            $result = (new RefGraphDiff())->diff($path, $baseRef, $this->getProjectRoot() . '/var/tmp');
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $diff = $result['diff'];
        $orphans = OrphanedRemovals::find($diff, $result['base'], $result['head']);
        $unreadable = OrphanedRemovals::unreadableFiles($result['head']);
        // A gate that could not read part of the code must not pass it.
        $exit = $gate && ($orphans !== [] || $unreadable !== []) ? Command::FAILURE : Command::SUCCESS;

        if ($format === 'markdown') {
            $output->write(EdgeDiffMarkdown::render($diff, $baseRef, $result['scope'], (new CoverageReport($result['head']))->summary(), $orphans, $unreadable));
            return $exit;
        }

        if ($format === 'json') {
            $edge = static fn (Edge $e): array => [
                'type'   => $e->getType()->value,
                'class'  => $e->getType()->edgeClass()->value,
                'source' => $e->getSourceId(),
                'target' => $e->getTargetId(),
            ];
            $output->writeln((string) json_encode([
                'base'       => $baseRef,
                'repository' => $result['repository'],
                'scope'      => $result['scope'],
                'added'      => array_map($edge, $diff->added),
                'removed'    => array_map($edge, $diff->removed),
                'orphans'    => $orphans,
                'unreadable' => $unreadable,
            ], JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $output->writeln(sprintf('<comment>Graph diff: %s (%s) against %s</comment>', $result['scope'], $result['repository'], $baseRef));
        foreach ($orphans as $orphan) {
            $output->writeln(sprintf('<error>ORPHAN %s</error> %s — removed %s; still used by %s', $orphan['kind'], $orphan['subject'], $orphan['removed'], implode(', ', $orphan['still_used_by'])));
        }
        foreach ($unreadable as $file) {
            $output->writeln('<error>UNREADABLE</error> ' . $file . ' — the graph could not parse it, so nothing about it is checked');
        }
        if ($diff->isEmpty()) {
            $output->writeln('No structural change.');
            return $exit;
        }
        $output->writeln(sprintf('+%d / -%d edges', count($diff->added), count($diff->removed)));
        foreach (['-' => $diff->removed, '+' => $diff->added] as $sign => $edges) {
            foreach ($edges as $e) {
                $output->writeln(sprintf('  %s %s %s -> %s', $sign, $e->getType()->value, $e->getSourceId(), $e->getTargetId()));
            }
        }

        return $exit;
    }

    /** The counts baseline lives in the graph itself, not in a temp file lost with the container. */
    private function loadPreviousStats(): array
    {
        $content = $this->storage()->getMeta(self::META_KEY);
        $decoded = $content !== null ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function saveCurrentStats(array $stats): void
    {
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_nodes' => $stats['total_nodes'],
            'total_edges' => $stats['total_edges'],
            'by_type' => $stats['by_type'],
            'modules' => $stats['modules'],
        ];
        $this->storage()->setMeta(self::META_KEY, (string) json_encode($data));
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
