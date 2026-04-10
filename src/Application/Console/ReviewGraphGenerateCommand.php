<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Scanner\IgnorePatternLoader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:generate',
    description: 'Build or update the review graph from the codebase',
)]
final class ReviewGraphGenerateCommand extends BaseCommand
{
    #[InjectAsReadonly]
    private OrmManager $orm;

    #[Config(env: 'PROJECT_ROOT')]
    private string $projectRoot;

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Force a full rebuild');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $full = $input->getOption('full');

        $storage = $this->createStorage();
        $engine = $this->createEngine($storage);

        $result = $full
            ? $engine->fullBuild($this->projectRoot)
            : $engine->update($this->projectRoot);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        if ($result->isNoChanges()) {
            $io->text('Graph is up to date. No changes detected.');
            return self::SUCCESS;
        }

        $label = $full ? 'Full build' : 'Incremental update';
        $io->text(sprintf(
            '%s complete. %d files scanned, +%d/-%d nodes, +%d/-%d edges. (%dms)',
            $label,
            $result->filesScanned,
            $result->nodesAdded,
            $result->nodesRemoved,
            $result->edgesAdded,
            $result->edgesRemoved,
            $result->duration,
        ));

        if ($result->errors) {
            $io->warning(sprintf('%d files had errors:', count($result->errors)));
            foreach ($result->errors as $err) {
                $io->text('  ' . $err['file'] . ': ' . $err['message']);
            }
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

    private function createEngine(GraphStorage $storage): IncrementalEngine
    {
        $ignoreLoader = new IgnorePatternLoader();
        $scanner = new FileScanner($ignoreLoader);
        $parser = new PhpParserAdapter();
        $extractors = new ExtractorPipeline(ExtractorPipeline::default());
        $builder = new GraphBuilder($storage);

        return new IncrementalEngine($scanner, $parser, $extractors, $builder, $storage);
    }
}
