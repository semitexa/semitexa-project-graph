<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Projection\CapabilityProjection;
use Semitexa\ProjectGraph\Application\Projection\CommandCapabilityEnricher;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:capabilities',
    description: 'Show AI-relevant project capabilities derived from the review graph',
)]
final class ReviewGraphCapabilitiesCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON manifest');
        $this->addOption('markdown', null, InputOption::VALUE_NONE, 'Output as Markdown');
        $this->addOption('category', 'c', InputOption::VALUE_REQUIRED, 'Filter by category: generators, introspection, operations, graph, all', 'all');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Focus on capabilities relevant to a specific module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $category = $input->getOption('category');
        $module = $input->getOption('module');

        $storage = $this->createStorage();
        $query = new GraphQueryService($storage);
        $enricher = new CommandCapabilityEnricher();
        $projection = new CapabilityProjection($query, $storage, $enricher);

        $totalNodes = (int)($storage->getMeta('total_nodes') ?: 0);
        if ($totalNodes === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');
            return self::FAILURE;
        }

        $manifest = $projection->build(category: $category, module: $module);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        if ($input->getOption('markdown')) {
            $output->writeln($projection->renderMarkdown($manifest));
            return self::SUCCESS;
        }

        $this->renderTerminal($manifest, $io);

        return self::SUCCESS;
    }

    private function renderTerminal(object $manifest, SymfonyStyle $io): void
    {
        $title = 'Review Graph Capabilities';
        if (!empty($manifest->projectContext->modules)) {
            $title .= ' — ' . array_key_first($manifest->projectContext->modules);
        }
        $io->title($title);

        $grouped = [];
        foreach ($manifest->commands as $cmd) {
            $grouped[$cmd->kind][] = $cmd;
        }

        $kindLabels = [
            'generator'     => 'Generators',
            'introspection' => 'Introspection',
            'operations'    => 'Operations',
            'graph'         => 'Graph',
            'other'         => 'Other',
        ];

        foreach ($grouped as $kind => $cmds) {
            $label = $kindLabels[$kind] ?? ucfirst($kind);
            $io->section($label . ' (' . count($cmds) . ' commands)');
            foreach ($cmds as $cmd) {
                $io->text('<info>' . $cmd->name . '</info> — ' . $cmd->summary);
            }
        }

        $io->section('Project Context');
        $ctx = $manifest->projectContext;
        $io->definitionList(
            ['Modules'   => count($ctx->modules) . ' (top: ' . implode(', ', array_slice(array_keys($ctx->modules), 0, 3)) . ')'],
            ['Routes'    => array_sum($ctx->routeSummary) . ' (' . implode(', ', array_map(fn($m, $c) => $m . ': ' . $c, array_keys($ctx->routeSummary), $ctx->routeSummary)) . ')'],
            ['Services'  => $ctx->serviceCount . ' (' . $ctx->contractCount . ' contracts)'],
            ['Events'    => $ctx->eventCount . ' (' . $ctx->listenerCount . ' listeners)'],
            ['Entities'  => $ctx->entityCount . ' (' . $ctx->relationCount . ' relations)'],
        );
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
