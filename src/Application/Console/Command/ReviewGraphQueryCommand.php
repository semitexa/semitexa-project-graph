<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
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

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

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
            $edges = $query->getUsages($nodeId, 3);
            return $compact
                ? $this->renderCompact($io, $edges, $query, $nodeId, $json)
                : $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($deps) && $deps !== '') {
            $nodeId = $this->resolveNodeId($storage, $deps);
            if ($nodeId === null) {
                $io->error('Node not found: ' . $deps);
                return self::FAILURE;
            }
            $edges = $query->getDependencies($nodeId, 3);
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
            $otherId = $e->sourceId === $anchorId ? $e->targetId : $e->sourceId;
            $node = $query->getNode($otherId);
            $label = $node ? $node->fqcn : $otherId;
            $groups[$label]['kinds'][$e->type->value] = true;
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
                'source'   => $e->sourceId,
                'target'   => $e->targetId,
                'type'     => $e->type->value,
                'metadata' => $e->metadata,
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
                    'source'   => $edge->sourceId,
                    'target'   => $edge->targetId,
                    'type'     => $edge->type->value,
                    'metadata' => $edge->metadata,
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
            $source = $query->getNode($edge->sourceId);
            $target = $query->getNode($edge->targetId);
            $srcLabel = $source ? $source->fqcn : $edge->sourceId;
            $tgtLabel = $target ? $target->fqcn : $edge->targetId;
            $io->text($srcLabel . ' --[' . $edge->type->value . ']--> ' . $tgtLabel);
        }

        return self::SUCCESS;
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Node> $nodes */
    private function renderNodes(SymfonyStyle $io, array $nodes, bool $json, bool $ndjson): int
    {
        if ($json) {
            $data = array_map(fn($n) => [
                'id'       => $n->id,
                'type'     => $n->type->value,
                'fqcn'     => $n->fqcn,
                'file'     => $n->file,
                'module'   => $n->module,
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
                    'id'     => $node->id,
                    'type'   => $node->type->value,
                    'fqcn'   => $node->fqcn,
                    'file'   => $node->file,
                    'module' => $node->module,
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
            $io->text('[' . $node->type->value . '] ' . $node->fqcn . ' (' . $node->module . ')');
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
            return $node->id;
        }

        $node = $storage->nodes->findByFqcn($target);
        if ($node !== null) {
            return $node->id;
        }

        return null;
    }
}
