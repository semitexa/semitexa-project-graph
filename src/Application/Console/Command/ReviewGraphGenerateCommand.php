<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
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
            ? $engine->fullBuild($this->getProjectRoot())
            : $engine->update($this->getProjectRoot());

        $coverage = (new CoverageReport($storage))->summary();

        if ($input->getOption('json')) {
            // One shape whatever the counts: `gaps` and `exclusions` were [] when empty and {} otherwise.
            foreach (['gaps', 'exclusions'] as $key) {
                if (isset($coverage[$key]) && is_array($coverage[$key])) {
                    $coverage[$key] = (object) $coverage[$key];
                }
            }
            $output->writeln((string) json_encode($result->toArray() + ['coverage' => $coverage], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
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

        $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));

        return self::SUCCESS;
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
