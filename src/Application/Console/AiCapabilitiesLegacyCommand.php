<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Projection\CapabilityProjection;
use Semitexa\ProjectGraph\Application\Projection\CommandCapabilityEnricher;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:capabilities',
    description: '[DEPRECATED] Use ai:review-graph:capabilities instead',
)]
final class AiCapabilitiesLegacyCommand extends BaseCommand
{
    #[InjectAsReadonly] private OrmManager $orm;

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->warning('Deprecated: use ai:review-graph:capabilities instead.');

        $storage = $this->createStorage();
        $query = new GraphQueryService($storage);
        $enricher = new CommandCapabilityEnricher();
        $projection = new CapabilityProjection($query, $storage, $enricher);

        $manifest = $projection->build(category: 'all');

        if ($input->getOption('json')) {
            $data = $manifest->toLegacyFormat();
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        foreach ($manifest->commands as $cmd) {
            $io->text('<info>' . $cmd->name . '</info> — ' . $cmd->summary);
        }

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
