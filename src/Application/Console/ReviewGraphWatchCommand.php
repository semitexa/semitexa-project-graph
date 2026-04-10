<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
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
    name: 'ai:review-graph:watch',
    description: 'Watch for file changes and incrementally update the graph',
)]
final class ReviewGraphWatchCommand extends BaseCommand
{
    #[InjectAsReadonly] private OrmManager $orm;
    #[\Semitexa\Core\Attribute\Config(env: 'PROJECT_ROOT')] private string $projectRoot;

    protected function configure(): void
    {
        $this->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Polling interval in seconds', '2');
        $this->addOption('full-on-start', null, InputOption::VALUE_NONE, 'Run a full build before watching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $interval = (int) $input->getOption('interval');
        $fullOnStart = $input->getOption('full-on-start');

        $storage = $this->createStorage();
        $engine = $this->createEngine($storage);

        if ($fullOnStart) {
            $io->text('Running full build...');
            $result = $engine->fullBuild($this->projectRoot);
            $io->text(sprintf('Full build: %d files, +%d/-%d nodes, +%d/-%d edges (%dms)',
                $result->filesScanned, $result->nodesAdded, $result->nodesRemoved,
                $result->edgesAdded, $result->edgesRemoved, $result->duration));
        }

        $io->text('Watching for file changes (Ctrl+C to stop)...');
        $io->text('Polling interval: ' . $interval . 's');
        $io->newLine();

        $running = true;
        pcntl_signal(SIGINT, fn() => $running = false);
        pcntl_signal(SIGTERM, fn() => $running = false);

        while ($running) {
            pcntl_signal_dispatch();
            try {
                $result = $engine->update($this->projectRoot);
                if (!$result->isNoChanges()) {
                    $io->text('[' . date('H:i:s') . '] Updated: ' . $result->filesScanned . ' files, +' . $result->nodesAdded . '/-' . $result->nodesRemoved . ' nodes');
                }
            } catch (\Throwable $e) {
                $io->error('Watch error: ' . $e->getMessage());
            }

            $slept = 0;
            while ($running && $slept < $interval) {
                sleep(1);
                $slept++;
                pcntl_signal_dispatch();
            }
        }

        $io->text('Watch stopped.');
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
