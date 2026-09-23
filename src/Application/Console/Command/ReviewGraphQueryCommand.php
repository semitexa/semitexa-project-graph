<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
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
        $this->addOption('search', null, InputOption::VALUE_REQUIRED, 'Full-text search');
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
            $io->error('Use either --json or --ndjson, not both.');
            return self::FAILURE;
        }

        if (is_string($usages) && $usages !== '') {
            $nodeId = $this->resolveNodeId($storage, $usages);
            if ($nodeId === null) {
                $io->error('Node not found: ' . $usages);
                return self::FAILURE;
            }
            $edges = $query->getUsages($nodeId, max(1, (int) $input->getOption('depth')));
            return $compact
                ? $this->renderCompact($io, $edges, $query, $nodeId, $json)
                : $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($deps) && $deps !== '') {
            $nodeId = $this->resolveNodeId($storage, $deps);
            if ($nodeId === null) {
                $io->error('Node not found: ' . $deps);
                return self::FAILURE;
            }
            $edges = $query->getDependencies($nodeId, max(1, (int) $input->getOption('depth')));
            return $compact
                ? $this->renderCompact($io, $edges, $query, $nodeId, $json)
                : $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif ($crossModule) {
            $from = $input->getOption('from');
            $to = $input->getOption('to');
            if ($from !== null && !is_string($from)) {
                $io->error('Option --from must be a string.');
                return self::FAILURE;
            }
            if ($to !== null && !is_string($to)) {
                $io->error('Option --to must be a string.');
                return self::FAILURE;
            }
            $edges = $query->getCrossModuleEdges($from, $to);
            return $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($search) && $search !== '') {
            $nodes = $query->search($search);
            return $this->renderNodes($io, $nodes, $json, $ndjson);
        } elseif (is_string($type) && $type !== '') {
            if ($module !== null && !is_string($module)) {
                $io->error('Option --module must be a string.');
                return self::FAILURE;
            }
            $nodes = $query->findNodes(type: $type, module: $module);
            return $this->renderNodes($io, $nodes, $json, $ndjson);
        } else {
            $io->error('No query specified. Use --usages, --dependencies, --cross-module, --search, or --type.');
            return self::FAILURE;
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
    private function renderCompact(SymfonyStyle $io, array $edges, GraphQueryService $query, string $anchorId, bool $json): int
    {
        $groups = [];
        foreach ($edges as $e) {
            $otherId = $e->getSourceId() === $anchorId ? $e->getTargetId() : $e->getSourceId();
            $node = $query->getNode($otherId);
            $label = $node ? $node->getFqcn() : $otherId;
            $groups[$label]['kinds'][$e->getType()->value] = true;
            $groups[$label]['count'] = ($groups[$label]['count'] ?? 0) + 1;
        }
        ksort($groups);

        if ($json) {
            $out = [];
            foreach ($groups as $fqcn => $g) {
                $out[] = ['class' => $fqcn, 'kinds' => array_keys($g['kinds']), 'edges' => $g['count']];
            }
            $io->writeln((string) json_encode(['anchor' => $anchorId, 'classes' => count($out), 'related' => $out], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($groups === []) {
            $io->text('No edges found.');

            return self::SUCCESS;
        }

        foreach ($groups as $fqcn => $g) {
            $io->text($fqcn . '  [' . implode(', ', array_keys($g['kinds'])) . '] ×' . $g['count']);
        }
        $io->text(count($groups) . ' related class(es).');

        return self::SUCCESS;
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges */
    private function renderEdges(SymfonyStyle $io, array $edges, GraphQueryService $query, bool $json, bool $ndjson): int
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
                $io->error('Failed to encode JSON payload.');
                return self::FAILURE;
            }

            $io->writeln($payload);
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
                    $io->error('Failed to encode NDJSON edge.');
                    return self::FAILURE;
                }

                $io->writeln($line);
            }

            return self::SUCCESS;
        }

        if (empty($edges)) {
            $io->text('No edges found.');
            return self::SUCCESS;
        }

        foreach ($edges as $edge) {
            $source = $query->getNode($edge->getSourceId());
            $target = $query->getNode($edge->getTargetId());
            $srcLabel = $source ? $source->getFqcn() : $edge->getSourceId();
            $tgtLabel = $target ? $target->getFqcn() : $edge->getTargetId();
            $io->text($srcLabel . ' --[' . $edge->getType()->value . ']--> ' . $tgtLabel);
        }

        return self::SUCCESS;
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
                $io->error('Failed to encode JSON payload.');
                return self::FAILURE;
            }

            $io->writeln($payload);
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
                    $io->error('Failed to encode NDJSON node.');
                    return self::FAILURE;
                }

                $io->writeln($line);
            }

            return self::SUCCESS;
        }

        if (empty($nodes)) {
            $io->text('No nodes found.');
            return self::SUCCESS;
        }

        foreach ($nodes as $node) {
            $io->text('[' . $node->getType()->value . '] ' . $node->getFqcn() . ' (' . $node->getModule() . ')');
        }

        return self::SUCCESS;
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }

    private function resolveNodeId(GraphStorage $storage, string $target): ?string
    {
        $node = $storage->nodes->findById($target);
        if ($node !== null) {
            return $node->getId();
        }

        $node = $storage->nodes->findByFqcn($target);
        if ($node !== null) {
            return $node->getId();
        }

        return null;
    }
}
