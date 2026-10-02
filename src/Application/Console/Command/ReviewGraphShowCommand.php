<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\ProjectGraph\Application\Service\Support\RefusesInMachineFormat;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\ExportLocation;
use Semitexa\ProjectGraph\Application\Service\Query\GraphExport;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\NodeResolver;
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
    use RefusesInMachineFormat;

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
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'html only: file to write, under var/ (default var/evidence/inbox/graph-<slice>.html) — a self-contained viewer that opens from file://');
        $this->addOption('allow-anywhere', null, InputOption::VALUE_NONE, 'html only: let --output point outside var/ (the file maps the whole codebase)');
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
        $types  = $input->getOption('type') ? array_values(array_filter(array_map('trim', explode(',', $input->getOption('type'))))) : null;
        $focus  = $input->getArgument('focus');
        $rawDepth = (string) $input->getOption('depth');
        if (!ctype_digit($rawDepth) || (int) $rawDepth < 1) {
            return $this->refuse($output, $io, '--depth must be a whole number, at least 1.', $format === 'json');
        }
        $depth  = (int) $rawDepth;

        if (!in_array($format, [...ReviewGraphRenderer::FORMATS, 'html'], true)) {
            return $this->refuse($output, $io, sprintf('Unknown --format "%s". Expected one of: %s, html.', is_string($format) ? $format : '', implode(', ', ReviewGraphRenderer::FORMATS)), $format === 'json');
        }

        $storage = $this->createStorage();

        // An unknown filter used to produce an empty graph with exit 0 (and an
        // empty HTML file with [OK]); a typo is an error.
        foreach ($types ?? [] as $type) {
            if (NodeType::tryFrom($type) === null) {
                return $this->refuse($output, $io, sprintf('Unknown --type "%s". Known: %s.', $type, implode(', ', array_map(static fn (NodeType $t): string => $t->value, NodeType::cases()))), $format === 'json');
            }
        }
        if (is_string($module) && $module !== '' && !in_array($module, $storage->nodes->distinctModules(), true)) {
            $known = $storage->nodes->distinctModules();
            sort($known);
            return $this->refuse($output, $io, sprintf('No module "%s" in the graph. Known: %s.', $module, implode(', ', $known)), $format === 'json');
        }
        if ($types === []) {
            return $this->refuse($output, $io, '--type is empty: name one or more node types, comma-separated.', $format === 'json');
        }
        // A bare module name is the documented module focus, even when a class
        // shares it: `show PlatformUser` walked the class (round 2).
        if (is_string($focus) && $focus !== '' && !str_contains($focus, '\\') && !str_contains($focus, ':')
            && in_array($focus, $storage->nodes->distinctModules(), true)
        ) {
            $module = $focus;
            $focus = null;
        }
        if (is_string($focus) && $focus !== '') {
            $resolver = new NodeResolver($storage, $this->getProjectRoot());
            $node = $resolver->resolve($focus);
            if ($node === null && !in_array($focus, $storage->nodes->distinctModules(), true)) {
                return $this->refuse($output, $io, $resolver->notFound($focus), $format === 'json');
            }
            if ($node === null) {
                // The documented "module name" focus.
                $module = $focus;
                $focus = null;
            } else {
                $focus = $node->getId();
            }
        }

        if ($format === 'html') {
            return $this->exportHtml($io, $storage, $input, is_string($focus) ? $focus : null, $depth, is_string($module) ? $module : null, $types);
        }

        if (($focus === null || $focus === '') && ($module === null || $module === '') && $types === null && in_array($format, ['json', 'dot'], true)) {
            // The whole graph as one JSON or DOT document is tens of megabytes
            // and ran out of memory; the counts need none of it.
            return $this->refuse($output, $io, sprintf('The whole graph does not fit a %s document here. Narrow it with a focus, --module or --type, or write the browsable file with --format=html --output=<path>.', $format), $format === 'json');
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

        // Raw: a class name or path holding <tag> is data, not console markup.
        $output->writeln($renderer->render($view, $format, $lastUpdate, $schemaVersion), OutputInterface::OUTPUT_RAW);

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
        $root = rtrim($this->getProjectRoot(), '/');
        $requested = $input->getOption('output');
        try {
            $path = ExportLocation::resolve($root, is_string($requested) ? $requested : null, $focus ?? $module ?? 'whole', (bool) $input->getOption('allow-anywhere'));
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($io, $io, $e->getMessage(), false);
        }
        if ((int) ($storage->getMeta('total_nodes') ?: 0) === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');

            return self::FAILURE;
        }

        $export = new GraphExport($storage, $root);
        try {
            $data = $export->data($focus, $depth, $module, $types);
        } catch (\InvalidArgumentException $e) {
            return $this->refuse($io, $io, $e->getMessage(), false);
        }

        $title = 'Project graph' . ($focus !== null ? ' · ' . $focus : ($module !== null ? ' · ' . $module : ''));
        try {
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('Could not create ' . dirname($path));
            }
            $bytes = $export->write($path, $data, $title);
            $hint = ExportLocation::hintFor($root, $path, $focus ?? $module ?? 'whole');
        } catch (\RuntimeException $e) {
            return $this->refuse($io, $io, $e->getMessage(), false);
        }

        $io->success(sprintf(
            'Wrote %s — %d nodes, %d edges, %s. Open it in a browser; it needs no server. It maps this codebase: keep it out of public PRs, issues and uploads unless that is meant.%s',
            str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path,
            count($data['nodes']),
            count($data['edges']),
            self::bytes($bytes),
            $hint !== null ? ' The next ai:evidence command records it as private evidence (expires in ' . ExportLocation::TTL_DAYS . ' days) and moves it into var/evidence/.' : '',
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
