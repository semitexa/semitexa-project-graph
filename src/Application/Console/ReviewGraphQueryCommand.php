<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Query\Direction;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Support\UsesProjectGraphConnection;
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
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
        $this->addOption('ndjson', null, InputOption::VALUE_NONE, 'Output as NDJSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storage = $this->createStorage();
        $query = new GraphQueryService($storage);

        $usages = $input->getOption('usages');
        $deps = $input->getOption('dependencies');
        $crossModule = (bool) $input->getOption('cross-module');
        $search = $input->getOption('search');
        $type = $input->getOption('type');
        $module = $input->getOption('module');
        $json = (bool) $input->getOption('json');
        $ndjson = (bool) $input->getOption('ndjson');

        if ($json && $ndjson) {
            $io->error('Use either --json or --ndjson, not both.');
            return self::FAILURE;
        }

        if (is_string($usages) && $usages !== '') {
            $edges = $query->getUsages($usages, 3);
            $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($deps) && $deps !== '') {
            $edges = $query->getDependencies($deps, 3);
            $this->renderEdges($io, $edges, $query, $json, $ndjson);
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
            $this->renderEdges($io, $edges, $query, $json, $ndjson);
        } elseif (is_string($search) && $search !== '') {
            $nodes = $query->search($search);
            $this->renderNodes($io, $nodes, $json, $ndjson);
        } elseif (is_string($type) && $type !== '') {
            if ($module !== null && !is_string($module)) {
                $io->error('Option --module must be a string.');
                return self::FAILURE;
            }
            $nodes = $query->findNodes(type: $type, module: $module);
            $this->renderNodes($io, $nodes, $json, $ndjson);
        } else {
            $io->error('No query specified. Use --usages, --dependencies, --cross-module, --search, or --type.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges */
    private function renderEdges(SymfonyStyle $io, array $edges, GraphQueryService $query, bool $json, bool $ndjson): void
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
                return;
            }

            $io->writeln($payload);
            return;
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
                    return;
                }

                $io->writeln($line);
            }
            return;
        }

        if (empty($edges)) {
            $io->text('No edges found.');
            return;
        }

        foreach ($edges as $edge) {
            $source = $query->getNode($edge->sourceId);
            $target = $query->getNode($edge->targetId);
            $srcLabel = $source ? $source->fqcn : $edge->sourceId;
            $tgtLabel = $target ? $target->fqcn : $edge->targetId;
            $io->text($srcLabel . ' --[' . $edge->type->value . ']--> ' . $tgtLabel);
        }
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Node> $nodes */
    private function renderNodes(SymfonyStyle $io, array $nodes, bool $json, bool $ndjson): void
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
                return;
            }

            $io->writeln($payload);
            return;
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
                    return;
                }

                $io->writeln($line);
            }
            return;
        }

        if (empty($nodes)) {
            $io->text('No nodes found.');
            return;
        }

        foreach ($nodes as $node) {
            $io->text('[' . $node->type->value . '] ' . $node->fqcn . ' (' . $node->module . ')');
        }
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
