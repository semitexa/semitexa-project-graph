<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:stats',
    description: 'Show review graph statistics and health',
)]
final class ReviewGraphStatsCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storage = $this->createStorage();

        $lastUpdate = $storage->getMeta('last_update');
        $totalNodes = $storage->getMeta('total_nodes');
        $totalEdges = $storage->getMeta('total_edges');

        $nodeCounts = $storage->nodes->countByType();
        $edgeCount = $storage->edges->countAll();
        $fileCount = count($storage->fileIndex->getAll());

        $payload = [
            'last_generated' => $lastUpdate !== null ? date('c', (int) $lastUpdate) : null,
            'indexed_files' => $fileCount,
            'total_nodes' => (int) ($totalNodes ?: 0),
            'live_nodes' => $storage->nodes->countAll(),
            'total_edges' => (int) ($totalEdges ?: $edgeCount),
            'live_edges' => $edgeCount,
            'node_counts' => $nodeCounts,
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->title('Review Graph Statistics');

        $io->section('Health');
        $io->definitionList(
            ['Last generated' => $lastUpdate ? date('Y-m-d H:i:s', (int)$lastUpdate) : 'Never'],
            ['Indexed files'  => $fileCount],
        );

        $io->section('Nodes');
        $io->definitionList(
            ['Total' => ($totalNodes ?: '0') . ' (live count: ' . $storage->nodes->countAll() . ')'],
        );

        $topTypes = array_slice($nodeCounts, 0, 10, true);
        $typeLines = [];
        foreach ($topTypes as $type => $count) {
            $typeLines[] = $type . ': ' . $count;
        }
        if ($typeLines) {
            $io->text('Top types: ' . implode(', ', $typeLines));
        }

        $io->section('Edges');
        $io->definitionList(
            ['Total' => $edgeCount],
        );

        return self::SUCCESS;
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
