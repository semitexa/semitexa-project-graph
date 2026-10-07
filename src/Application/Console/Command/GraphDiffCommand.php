<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeDiffMarkdown;
use Semitexa\ProjectGraph\Application\Service\Diff\EdgeSetDiff;
use Semitexa\ProjectGraph\Application\Service\Diff\MovedEdges;
use Semitexa\ProjectGraph\Application\Service\Diff\OrphanedRemovals;
use Semitexa\ProjectGraph\Application\Service\Diff\RefGraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
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

    private const FORMATS = ['text', 'json', 'markdown'];

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** Wiring a reviewer reads as "still there" when none of it changed. */
    private const UNCHANGED_WIRING = [
        'serves_route' => 'routes',
        'handles' => 'handlers',
        'listens_to' => 'listeners',
        'emits' => 'event emissions',
        'satisfies_contract' => 'contract bindings',
    ];

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
        if (!in_array($format, self::FORMATS, true)) {
            return $this->fail($output, 'text', sprintf('--format accepts %s; got "%s".', implode(', ', self::FORMATS), (string) $format));
        }

        $base = $input->getOption('base');
        $path = $input->getOption('path');
        $failOn = $input->getOption('fail-on');
        // A gate that silently fell back to the counts mode exited 0 on a real
        // orphan whenever its --base came from an empty CI variable.
        if ($path === '') {
            return $this->fail($output, (string) $format, '--path is empty: pass a directory, or leave the option out to compare the project root.');
        }
        if (is_string($base) && is_string($module) && $module !== '') {
            return $this->fail($output, (string) $format, '--module narrows the counts mode only; with --base, narrow by directory: --path=<dir>.');
        }
        if ($base === '' || (($failOn !== null || $path !== null) && $base === null)) {
            return $this->fail($output, (string) $format, '--fail-on and --path compare against a git ref: pass a non-empty --base=<ref>.');
        }
        if (is_string($base)) {
            if ($failOn !== null && $failOn !== 'orphaned-removals') {
                return $this->fail($output, (string) $format, '--fail-on accepts only "orphaned-removals".');
            }

            return $this->diffAgainstRef($output, $base, is_string($path) && $path !== '' ? $path : $this->getProjectRoot(), (string) $format, $failOn !== null);
        }

        // One baseline per scope: a --module run compared against, and then
        // overwrote, the whole-graph baseline (43 modules "removed").
        $metaKey = self::META_KEY . (is_string($module) && $module !== '' ? ':' . $module : '');
        $previousStats = $this->loadPreviousStats($metaKey);
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
            // Types that fell to zero are a change too.
            foreach (array_keys($currentStats['by_type'] + $prevByType) as $type) {
                $count = $currentStats['by_type'][$type] ?? 0;
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
            $diff['new_modules'] = array_values(array_diff($currentModules, $prevModules));
            $diff['removed_modules'] = array_values(array_diff($prevModules, $currentModules));
        } else {
            foreach ($currentStats['by_type'] as $type => $count) {
                $diff['by_type'][$type] = ['previous' => 0, 'current' => $count, 'delta' => $count];
            }
            $diff['new_modules'] = $currentStats['modules'] ?? [];
        }

        $this->saveCurrentStats($metaKey, $currentStats);

        if ($format === 'json') {
            ksort($diff['by_type']);
            // Always an object, so a consumer reads one shape.
            $output->writeln(json_encode(['by_type' => (object) $diff['by_type']] + $diff, self::JSON), OutputInterface::OUTPUT_RAW);
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
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            // A bad ref, a failed export, an unwritable var/: named, not a stack trace.
            return $this->fail($output, $format, $e->getMessage());
        }
        /** @var MovedEdges $moves */
        $moves = $result['moves'];
        // Moves are paired before the gate: only what is really gone can orphan.
        $diff = $moves->remaining;
        $orphans = OrphanedRemovals::find($diff, $result['base'], $result['head']);
        $headUnreadable = OrphanedRemovals::unreadableFiles($result['head']);
        $unreadable = $this->relative($headUnreadable, $result['repository']);
        // A file that was already unparseable at the base did not get worse in
        // this change; gating on it kept the gate red forever. Compared by
        // content, so `git mv` of a broken file is not "newly" broken either.
        $newly = array_filter($headUnreadable, static function (string $file) use ($result): bool {
            $hash = @hash_file('xxh3', $file);
            return $hash === false || !isset($result['base_unreadable_hashes'][$hash]);
        });
        $newlyUnreadable = $this->relative(array_values($newly), $result['repository']);
        $orphans = self::relativeOrphans($orphans, $result['repository']);
        $exit = $gate && ($orphans !== [] || $newlyUnreadable !== []) ? Command::FAILURE : Command::SUCCESS;
        $scopeNote = $result['scope_dir'] === ''
            ? null
            : sprintf('Scoped to %s: code elsewhere in %s that depends on it was not read, so a removal it still relies on is not caught here.', $result['scope_dir'], basename($result['repository']));
        $unchanged = $this->unchangedWiring($diff, $result['base'], $result['head'], $moves);

        if ($format === 'markdown') {
            $output->write(EdgeDiffMarkdown::render($diff, $baseRef, $result['scope'], (new CoverageReport($result['head']))->summary(), $orphans, $unreadable, $moves, $unchanged, $scopeNote, $newlyUnreadable));
            return $exit;
        }

        if ($format === 'json') {
            $edge = static fn (Edge $e): array => [
                'type'   => $e->getType()->value,
                'class'  => $e->getType()->edgeClass()->value,
                'source' => $e->getSourceId(),
                'target' => $e->getTargetId(),
            ];
            $output->writeln(json_encode([
                'base'              => $baseRef,
                'repository'        => $result['repository'],
                'scope'             => $result['scope'],
                'scope_note'        => $scopeNote,
                'added'             => array_map($edge, $diff->added),
                'removed'           => array_map($edge, $diff->removed),
                'moved'             => $moves->movedNodes,
                'moved_edges'       => count($moves->pairs),
                'far_end_changed'   => array_map(static fn (array $p): array => ['removed' => $edge($p['removed']), 'added' => $edge($p['added'])], $moves->farEndChanged),
                'replaced'          => array_map(static fn (array $p): array => ['removed' => $edge($p['removed']), 'added' => $edge($p['added'])], $moves->replaced),
                'unchanged'         => $unchanged,
                'orphans'           => $orphans,
                'unreadable'        => $unreadable,
                'newly_unreadable'  => $newlyUnreadable,
            ], self::JSON), OutputInterface::OUTPUT_RAW);

            return $exit;
        }

        $output->writeln(sprintf('<comment>Graph diff: %s (%s) against %s</comment>', $result['scope'], $result['repository'], $baseRef));
        if ($scopeNote !== null) {
            $output->writeln($scopeNote);
        }
        foreach ($orphans as $orphan) {
            $output->writeln(sprintf('<error>ORPHAN %s</error> %s — removed %s; still used by %s', $orphan['kind'], $orphan['subject'], $orphan['removed'], implode(', ', $orphan['still_used_by'])));
        }
        foreach ($unreadable as $file) {
            $output->writeln(sprintf('<error>UNREADABLE</error> %s — the graph could not parse it, so nothing about it is checked%s', $file, in_array($file, $newlyUnreadable, true) ? '' : ' (already unreadable at the base)'));
        }
        foreach ($moves->farEndChanged as $pair) {
            $output->writeln(sprintf('<comment>MOVED AND REWIRED</comment> %s: - %s -> %s, + %s -> %s', $pair['removed']->getType()->value, $pair['removed']->getSourceId(), $pair['removed']->getTargetId(), $pair['added']->getSourceId(), $pair['added']->getTargetId()));
        }
        foreach ($moves->replaced as $pair) {
            $output->writeln(sprintf('  replaced %s %s: %s -> %s', $pair['removed']->getType()->value, $pair['removed']->getTargetId(), $pair['removed']->getSourceId(), $pair['added']->getSourceId()));
        }
        foreach ($moves->movedNodes as $move) {
            $output->writeln(sprintf('  moved %s -> %s%s', $move['from'], $move['to'], $move['kept'] === [] ? '' : ' (' . implode(', ', $move['kept']) . ' unchanged)'));
        }
        if ($diff->isEmpty()) {
            $output->writeln($moves->movedNodes === [] ? 'No structural change.' : 'No structural change besides the moves above.');
            if ($unchanged !== []) {
                $output->writeln('Unchanged: ' . implode(', ', $unchanged) . '.');
            }
            return $exit;
        }
        $output->writeln(sprintf('+%d / -%d edges', count($diff->added), count($diff->removed)));
        foreach (['-' => $diff->removed, '+' => $diff->added] as $sign => $edges) {
            foreach ($edges as $e) {
                $output->writeln(sprintf('  %s %s %s -> %s', $sign, $e->getType()->value, $e->getSourceId(), $e->getTargetId()));
            }
        }
        if ($unchanged !== []) {
            $output->writeln('Unchanged: ' . implode(', ', $unchanged) . '.');
        }

        return $exit;
    }

    /**
     * The wiring kinds present on either side that no remaining edge touches:
     * "routes unchanged" is evidence a reviewer can rely on, not an assumption.
     *
     * @return list<string>
     */
    private function unchangedWiring(EdgeSetDiff $diff, GraphStorage $base, GraphStorage $head, ?MovedEdges $moves = null): array
    {
        $changed = [];
        // A replaced provider is a change of that wiring, though it is paired.
        $replacedEdges = [];
        foreach ($moves?->replaced ?? [] as $pair) {
            $replacedEdges[] = $pair['removed'];
            $replacedEdges[] = $pair['added'];
        }
        foreach ([...$diff->added, ...$diff->removed, ...$replacedEdges] as $edge) {
            $changed[$edge->getType()->value] = true;
        }
        $unchanged = [];
        foreach (self::UNCHANGED_WIRING as $type => $name) {
            if (isset($changed[$type])) {
                continue;
            }
            $kind = EdgeType::from($type);
            if ($base->edges->findByType($kind, 1) !== [] || $head->edges->findByType($kind, 1) !== []) {
                $unchanged[] = $name;
            }
        }

        return $unchanged;
    }

    /**
     * @param list<string> $files
     * @return list<string> relative to $root (then to $scopeDir inside it), as a reviewer reads them
     */
    private function relative(array $files, string $root, string $scopeDir = ''): array
    {
        $prefix = rtrim($root, '/') . '/';
        $out = array_map(
            static fn (string $f): string => str_starts_with($f, $prefix) ? ltrim(($scopeDir === '' ? '' : $scopeDir . '/') . substr($f, strlen($prefix)), '/') : $f,
            $files,
        );
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * @param list<array{kind: string, subject: string, removed: string, still_used_by: list<string>}> $orphans
     * @return list<array{kind: string, subject: string, removed: string, still_used_by: list<string>}>
     */
    private static function relativeOrphans(array $orphans, string $repository): array
    {
        $prefix = 'file:' . rtrim($repository, '/') . '/';
        $local = static fn (string $id): string => str_starts_with($id, $prefix) ? 'file:' . substr($id, strlen($prefix)) : $id;
        foreach ($orphans as &$orphan) {
            $orphan['subject'] = $local($orphan['subject']);
            $orphan['still_used_by'] = array_map($local, $orphan['still_used_by']);
        }

        return $orphans;
    }

    private function fail(OutputInterface $output, string $format, string $message): int
    {
        if ($format === 'json') {
            $output->writeln(json_encode(['error' => $message], self::JSON), OutputInterface::OUTPUT_RAW);
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }

        return Command::FAILURE;
    }

    /** The counts baseline lives in the graph itself, not in a temp file lost with the container. */
    private function loadPreviousStats(string $key): array
    {
        $content = $this->storage()->getMeta($key);
        $decoded = $content !== null ? json_decode($content, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function saveCurrentStats(string $key, array $stats): void
    {
        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_nodes' => $stats['total_nodes'],
            'total_edges' => $stats['total_edges'],
            'by_type' => $stats['by_type'],
            'modules' => $stats['modules'],
        ];
        $this->storage()->setMeta($key, (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
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
