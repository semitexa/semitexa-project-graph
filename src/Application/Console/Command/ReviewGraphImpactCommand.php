<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\ProjectGraph\Application\Service\Support\RefusesInMachineFormat;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactResult;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactedNode;
use Semitexa\ProjectGraph\Application\Service\Query\NodeResolver;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Analysis\ImpactAnalyzer;
use Semitexa\ProjectGraph\Application\Service\Context\ContextPacker;
use Semitexa\ProjectGraph\Application\Service\Context\PromptFormatter;
use Semitexa\ProjectGraph\Application\Service\Context\RelevanceScorer;
use Semitexa\ProjectGraph\Application\Service\Context\SourceSnippetLoader;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Support\AutoRefreshesProjectGraph;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
    use RefusesInMachineFormat;

    use AutoRefreshesProjectGraph;
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
        $this->addArgument('target', InputArgument::REQUIRED, 'Class FQCN, file path, or node ID');
        $this->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Maximum traversal depth', '5');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
        $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Skip the incremental staleness refresh before analysing');
        $this->addOption('ndjson', null, InputOption::VALUE_NONE, 'Output as NDJSON');
        $this->addOption('context', null, InputOption::VALUE_NONE, 'Include source snippets in context package');
        $this->addOption('prompt', 'p', InputOption::VALUE_REQUIRED, 'Generate LLM prompt: review, refactor, test');
        $this->addOption('module', 'm', InputOption::VALUE_REQUIRED, 'Scope to a specific module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = $input->getArgument('target');
        $depthOption = $input->getOption('depth');
        $json = (bool) $input->getOption('json');
        $ndjson = (bool) $input->getOption('ndjson');

        if (!is_string($target) || $target === '') {
            return $this->refuse($output, $io, 'Target must be a non-empty string.', $json || $ndjson);
        }

        if ($json && $ndjson) {
            return $this->refuse($output, $io, 'Use either --json or --ndjson, not both.', $json || $ndjson);
        }

        $depth = $this->parseDepth($depthOption, $io);
        if ($depth === null) {
            return self::FAILURE;
        }

        // A prompt is made from the context package, so asking for one implies
        // it; an unknown prompt kind used to fall back to "review" in silence.
        $prompt = $input->getOption('prompt');
        if ($prompt !== null && !in_array($prompt, self::PROMPTS, true)) {
            return $this->refuse($output, $io, sprintf('--prompt accepts %s; got "%s".', implode(', ', self::PROMPTS), (string) $prompt), $json || $ndjson);
        }
        $withContext = (bool) $input->getOption('context') || $prompt !== null;
        if ($withContext && $ndjson) {
            // The context used to vanish from --ndjson without a word.
            return $this->refuse($output, $io, '--context/--prompt have no NDJSON form; use --json (the context is a field) or text.', true);
        }
        $module = $input->getOption('module');
        $module = is_string($module) && $module !== '' ? $module : null;

        $storage = $this->createStorage();
        $this->refreshProjectGraph(
            $storage,
            $io,
            (bool) $input->getOption('no-refresh'),
            (bool) $input->getOption('json') || (bool) $input->getOption('ndjson'),
        );
        $totalNodes = (int)($storage->getMeta('total_nodes') ?: 0);
        if ($totalNodes === 0) {
            $io->warning('Graph is empty. Run ai:review-graph:generate first.');
            return self::FAILURE;
        }

        if ($module !== null && !in_array($module, $storage->nodes->distinctModules(), true)) {
            return $this->refuse($output, $io, sprintf('No module "%s" in the graph.', $module), $json || $ndjson);
        }

        $analyzer = new ImpactAnalyzer($storage);

        $resolver = new NodeResolver($storage, $this->getProjectRoot());
        $node = $resolver->resolve($target);
        if ($node === null) {
            // It used to take the first LIKE match in row order and analyse
            // that — another class, with exit 0 and no word about it.
            return $this->refuse($output, $io, $resolver->notFound($target), $json || $ndjson);
        }
        $nodeId = $node->getId();

        $impact = $analyzer->analyze([$nodeId], $depth);
        if ($module !== null) {
            // --module was declared and never read.
            $impact = new ImpactResult(
                $impact->changed,
                array_filter($impact->impacted, static fn (ImpactedNode $n): bool => $n->node->getModule() === $module),
            );
        }
        $coverage = (new CoverageReport($storage))->forNodes([$nodeId]);

        if ($json) {
            $extra = ['coverage' => $coverage];
            if ($withContext) {
                // --context with --json used to drop the context.
                $extra['context'] = $this->contextText($impact, $prompt);
            }
            $payload = json_encode($this->buildJsonPayload($impact) + $extra, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payload === false) {
                return $this->refuse($output, $io, 'Failed to encode JSON payload.', $json || $ndjson);
            }

            $output->writeln($payload, OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        }

        if ($ndjson) {
            return $this->emitNdjson($io, $output, $impact, $coverage);
        }

        if ($impact->totalImpacted() === 0) {
            // "Nothing depends on this" is only proof when nothing that could
            // hide a dependent was left unread.
            $io->text($coverage['absence_is_proof']
                ? 'No downstream impact detected for ' . $target
                : 'No downstream impact found for ' . $target . ' — not proof: the graph did not see everything.');
            $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));
            return self::SUCCESS;
        }

        $this->renderImpact($impact, $io);
        if (!$coverage['absence_is_proof']) {
            $io->text(CoverageReport::describe($coverage, $this->getProjectRoot()));
        }

        if ($withContext) {
            $io->section($prompt !== null ? 'LLM Prompt' : 'Context Package');
            // Raw: source code is full of <tags> the console would eat.
            $output->writeln($this->contextText($impact, $prompt), OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private const PROMPTS = ['review', 'refactor', 'test'];

    private function contextText(ImpactResult $impact, ?string $prompt): string
    {
        $context = (new ContextPacker(new RelevanceScorer(), new SourceSnippetLoader()))->pack($impact);
        if ($prompt === null) {
            return $context->toMarkdown();
        }
        $formatter = new PromptFormatter();

        return match ($prompt) {
            'refactor' => $formatter->formatForRefactor($context, 'improve architecture'),
            'test'     => $formatter->formatForTests($context),
            default    => $formatter->formatForReview($context),
        };
    }

    /**
     * Emit NDJSON: one object per line. First line is a summary an agent can
     * short-circuit on; subsequent lines tag each impacted node with a `kind`
     * discriminator (`direct` for immediate downstream nodes, `transitive`
     * otherwise) and an `action` the agent should take. The payload is kept
     * mostly flat to make downstream grep/filter workflows practical, though
     * the summary line includes a `modules` collection.
     */
    /** @param array<string, mixed> $coverage */
    private function emitNdjson(SymfonyStyle $io, OutputInterface $output, ImpactResult $impact, array $coverage): int
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
            'coverage'  => $coverage,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($summary === false) {
            return $this->refuse($output, $io, 'Failed to encode NDJSON summary payload.', true);
        }

        $output->writeln($summary, OutputInterface::OUTPUT_RAW);

        foreach ($impact->impacted as $impacted) {
            $node = $impacted->node;
            $isDirect = $impacted->distance === 1;
            $line = json_encode([
                'kind'     => $isDirect ? 'direct' : 'transitive',
                'fqcn'     => $node->getFqcn(),
                'type'     => $node->getType()->value,
                'module'   => $node->getModule(),
                'distance' => $impacted->distance,
                'action'   => $isDirect ? 'edit' : 'review',
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                return $this->refuse($output, $io, 'Failed to encode NDJSON impacted-node payload.', true);
            }

            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    private function parseDepth(mixed $depthOption, SymfonyStyle $io): ?int
    {
        if (!is_scalar($depthOption)) {
            $io->error('Depth must be a scalar integer value.');

            return null;
        }

        $depth = trim((string) $depthOption);
        if ($depth === '' || !preg_match('/^[1-9][0-9]*$/', $depth)) {
            $io->error('Depth must be an integer greater than or equal to 1.');

            return null;
        }

        return (int) $depth;
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
                'id'       => $n->node->getId(),
                'fqcn'     => $n->node->getFqcn(),
                'type'     => $n->node->getType()->value,
                'module'   => $n->node->getModule(),
                'distance' => $n->distance,
            ], array_keys($impact->impacted), $impact->impacted),
            'total'     => $impact->totalImpacted(),
            'max_depth' => $impact->maxDepth(),
            'modules'   => $impact->getModulesAffected(),
        ];
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
                $io->text('<info>' . OutputFormatter::escape($impacted->node->getFqcn()) . '</info> (' . $impacted->node->getType()->value . ', ' . $impacted->node->getModule() . ')');
            }
        }
    }

    private function createStorage(): GraphStorage
    {
        return $this->createProjectGraphStorage($this->connections);
    }
}
