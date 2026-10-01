<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphExport;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Query\ReviewGraphRenderer;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
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
        $this->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: summary, json, dot, markdown, html', 'summary');
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (required for html: a self-contained viewer that opens from file://)');
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

        if (!in_array($format, [...ReviewGraphRenderer::FORMATS, 'html'], true)) {
            $io->error(sprintf('Unknown --format "%s". Expected one of: %s, html.', is_string($format) ? $format : '', implode(', ', ReviewGraphRenderer::FORMATS)));

            return self::FAILURE;
        }

        $storage = $this->createStorage();
        if ($format === 'html') {
            return $this->exportHtml($io, $storage, $input, is_string($focus) ? $focus : null, $depth, is_string($module) ? $module : null, $types);
        }

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

    /**
     * The graph view as one file. The slice is whatever the filters say — a
     * focus with --depth, a --module, --type — or the whole graph; the size is
     * printed because the whole graph of a large project is several megabytes.
     *
     * @param list<string>|null $types
     */
    private function exportHtml(SymfonyStyle $io, GraphStorage $storage, InputInterface $input, ?string $focus, int $depth, ?string $module, ?array $types): int
    {
        $path = $input->getOption('output');
        if (!is_string($path) || $path === '') {
            $io->error('--format=html writes a file: pass --output=<path>.');

            return self::FAILURE;
        }
        if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');

            return self::FAILURE;
        }

        $export = new GraphExport($storage, rtrim($this->getProjectRoot(), '/'));
        try {
            $data = $export->data($focus, $depth, $module, $types);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $title = 'Project graph' . ($focus !== null ? ' · ' . $focus : ($module !== null ? ' · ' . $module : ''));
        try {
            $bytes = $export->write($path, $data, $title);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf(
            'Wrote %s — %d nodes, %d edges, %s. Open it in a browser; it needs no server.',
            $path,
            count($data['nodes']),
            count($data['edges']),
            self::bytes($bytes),
        ));

        return self::SUCCESS;
    }

    private static function bytes(int $n): string
    {
        return $n >= 1048576 ? round($n / 1048576, 1) . ' MB' : round($n / 1024) . ' KB';
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
