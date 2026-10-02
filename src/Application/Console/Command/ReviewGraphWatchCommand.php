<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
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
        $this->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Polling interval in seconds', '2');
        $this->addOption('full-on-start', null, InputOption::VALUE_NONE, 'Run a full build before watching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rawInterval = $input->getOption('interval');
        // 0 or a negative interval made the loop spin with no sleep at all.
        if (!is_numeric($rawInterval) || (int) $rawInterval < 1 || (string) (int) $rawInterval !== trim((string) $rawInterval)) {
            $io->error('--interval must be a whole number of seconds, at least 1.');
            return self::FAILURE;
        }
        $interval = (int) $rawInterval;
        $fullOnStart = $input->getOption('full-on-start');

        $storage = $this->createStorage();
        $engine = $this->createEngine($storage);

        if ($fullOnStart) {
            $io->text('Running full build...');
            $result = $engine->fullBuild($this->getProjectRoot());
            $io->text(sprintf('Full build: %d files, +%d/-%d nodes, +%d/-%d edges (%dms)',
                $result->filesScanned, $result->nodesAdded, $result->nodesRemoved,
                $result->edgesAdded, $result->edgesRemoved, $result->duration));
        }

        $io->text('Watching for file changes (Ctrl+C to stop)...');
        $io->text('Polling interval: ' . $interval . 's');
        $io->newLine();

        $running = true;
        $signals = $this->stopOnSignals($running);

        while ($running) {
            $signals && pcntl_signal_dispatch();
            try {
                $result = $engine->update($this->getProjectRoot());
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
                $signals && pcntl_signal_dispatch();
            }
        }

        $io->text('Watch stopped.');
        return self::SUCCESS;
    }

    /**
     * Ctrl+C and SIGTERM stop the loop after the current update, so a refresh
     * is never cut in half. This called pcntl_signal() unconditionally — a
     * fatal on the app image, which has no pcntl — and its handlers were arrow
     * functions, which capture $running BY VALUE: with pcntl, the signal was
     * swallowed and only SIGKILL stopped the watch. Without pcntl the default
     * signal handling applies: the process ends, the update's transaction with it.
     *
     * @internal public for the test of the by-reference capture
     */
    public function stopOnSignals(bool &$running): bool
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_signal_dispatch')) {
            return false;
        }
        $stop = static function () use (&$running): void {
            $running = false;
        };
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);

        return true;
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
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
