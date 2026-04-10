<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:stats',
    description: 'Show review graph statistics and health',
)]
final class ReviewGraphStatsCommand extends BaseCommand
{
    #[InjectAsReadonly] private OrmManager $orm;

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
        $adapter = $this->orm->getAdapter();
        $txManager = $this->orm->getTransactionManager();
        $mapperRegistry = $this->orm->getMapperRegistry();
        $hydrator = $this->orm->getTableModelHydrator();
        $metadataRegistry = $this->orm->getTableModelMetadataRegistry();
        $relationLoader = $this->orm->getTableModelRelationLoader();
        $writeEngine = $this->orm->getAggregateWriteEngine();

        return new GraphStorage(
            $adapter,
            $txManager,
            $mapperRegistry,
            $hydrator,
            $metadataRegistry,
            $relationLoader,
            $writeEngine,
        );
    }
}
