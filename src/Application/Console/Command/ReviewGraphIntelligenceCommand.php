<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Service\Intelligence\NaturalLanguageQueryResolver;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
use Semitexa\ProjectGraph\Application\Service\Query\NodeResolver;
use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Support\UsesProjectGraphConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:intelligence', description: 'Query the project graph intelligence layer')]
final class ReviewGraphIntelligenceCommand extends BaseCommand
{
    use UsesProjectGraphConnection;

    private ?GraphQueryService $queryService = null;

    /**
     * Property injection, not a constructor parameter: #[AsCommand] classes are
     * container-managed, and semitexa.injectionViaConstructor makes that the only DI channel.
     * The rule fires per changed file, so a constructor here is a violation waiting for the
     * next person to edit the file rather than a clean build.
     */
    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    private function query(): GraphQueryService
    {
        return $this->queryService ??= new GraphQueryService(
            $this->createProjectGraphStorage($this->connections),
        );
    }

    protected function configure(): void
    {
        $this->addArgument('query', InputArgument::OPTIONAL, 'Natural language query');
        $this->addOption('hotspots', null, InputOption::VALUE_NONE, 'Show hotspot analysis');
        $this->addOption('doc-gaps', null, InputOption::VALUE_NONE, 'Show documentation gaps');
        $this->addOption('flows', null, InputOption::VALUE_OPTIONAL, 'Show flows for module');
        $this->addOption('event-lifecycle', null, InputOption::VALUE_OPTIONAL, 'Trace event lifecycle');
        $this->addOption('intent', null, InputOption::VALUE_OPTIONAL, 'Show intent for class');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (text|markdown)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query());
        $resolver = new NaturalLanguageQueryResolver($this->query(), $intelligence, $this->resolver());

        if ($input->getOption('hotspots')) {
            $this->showHotspots($intelligence, $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('doc-gaps')) {
            $this->showDocGaps($intelligence, $output);
            return Command::SUCCESS;
        }

        if ($input->getOption('flows') !== null) {
            if (!in_array((string) $input->getOption('flows'), $this->query()->knownModules(), true)) {
                $output->writeln('<error>' . OutputFormatter::escape(sprintf('No module "%s" in the graph.', (string) $input->getOption('flows'))) . '</error>');
                return Command::FAILURE;
            }
            $this->showFlows($intelligence, (string) $input->getOption('flows'), $output);
            return Command::SUCCESS;
        }

        // Not found used to print an error and exit 0.
        if ($input->getOption('event-lifecycle') !== null) {
            return $this->showEventLifecycle($intelligence, (string) $input->getOption('event-lifecycle'), $output) ? Command::SUCCESS : Command::FAILURE;
        }

        if ($input->getOption('intent') !== null) {
            return $this->showIntent($intelligence, (string) $input->getOption('intent'), $output) ? Command::SUCCESS : Command::FAILURE;
        }

        $userQuery = $input->getArgument('query');
        if ($userQuery === null) {
            $output->writeln('<error>Provide a query or use --hotspots, --doc-gaps, --flows, --event-lifecycle, or --intent</error>');
            $output->writeln('');
            $output->writeln('Examples:');
            $output->writeln('  ai:review-graph:intelligence "how does checkout work"');
            $output->writeln('  ai:review-graph:intelligence "what happens when OrderCreated is emitted"');
            $output->writeln('  ai:review-graph:intelligence --hotspots');
            $output->writeln('  ai:review-graph:intelligence --event-lifecycle DemoItemCreated');
            return Command::FAILURE;
        }

        $result = $resolver->resolve($userQuery);
        $this->renderResult($result, $output);

        return Command::SUCCESS;
    }

    private function resolver(): NodeResolver
    {
        return $this->query()->resolver($this->getProjectRoot());
    }

    private function showHotspots(IntelligenceLayer $intelligence, OutputInterface $output): void
    {
        $hotspots = $intelligence->getHotspots(15);

        $output->writeln('<comment>=== Hotspot Analysis ===</comment>');
        $output->writeln('');

        foreach ($hotspots as $h) {
            $level = $h->riskLevel();
            // fg=… , not a bare colour name: Symfony ships <info>/<comment>/<error> and
            // nothing else, so <blue> reached the terminal verbatim.
            $color = match ($level) {
                'CRITICAL' => 'fg=red',
                'HIGH' => 'fg=yellow',
                'MEDIUM' => 'fg=blue',
                default => 'fg=green',
            };
            $output->writeln("<{$color}>[{$level}]</> {$h->nodeId} (score: {$h->riskScore})");
            $output->writeln("  Incoming: {$h->incomingEdges} | Cross-module: {$h->crossModuleDeps} | Complexity: {$h->complexityScore}");
            if ($h->recommendation !== null) {
                $output->writeln("  → {$h->recommendation}");
            }
            $output->writeln('');
        }
    }

    private function showDocGaps(IntelligenceLayer $intelligence, OutputInterface $output): void
    {
        $gaps = $intelligence->getDocGaps();

        $output->writeln('<comment>=== Documentation Gaps ===</comment>');
        $output->writeln('');

        foreach (array_slice($gaps, 0, 20) as $gap) {
            $node = $gap['node'];
            $output->writeln("[{$gap['score']}] {$node->getFqcn()} ({$node->getType()->value})", OutputInterface::OUTPUT_RAW);
        }

        $output->writeln('');
        $output->writeln("Total gaps: " . count($gaps));
    }

    private function showFlows(IntelligenceLayer $intelligence, string $module, OutputInterface $output): void
    {
        $flows = $intelligence->getFlowsForModule($module);

        $output->writeln("<comment>=== Execution Flows: {$module} ===</comment>");
        $output->writeln('');

        foreach ($flows as $flow) {
            $output->writeln("- {$flow['name']} (entry: {$flow['entry_point']})");
        }

        if ($flows === []) {
            $output->writeln('No flows found for this module.');
        }
    }

    /**
     * The help's own example, `--event-lifecycle DemoItemCreated`, failed:
     * only the exact FQCN was understood. The shared resolver also takes a
     * unique short name, any case and a leading backslash.
     */
    private function showEventLifecycle(IntelligenceLayer $intelligence, string $eventClass, OutputInterface $output): bool
    {
        $resolver = $this->resolver();
        $node = $resolver->resolve($eventClass);
        $lifecycle = $node === null ? null : $intelligence->getEventLifecycle($node->getFqcn());

        if ($lifecycle === null) {
            $output->writeln('<error>' . OutputFormatter::escape($node === null ? $resolver->notFound($eventClass) : 'Not an event: ' . $node->getFqcn()) . '</error>');
            return false;
        }

        $output->writeln('<comment>=== Event Lifecycle ===</comment>');
        $output->writeln('');
        $output->write($lifecycle->toMarkdown(), false, OutputInterface::OUTPUT_RAW);

        return true;
    }

    private function showIntent(IntelligenceLayer $intelligence, string $target, OutputInterface $output): bool
    {
        $resolver = $this->resolver();
        $node = $resolver->resolve($target);
        $intent = $node === null ? null : $intelligence->getIntent($node->getId());

        if ($intent === null) {
            $output->writeln('<error>' . OutputFormatter::escape($node === null ? $resolver->notFound($target) : 'No intent inference for: ' . $node->getId()) . '</error>');
            return false;
        }

        $output->writeln('<comment>=== Intent Inference ===</comment>');
        $output->writeln('');
        $output->write($intent->toMarkdown(), false, OutputInterface::OUTPUT_RAW);

        return true;
    }

    private function renderResult(mixed $result, OutputInterface $output): void
    {
        if ($result === null) {
            $output->writeln('<comment>No results found.</comment>');
            return;
        }

        if (is_object($result) && method_exists($result, 'toMarkdown')) {
            $output->write($result->toMarkdown());
            return;
        }

        if (is_array($result)) {
            if ($result === []) {
                $output->writeln('<comment>No results found.</comment>');
                return;
            }
            foreach ($result as $item) {
                $output->writeln(self::line($item), OutputInterface::OUTPUT_RAW);
            }
            return;
        }

        $output->writeln(self::line($result), OutputInterface::OUTPUT_RAW);
    }

    /**
     * One readable line per result. Search results are Node objects, which
     * were print_r()'d — a page of private properties per match.
     */
    private static function line(mixed $item): string
    {
        return match (true) {
            is_object($item) && method_exists($item, 'toMarkdown') => rtrim($item->toMarkdown()) . "\n",
            $item instanceof \Semitexa\ProjectGraph\Application\Service\Query\ImpactResult => sprintf(
                "Changing %s reaches %d node(s):\n%s",
                implode(', ', $item->changed),
                count($item->impacted),
                implode("\n", array_map(
                    static fn (string $id, $n): string => sprintf('- [%d] %s', $n->distance, $id),
                    array_keys($item->impacted),
                    $item->impacted,
                )),
            ),
            $item instanceof \Semitexa\ProjectGraph\Domain\Model\Node => sprintf('- %s (%s%s)', $item->getFqcn() !== '' ? $item->getFqcn() : $item->getId(), $item->getType()->value, $item->getModule() !== '' ? ', ' . $item->getModule() : ''),
            is_array($item) && isset($item['node']) && $item['node'] instanceof \Semitexa\ProjectGraph\Domain\Model\Node => sprintf('- %s (score %s)', $item['node']->getFqcn(), (string) ($item['score'] ?? '')),
            is_array($item) && isset($item['name']) => sprintf('- %s%s', (string) $item['name'], isset($item['entry_point']) ? ' (entry: ' . $item['entry_point'] . ')' : ''),
            is_scalar($item) => (string) $item,
            default => (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };
    }
}
