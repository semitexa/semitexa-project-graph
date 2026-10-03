<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\ProjectGraph\Application\Service\Support\RefusesInMachineFormat;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\NodeResolver;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:query',
    description: 'Run ad-hoc queries against the review graph',
)]
final class ReviewGraphQueryCommand extends BaseCommand
{
    use RefusesInMachineFormat;

    use AutoRefreshesProjectGraph;
    use UsesProjectGraphConnection;

    /**
     * Property injection, not a constructor parameter: #[AsCommand] classes are
     * container-managed, and semitexa.injectionViaConstructor makes that the only DI channel.
     * The rule fires per changed file, so a constructor here is a violation waiting for the
     * next person to edit the file rather than a clean build.
     */
    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter by node type');
        $this->addOption('module', null, InputOption::VALUE_REQUIRED, 'Filter by module');
        $this->addOption('usages', null, InputOption::VALUE_REQUIRED, 'Find usages of a class');
        $this->addOption('dependencies', null, InputOption::VALUE_REQUIRED, 'Find dependencies of a class');
        $this->addOption('cross-module', null, InputOption::VALUE_NONE, 'Show cross-module dependencies');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Cross-module: from module');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Cross-module: to module');
        $this->addOption('search', null, InputOption::VALUE_REQUIRED, 'Names and FQCNs containing the text, literally (exact-prefix names first)');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'With --search: how many matches to show', '20');
        $this->addOption('compact', null, InputOption::VALUE_NONE, 'Deduplicated per-class summary (LLM-friendly): one row per counterpart class with edge kinds + count');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
        $this->addOption('ndjson', null, InputOption::VALUE_NONE, 'Output as NDJSON');
        $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Skip the incremental staleness refresh before querying');
        $this->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Traversal depth for --usages/--dependencies (1 = direct relations only; higher values expand transitively and grow output fast)', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storage = $this->createStorage();
        $this->refreshProjectGraph(
            $storage,
            $io,
            (bool) $input->getOption('no-refresh'),
            (bool) $input->getOption('json') || (bool) $input->getOption('ndjson'),
        );
        $query = new GraphQueryService($storage);

        $usages = $input->getOption('usages');
        $deps = $input->getOption('dependencies');
        $crossModule = (bool) $input->getOption('cross-module');
        $search = $input->getOption('search');
        $type = $input->getOption('type');
        $module = $input->getOption('module');
        $json = (bool) $input->getOption('json');
        $compact = (bool) $input->getOption('compact');
        $ndjson = (bool) $input->getOption('ndjson');

        if ($json && $ndjson) {
            return $this->refuse($output, $io, 'Use either --json or --ndjson, not both.', $json || $ndjson);
        }

        // 0, -1, abc and 1.9 used to run silently as 1.
        $rawDepth = (string) $input->getOption('depth');
        if (!ctype_digit($rawDepth) || (int) $rawDepth < 1) {
            return $this->refuse($output, $io, '--depth must be a whole number, at least 1.', $json || $ndjson);
        }
        $depth = (int) $rawDepth;

        // An unknown module or type used to answer "nothing found" with exit 0.
        $knownModules = null;
        foreach (['module' => $module, 'from' => $input->getOption('from'), 'to' => $input->getOption('to')] as $option => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $knownModules ??= $storage->nodes->distinctModules();
            if (!is_string($value) || !in_array($value, $knownModules, true)) {
                sort($knownModules);
                return $this->refuse($output, $io, sprintf('--%s: no module "%s" in the graph. Known: %s.', $option, is_string($value) ? $value : '', implode(', ', $knownModules)), $json || $ndjson);
            }
        }
        $module = is_string($module) && $module !== '' ? $module : null;
        if (is_string($type) && $type !== '' && NodeType::tryFrom($type) === null) {
            return $this->refuse($output, $io, sprintf('Unknown --type "%s". Known: %s.', $type, implode(', ', array_map(static fn (NodeType $t): string => $t->value, NodeType::cases()))), $json || $ndjson);
        }
        $resolver = new NodeResolver($storage, $this->getProjectRoot());

        if ((is_string($usages) && $usages !== '') || (is_string($deps) && $deps !== '')) {
            $usagesMode = is_string($usages) && $usages !== '';
            $subject = (string) ($usagesMode ? $usages : $deps);
            $node = $resolver->resolve($subject);
            if ($node === null) {
                return $this->refuse($output, $io, $resolver->notFound($subject), $json || $ndjson);
            }
            $nodeId = $node->getId();
            $edges = $usagesMode ? $query->getUsages($nodeId, $depth) : $query->getDependencies($nodeId, $depth);
            // The class at the far end of each edge: the user for usages, the
            // dependency for dependencies — at any depth, not "the end that is
            // not the anchor", which only holds at depth 1.
            $far = static fn (Edge $e): string => $usagesMode ? $e->getSourceId() : $e->getTargetId();
            if ($module !== null || (is_string($type) && $type !== '')) {
                // --module (and --type) were ignored here: both narrow the far end.
                $inModule = [];
                foreach ($edges as $e) {
                    $inModule[$far($e)] = true;
                }
                $nodesById = $storage->nodes->findByIds(array_keys($inModule));
                $wantType = is_string($type) && $type !== '' ? $type : null;
                $edges = array_values(array_filter($edges, static function (Edge $e) use ($nodesById, $far, $module, $wantType): bool {
                    $node = $nodesById[$far($e)] ?? null;

                    return $node !== null
                        && ($module === null || $node->getModule() === $module)
                        && ($wantType === null || $node->getType()->value === $wantType);
                }));
            }
            $coverage = (new CoverageReport($storage))->forNodes([$nodeId]);
            return $compact
                ? $this->renderCompact($io, $edges, $storage, $far, $nodeId, $json, $ndjson, $coverage)
                : $this->renderEdges($io, $edges, $query, $json, $ndjson, $coverage);
        } elseif ($crossModule) {
            $from = $input->getOption('from');
            $to = $input->getOption('to');
            if ($from !== null && !is_string($from)) {
                return $this->refuse($output, $io, 'Option --from must be a string.', $json || $ndjson);
            }
            if ($to !== null && !is_string($to)) {
                return $this->refuse($output, $io, 'Option --to must be a string.', $json || $ndjson);
            }
            $edges = $query->getCrossModuleEdges($from, $to);
            return $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($search) && $search !== '') {
            $rawLimit = (string) $input->getOption('limit');
            if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
                return $this->refuse($output, $io, '--limit must be a whole number, at least 1.', $json || $ndjson);
            }
            $limit = (int) $rawLimit;
            $nodes = $query->search($search, $limit + 1, $module, is_string($type) && $type !== '' ? $type : null);
            $truncated = count($nodes) > $limit;
            $nodes = array_slice($nodes, 0, $limit);
            $result = $this->renderNodes($io, $nodes, $json, $ndjson);
            // It stopped at 20 without a word.
            if ($truncated) {
                if ($ndjson) {
                    $io->writeln((string) json_encode(['kind' => 'truncated', 'shown' => $limit], JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
                } elseif ($json) {
                    $io->getErrorStyle()->warning(sprintf('Showing the first %d matches; raise --limit for more.', $limit));
                } else {
                    $io->text(sprintf('Showing the first %d matches; raise --limit for more.', $limit));
                }
            }
            return $result;
        } elseif (is_string($type) && $type !== '') {
            if ($module !== null && !is_string($module)) {
                return $this->refuse($output, $io, 'Option --module must be a string.', $json || $ndjson);
            }
            $nodes = $query->findNodes(type: $type, module: $module);
            return $this->renderNodes($io, $nodes, $json, $ndjson);
        } else {
            return $this->refuse($output, $io, 'No query specified. Use --usages, --dependencies, --cross-module, --search, or --type.', $json || $ndjson);
        }
    }

    /**
     * LLM-friendly summary: raw edge dumps repeat the anchor on every row and
     * list one row per edge (a hot class yields hundreds of rows / tens of KB).
     * Group by the counterpart class instead — one row per class with its
     * distinct edge kinds and edge count. Same information an agent needs to
     * judge blast radius, at ~1/20 the tokens.
     *
     * @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges
     */
    /**
     * @param list<Edge> $edges
     * @param \Closure(Edge): string $far the counterpart of each edge
     * @param ?array<string, mixed> $coverage
     */
    private function renderCompact(SymfonyStyle $io, array $edges, GraphStorage $storage, \Closure $far, string $anchorId, bool $json, bool $ndjson, ?array $coverage = null): int
    {
        $ids = [];
        foreach ($edges as $e) {
            $ids[$far($e)] = true;
        }
        $nodes = $storage->nodes->findByIds(array_keys($ids));

        $groups = [];
        foreach ($edges as $e) {
            $otherId = $far($e);
            $node = $nodes[$otherId] ?? null;
            // Synthetic nodes have no FQCN: three of them used to share the label "".
            $label = $node === null ? $otherId : ($node->getFqcn() !== '' ? $node->getFqcn() : $otherId);
            $groups[$label]['kinds'][$e->getType()->value] = true;
            $groups[$label]['count'] = ($groups[$label]['count'] ?? 0) + 1;
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $label => $g) {
            $kinds = array_keys($g['kinds']);
            sort($kinds);
            $rows[] = ['class' => (string) $label, 'kinds' => $kinds, 'edges' => $g['count']];
        }

        if ($json) {
            $payload = ['anchor' => $anchorId, 'classes' => count($rows), 'related' => $rows];
            if ($coverage !== null) {
                $payload['coverage'] = $coverage;
            }
            $io->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if ($ndjson) {
            // --compact used to ignore --ndjson and print text.
            foreach ($rows as $row) {
                $io->writeln((string) json_encode(['kind' => 'related'] + $row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
            }
            if ($coverage !== null) {
                $io->writeln((string) json_encode(['kind' => 'coverage'] + $coverage, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            }

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->renderNoEdges($io, $coverage);

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $io->writeln('  ' . $row['class'] . '  [' . implode(', ', $row['kinds']) . '] ×' . $row['edges'], OutputInterface::OUTPUT_RAW);
        }
        $io->text(count($rows) . ' related class(es).');
        $this->renderCoverageFooter($io, $coverage);

        return self::SUCCESS;
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges */
    /**
     * --json keeps its bare list of edges (existing consumers parse it as
     * such); the coverage block reaches machine readers through --ndjson (a
     * trailing kind:coverage line) and --compact --json.
     *
     * @param ?array<string, mixed> $coverage
     */
    private function renderEdges(SymfonyStyle $io, array $edges, GraphQueryService $query, bool $json, bool $ndjson, ?array $coverage = null): int
    {
        if ($json) {
            $data = array_map(fn($e) => [
                'source'   => $e->getSourceId(),
                'target'   => $e->getTargetId(),
                'type'     => $e->getType()->value,
                'metadata' => $e->getMetadata(),
            ], $edges);
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                return $this->refuse($io, $io, 'Failed to encode JSON payload.', $json || $ndjson);
            }

            $io->writeln($payload, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        if ($ndjson) {
            foreach ($edges as $edge) {
                $line = json_encode([
                    'kind'     => 'edge',
                    'source'   => $edge->getSourceId(),
                    'target'   => $edge->getTargetId(),
                    'type'     => $edge->getType()->value,
                    'metadata' => $edge->getMetadata(),
                ], JSON_UNESCAPED_SLASHES);
                if ($line === false) {
                    return $this->refuse($io, $io, 'Failed to encode NDJSON edge.', $json || $ndjson);
                }

                $io->writeln($line, OutputInterface::OUTPUT_RAW);
            }
            if ($coverage !== null) {
                $io->writeln((string) json_encode(['kind' => 'coverage'] + $coverage, JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
            }

            return self::SUCCESS;
        }

        if (empty($edges)) {
            $this->renderNoEdges($io, $coverage);
            return self::SUCCESS;
        }

        foreach ($edges as $edge) {
            $source = $query->getNode($edge->getSourceId());
            $target = $query->getNode($edge->getTargetId());
            $srcLabel = $source ? $source->getFqcn() : $edge->getSourceId();
            $tgtLabel = $target ? $target->getFqcn() : $edge->getTargetId();
            $io->text($srcLabel . ' --[' . $edge->getType()->value . ']--> ' . $tgtLabel);
        }
        $this->renderCoverageFooter($io, $coverage);

        return self::SUCCESS;
    }

    /** @param ?array<string, mixed> $coverage */
    private function renderNoEdges(SymfonyStyle $io, ?array $coverage): void
    {
        if ($coverage === null || $coverage['absence_is_proof']) {
            $io->text('No edges found.');
            return;
        }

        $io->text('No edges found — not proof: the graph did not see everything.');
        $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));
    }

    /** @param ?array<string, mixed> $coverage */
    private function renderCoverageFooter(SymfonyStyle $io, ?array $coverage): void
    {
        if ($coverage !== null && !$coverage['absence_is_proof']) {
            $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));
        }
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Node> $nodes */
    private function renderNodes(SymfonyStyle $io, array $nodes, bool $json, bool $ndjson): int
    {
        if ($json) {
            $data = array_map(fn($n) => [
                'id'       => $n->getId(),
                'type'     => $n->getType()->value,
                'fqcn'     => $n->getFqcn(),
                'file'     => $n->getFile(),
                'module'   => $n->getModule(),
            ], $nodes);
            $payload = json_encode($data, JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                return $this->refuse($io, $io, 'Failed to encode JSON payload.', $json || $ndjson);
            }

            $io->writeln($payload, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        if ($ndjson) {
            foreach ($nodes as $node) {
                $line = json_encode([
                    'kind'   => 'node',
                    'id'     => $node->getId(),
                    'type'   => $node->getType()->value,
                    'fqcn'   => $node->getFqcn(),
                    'file'   => $node->getFile(),
                    'module' => $node->getModule(),
                ], JSON_UNESCAPED_SLASHES);
                if ($line === false) {
                    return $this->refuse($io, $io, 'Failed to encode NDJSON node.', $json || $ndjson);
                }

                $io->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            return self::SUCCESS;
        }

        if (empty($nodes)) {
            $io->text('No nodes found.');
            return self::SUCCESS;
        }

        foreach ($nodes as $node) {
            $io->writeln(' [' . $node->getType()->value . '] ' . ($node->getFqcn() !== '' ? $node->getFqcn() : $node->getId()) . ' (' . $node->getModule() . ')', OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
