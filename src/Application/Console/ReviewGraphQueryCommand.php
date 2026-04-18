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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $storage = $this->createStorage();
        $query = new GraphQueryService($storage);

        $usages = $input->getOption('usages');
        $deps = $input->getOption('dependencies');
        $crossModule = $input->getOption('cross-module');
        $search = $input->getOption('search');
        $type = $input->getOption('type');
        $module = $input->getOption('module');
        $json = $input->getOption('json');

        if ($usages !== null) {
            $edges = $query->getUsages($usages, 3);
            $this->renderEdges($io, $edges, $query, $json);
        } elseif ($deps !== null) {
            $edges = $query->getDependencies($deps, 3);
            $this->renderEdges($io, $edges, $query, $json);
        } elseif ($crossModule) {
            $from = $input->getOption('from');
            $to = $input->getOption('to');
            $edges = $query->getCrossModuleEdges($from, $to);
            $this->renderEdges($io, $edges, $query, $json);
        } elseif ($search !== null) {
            $nodes = $query->search($search);
            $this->renderNodes($io, $nodes, $json);
        } elseif ($type !== null) {
            $nodes = $query->findNodes(type: $type, module: $module);
            $this->renderNodes($io, $nodes, $json);
        } else {
            $io->error('No query specified. Use --usages, --dependencies, --cross-module, --search, or --type.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param list<\Semitexa\ProjectGraph\Domain\Model\Edge> $edges */
    private function renderEdges(SymfonyStyle $io, array $edges, GraphQueryService $query, bool $json): void
    {
        if ($json) {
            $data = array_map(fn($e) => [
                'source'   => $e->sourceId,
                'target'   => $e->targetId,
                'type'     => $e->type->value,
                'metadata' => $e->metadata,
            ], $edges);
            $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
    private function renderNodes(SymfonyStyle $io, array $nodes, bool $json): void
    {
        if ($json) {
            $data = array_map(fn($n) => [
                'id'       => $n->id,
                'type'     => $n->type->value,
                'fqcn'     => $n->fqcn,
                'file'     => $n->file,
                'module'   => $n->module,
            ], $nodes);
            $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
