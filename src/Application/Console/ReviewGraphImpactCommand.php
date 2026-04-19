<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\ProjectGraph\Application\Analysis\ImpactResult;
use Semitexa\Orm\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Analysis\ImpactAnalyzer;
use Semitexa\ProjectGraph\Application\Context\ContextPacker;
use Semitexa\ProjectGraph\Application\Context\PromptFormatter;
use Semitexa\ProjectGraph\Application\Context\RelevanceScorer;
use Semitexa\ProjectGraph\Application\Context\SourceSnippetLoader;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ai:review-graph:impact',
    description: 'Analyze the impact of changes on the codebase',
)]
final class ReviewGraphImpactCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    public function __construct(
        private readonly ConnectionRegistry $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Class FQCN, file path, or node ID');
        $this->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Maximum traversal depth', '5');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
        $this->addOption('ndjson', null, InputOption::VALUE_NONE, 'Output as NDJSON');
        $this->addOption('context', null, InputOption::VALUE_NONE, 'Include source snippets in context package');
        $this->addOption('prompt', 'p', InputOption::VALUE_REQUIRED, 'Generate LLM prompt: review, refactor, test');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Scope to a specific module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getArgument('target');
        $depth = $input->getOption('depth');

        if (!is_string($target) || $target === '') {
            $io->error('Target must be a non-empty string.');
            return self::FAILURE;
        }

        if (!is_scalar($depth)) {
            $io->error('Depth must be scalar.');
            return self::FAILURE;
        }

        $depth = (int) $depth;

        $storage = $this->createStorage();
        $totalNodes = (int)($storage->getMeta('total_nodes') ?: 0);
        if ($totalNodes === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');
            return self::FAILURE;
        }

        $analyzer = new ImpactAnalyzer($storage);

        $nodeId = $this->resolveNodeId($storage, $target);
        if ($nodeId === null) {
            $io->error('Node not found: ' . $target);
            return self::FAILURE;
        }

        $impact = $analyzer->analyze([$nodeId], $depth);

        if ($impact->totalImpacted() === 0) {
            $io->text('No downstream impact detected for ' . $target);
            return self::SUCCESS;
        }

        if ($input->getOption('json') && $input->getOption('ndjson')) {
            $io->error('Use either --json or --ndjson, not both.');
            return self::FAILURE;
        }

        if ($input->getOption('json')) {
            $payload = json_encode($this->buildJsonPayload($impact), JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                $io->error('Failed to encode JSON payload.');
                return self::FAILURE;
            }

            $output->writeln($payload);
            return self::SUCCESS;
        }

        if ($input->getOption('ndjson')) {
            return $this->emitNdjson($output, $impact);
        }

        $this->renderImpact($impact, $io);

        if ($input->getOption('context')) {
            $scorer = new RelevanceScorer();
            $snippetLoader = new SourceSnippetLoader();
            $packer = new ContextPacker($scorer, $snippetLoader);
            $context = $packer->pack($impact);

            if ($input->getOption('prompt')) {
                $formatter = new PromptFormatter();
                $prompt = match ($input->getOption('prompt')) {
                    'review'    => $formatter->formatForReview($context),
                    'refactor'  => $formatter->formatForRefactor($context, 'improve architecture'),
                    'test'      => $formatter->formatForTests($context),
                    default     => $formatter->formatForReview($context),
                };
                $io->section('LLM Prompt');
                $output->writeln($prompt);
            } else {
                $io->section('Context Package');
                $output->writeln($context->toMarkdown());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Emit NDJSON: one object per line. First line is a summary an agent can
     * short-circuit on; subsequent lines tag each impacted node with a `kind`
     * discriminator (`direct` for immediate downstream nodes, `transitive`
     * otherwise) and an `action` the agent should take. The payload is kept
     * mostly flat to make downstream grep/filter workflows practical, though
     * the summary line includes a `modules` collection.
     */
    private function emitNdjson(OutputInterface $output, ImpactResult $impact): int
    {
        $direct = 0;
        foreach ($impact->impacted as $impacted) {
            if ($impacted->distance === 1) {
                $direct++;
            }
        }

        $total = $impact->totalImpacted();
        $modules = $impact->getModulesAffected();

        $summary = json_encode([
            'kind'      => 'summary',
            'direct'    => $direct,
            'transitive' => max(0, $total - $direct),
            'total'     => $total,
            'max_depth' => $impact->maxDepth(),
            'modules'   => $modules,
        ], JSON_UNESCAPED_SLASHES);
        if ($summary === false) {
            return self::FAILURE;
        }

        $output->writeln($summary);

        foreach ($impact->impacted as $impacted) {
            $node = $impacted->node;
            $isDirect = $impacted->distance === 1;
            $line = json_encode([
                'kind'     => $isDirect ? 'direct' : 'transitive',
                'fqcn'     => $node->fqcn,
                'type'     => $node->type->value,
                'module'   => $node->module,
                'distance' => $impacted->distance,
                'action'   => $isDirect ? 'edit' : 'review',
            ], JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return self::FAILURE;
            }

            $output->writeln($line);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *   changed: list<string>,
     *   impacted: list<array{id: string, fqcn: string, type: string, module: string, distance: int}>,
     *   total: int,
     *   max_depth: int,
     *   modules: array<string, int>
     * }
     */
    private function buildJsonPayload(ImpactResult $impact): array
    {
        return [
            'changed'   => $impact->changed,
            'impacted'  => array_map(fn($id, $n) => [
                'id'       => $n->node->id,
                'fqcn'     => $n->node->fqcn,
                'type'     => $n->node->type->value,
                'module'   => $n->node->module,
                'distance' => $n->distance,
            ], array_keys($impact->impacted), $impact->impacted),
            'total'     => $impact->totalImpacted(),
            'max_depth' => $impact->maxDepth(),
            'modules'   => $impact->getModulesAffected(),
        ];
    }

    private function resolveNodeId(GraphStorage $storage, string $target): ?string
    {
        $node = $storage->nodes->findById($target);
        if ($node !== null) {
            return $node->id;
        }

        $node = $storage->nodes->findByFqcn($target);
        if ($node !== null) {
            return $node->id;
        }

        if (is_file($target)) {
            $nodes = $storage->nodes->findByFile($target);
            if (!empty($nodes)) {
                return $nodes[0]->id;
            }
        }

        $nodes = $storage->nodes->searchFull($target, 1);
        if (!empty($nodes)) {
            return $nodes[0]->id;
        }

        return null;
    }

    private function renderImpact(ImpactResult $impact, SymfonyStyle $io): void
    {
        $io->title('Impact Analysis');
        $io->definitionList(
            ['Changed'    => implode(', ', $impact->changed)],
            ['Impacted'   => $impact->totalImpacted() . ' nodes'],
            ['Max depth'  => $impact->maxDepth()],
        );

        $modules = $impact->getModulesAffected();
        if (!empty($modules)) {
            $io->section('Affected Modules');
            foreach ($modules as $mod => $cnt) {
                $io->text($mod . ': ' . $cnt . ' nodes');
            }
        }

        $byDepth = $impact->getNodesByDepth();
        foreach ($byDepth as $depth => $nodes) {
            $io->section('Depth ' . $depth . ' (' . count($nodes) . ' nodes)');
            foreach ($nodes as $impacted) {
                $io->text('<info>' . $impacted->node->fqcn . '</info> (' . $impacted->node->type->value . ', ' . $impacted->node->module . ')');
            }
        }
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
