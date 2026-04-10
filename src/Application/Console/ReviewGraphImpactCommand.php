<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Orm\OrmManager;
use Semitexa\ProjectGraph\Application\Analysis\ImpactAnalyzer;
use Semitexa\ProjectGraph\Application\Context\ContextPacker;
use Semitexa\ProjectGraph\Application\Context\PromptFormatter;
use Semitexa\ProjectGraph\Application\Context\RelevanceScorer;
use Semitexa\ProjectGraph\Application\Context\SourceSnippetLoader;
use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
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
    #[InjectAsReadonly] private OrmManager $orm;

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Class FQCN, file path, or node ID');
        $this->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Maximum traversal depth', '5');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
        $this->addOption('context', null, InputOption::VALUE_NONE, 'Include source snippets in context package');
        $this->addOption('prompt', 'p', InputOption::VALUE_REQUIRED, 'Generate LLM prompt: review, refactor, test');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Scope to a specific module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getArgument('target');
        $depth = (int) $input->getOption('depth');

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

        if ($input->getOption('json')) {
            $data = [
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
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
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

    private function renderImpact(object $impact, SymfonyStyle $io): void
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
