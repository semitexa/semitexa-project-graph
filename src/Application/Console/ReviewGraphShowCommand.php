<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Query\ReviewGraphRenderer;
use Semitexa\ProjectGraph\Application\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:show',
    description: 'Display or export the review graph',
)]
final class ReviewGraphShowCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: summary, json, dot, markdown', 'summary');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Filter by module');
        $this->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by node type (comma-separated)');
        $this->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Traversal depth from focus node', '3');
        $this->addArgument('focus', InputArgument::OPTIONAL, 'Focus node: FQCN, file path, or module name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format');
        $module = $input->getOption('module');
        $types  = $input->getOption('type') ? explode(',', $input->getOption('type')) : null;
        $focus  = $input->getArgument('focus');
        $depth  = (int) $input->getOption('depth');

        $storage = $this->createStorage();
        $query   = new GraphQueryService($storage);
        $renderer = new ReviewGraphRenderer();

        $view = $query->buildView(
            module: $module,
            types:  $types,
            focus:  $focus,
            depth:  $depth,
        );

        $lastUpdate = $storage->getMeta('last_update');
        $schemaVersion = $storage->getMeta('schema_version') ?? '1';

        $output->writeln($renderer->render($view, $format, $lastUpdate, $schemaVersion));

        return self::SUCCESS;
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
