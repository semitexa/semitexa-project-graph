<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console\Command;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
use Semitexa\ProjectGraph\Application\Service\Query\GraphQueryService;
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

#[AsCommand(name: 'ai:review-graph:context', description: 'Build relevant context for a task')]
final class ContextBuilderCommand extends BaseCommand
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
        $this->addArgument('task', InputArgument::REQUIRED, 'What are you working on? e.g. "adding payment method", "fixing checkout"');
        $this->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format: text, json', 'text');
        $this->addOption('depth', null, InputOption::VALUE_OPTIONAL, 'Context depth (1-3)', '2');
        $this->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Limit to module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intelligence = new IntelligenceLayer($this->query());
        $task = $input->getArgument('task');
        $format = $input->getOption('format') ?? 'text';
        $rawDepth = (string) ($input->getOption('depth') ?? '2');
        $module = $input->getOption('module');
        $module = is_string($module) && $module !== '' ? $module : null;

        // Bad values used to run silently: yaml printed text, -4 and abc ran as 1.
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>--format accepts text or json.</error>');
            return Command::FAILURE;
        }
        if (!in_array($rawDepth, ['1', '2', '3'], true)) {
            $output->writeln('<error>--depth accepts 1, 2 or 3.</error>');
            return Command::FAILURE;
        }
        $depth = (int) $rawDepth;
        if ($module !== null && !in_array($module, $this->query()->knownModules(), true)) {
            $message = sprintf('No module "%s" in the graph.', $module);
            // A caller that asked for JSON parses stdout: the <error> text failed the parse.
            $format === 'json'
                ? $output->writeln((string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW)
                : $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');
            return Command::FAILURE;
        }

        $context = $this->buildContext((string) $task, $depth, $module, $intelligence);

        if ($format === 'json') {
            // Raw: the formatter stripped <tags> out of the task inside the JSON.
            $output->writeln((string) json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        // The task is the person's text: `fix </info> bug` crashed the formatter.
        $output->writeln('<comment>=== Context for: ' . OutputFormatter::escape((string) $task) . ' ===</comment>');
        $output->writeln('');

        if ($context['matched_nodes'] !== []) {
            $output->writeln('<info>Matched Components:</info>');
            foreach ($context['matched_nodes'] as $node) {
                $output->writeln("  → {$node['fqcn']} ({$node['type']})", OutputInterface::OUTPUT_RAW);
                if (!empty($node['file'])) {
                    $output->writeln("    file: {$node['file']}", OutputInterface::OUTPUT_RAW);
                }
                if (!empty($node['intent'])) {
                    $output->writeln("    purpose: {$node['intent']}", OutputInterface::OUTPUT_RAW);
                }
            }
            $output->writeln('');
        }

        if ($context['related_flows'] !== []) {
            $output->writeln('<info>Related Execution Flows:</info>');
            foreach ($context['related_flows'] as $flow) {
                $output->writeln("  → {$flow['name']}", OutputInterface::OUTPUT_RAW);
                $output->writeln("    entry: {$flow['entry_point']}", OutputInterface::OUTPUT_RAW);
                foreach ($flow['steps'] as $step) {
                    $output->writeln("    {$step['order']}. {$step['node']} ({$step['role']})", OutputInterface::OUTPUT_RAW);
                }
            }
            $output->writeln('');
        }

        if ($context['related_events'] !== []) {
            $output->writeln('<info>Related Events:</info>');
            foreach ($context['related_events'] as $event) {
                $output->writeln("  → {$event['class']}", OutputInterface::OUTPUT_RAW);
                if ($event['nats_subject'] !== null) {
                    $output->writeln("    subject: {$event['nats_subject']}", OutputInterface::OUTPUT_RAW);
                }
                if ($event['listeners'] !== []) {
                    $output->writeln("    listeners: " . implode(', ', $event['listeners']));
                }
            }
            $output->writeln('');
        }

        if ($context['dependencies'] !== []) {
            $output->writeln('<info>Direct Dependencies:</info>');
            foreach ($context['dependencies'] as $dep) {
                $output->writeln("  → {$dep['target']} ({$dep['type']})", OutputInterface::OUTPUT_RAW);
            }
            $output->writeln('');
        }

        if ($context['dependents'] !== []) {
            $output->writeln('<info>Components That Depend On This:</info>');
            foreach ($context['dependents'] as $dep) {
                $output->writeln("  ← {$dep['source']} ({$dep['type']})", OutputInterface::OUTPUT_RAW);
            }
            $output->writeln('');
        }

        if ($context['hotspots'] !== []) {
            $output->writeln('<comment>⚠ Hotspots (high risk):</comment>');
            foreach ($context['hotspots'] as $h) {
                $output->writeln("  ⚠ {$h['node_id']} (risk: {$h['risk_score']})", OutputInterface::OUTPUT_RAW);
                if (!empty($h['recommendation'])) {
                    $output->writeln("    → {$h['recommendation']}", OutputInterface::OUTPUT_RAW);
                }
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    private function buildContext(string $task, int $depth, ?string $module, IntelligenceLayer $intelligence): array
    {
        $context = [
            'task' => $task,
            'matched_nodes' => [],
            'related_flows' => [],
            'related_events' => [],
            'dependencies' => [],
            'dependents' => [],
            'hotspots' => [],
        ];

        $keywords = $this->extractKeywords($task);
        $matchedIds = [];

        foreach ($keywords as $keyword) {
            // Scoped in the query: filtering after its limit of 20 lost real matches.
            $results = $this->query()->search($keyword, 20, $module);
            foreach ($results as $node) {
                if (!isset($matchedIds[$node->getId()])) {
                    $matchedIds[$node->getId()] = true;
                    $intent = $intelligence->getIntent($node->getId());
                    $context['matched_nodes'][] = [
                        'id' => $node->getId(),
                        'fqcn' => $node->getFqcn(),
                        'type' => $node->getType()->value,
                        'file' => $node->getFile(),
                        'intent' => $intent?->purpose,
                    ];

                    if ($depth >= 2) {
                        $this->addDependencies($node->getId(), $context, $depth);
                        $this->addDependents($node->getId(), $context, $depth);
                    }
                }
            }
        }

        // The flows and events the matched classes take part in. Both sections
        // were declared, rendered, and never filled (round 2).
        $flowIds = [];
        $eventIds = [];
        foreach (array_keys($matchedIds) as $id) {
            foreach ($this->query()->getEdges($id, EdgeType::ParticipatesInFlow->value, Direction::Outgoing) as $edge) {
                $flowIds[$edge->getTargetId()] = true;
            }
            foreach ([EdgeType::Emits, EdgeType::ListensTo] as $type) {
                foreach ($this->query()->getEdges($id, $type->value, Direction::Outgoing) as $edge) {
                    $eventIds[$edge->getTargetId()] = true;
                }
            }
        }
        foreach (array_slice(array_keys($flowIds), 0, 10) as $flowId) {
            $flow = $intelligence->getExecutionFlow(str_starts_with($flowId, 'flow:') ? substr($flowId, 5) : $flowId);
            if ($flow !== null) {
                $context['related_flows'][] = [
                    'name' => $flow->name,
                    'entry_point' => $flow->entryPoint,
                    'steps' => array_values(array_map(
                        static fn (array $step, int $i): array => ['order' => $step['order'] ?? $i + 1, 'node' => $step['node'] ?? '', 'role' => $step['role'] ?? ''],
                        $flow->steps,
                        array_keys($flow->steps),
                    )),
                ];
            }
        }
        foreach (array_slice(array_keys($eventIds), 0, 10) as $eventId) {
            $lifecycle = $intelligence->getEventLifecycle(str_starts_with($eventId, 'class:') ? substr($eventId, 6) : $eventId);
            if ($lifecycle !== null) {
                $context['related_events'][] = [
                    'class' => $lifecycle->eventClass,
                    'nats_subject' => $lifecycle->natsSubject,
                    // A queued listener is {class, queue}: strval() made it "Array".
                    'listeners' => array_values(array_map('strval', [
                        ...$lifecycle->syncListeners,
                        ...$lifecycle->asyncListeners,
                        ...array_column($lifecycle->queuedListeners, 'class'),
                    ])),
                ];
            }
        }

        $hotspots = $intelligence->getHotspots(10);
        foreach ($hotspots as $h) {
            if (isset($matchedIds[$h->nodeId])) {
                $context['hotspots'][] = [
                    'node_id' => $h->nodeId,
                    'risk_score' => $h->riskScore,
                    'recommendation' => $h->recommendation,
                ];
            }
        }

        return $context;
    }

    private function extractKeywords(string $task): array
    {
        $keywords = [];
        $words = preg_split('/[\s\-_]+/', $task);
        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                $keywords[] = $word;
                $keywords[] = ucfirst(strtolower($word));
            }
        }

        $keywordMap = [
            'payment' => ['Payment', 'Billing', 'Checkout', 'Stripe'],
            'checkout' => ['Checkout', 'Order', 'Cart', 'Payment'],
            'order' => ['Order', 'Checkout', 'Fulfillment'],
            'user' => ['User', 'Auth', 'Profile', 'Account'],
            'auth' => ['Auth', 'Login', 'Permission', 'Capability'],
            'product' => ['Product', 'Inventory', 'Catalog'],
            'inventory' => ['Inventory', 'Stock', 'Product', 'Warehouse'],
            'notification' => ['Notification', 'Email', 'Alert'],
            'email' => ['Email', 'Notification', 'Mail'],
        ];

        foreach ($keywordMap as $trigger => $expansions) {
            foreach ($keywords as $kw) {
                if (stripos($kw, $trigger) !== false) {
                    $keywords = array_merge($keywords, $expansions);
                }
            }
        }

        return array_unique($keywords);
    }

    private const CONTEXT_EDGES = [EdgeType::Calls, EdgeType::Instantiates, EdgeType::InjectsReadonly, EdgeType::InjectsMutable];

    /**
     * $depth 2 is the direct dependencies, 3 adds theirs. --depth used to be
     * read and then ignored: 2, 3 and 99 printed the same thing.
     */
    private function addDependencies(string $nodeId, array &$context, int $depth): void
    {
        $this->walk($nodeId, $context, $depth - 1, Direction::Outgoing, 'dependencies', 'target');
    }

    private function addDependents(string $nodeId, array &$context, int $depth): void
    {
        $this->walk($nodeId, $context, $depth - 1, Direction::Incoming, 'dependents', 'source');
    }

    /** @param array<string, mixed> $context */
    private function walk(string $nodeId, array &$context, int $levels, Direction $direction, string $key, string $field): void
    {
        $seen = [];
        foreach ($context[$key] as $row) {
            $seen[$row[$field] . '|' . $row['type']] = true;
        }
        $frontier = [$nodeId];
        for ($level = 0; $level < $levels && $frontier !== []; $level++) {
            $next = [];
            foreach ($frontier as $id) {
                foreach ($this->query()->getEdges($id, direction: $direction) as $edge) {
                    if (!in_array($edge->getType(), self::CONTEXT_EDGES, true)) {
                        continue;
                    }
                    $other = $direction === Direction::Outgoing ? $edge->getTargetId() : $edge->getSourceId();
                    $k = $other . '|' . $edge->getType()->value;
                    if (isset($seen[$k])) {
                        continue;
                    }
                    $seen[$k] = true;
                    $context[$key][] = [$field => $other, 'type' => $edge->getType()->value];
                    $next[] = $other;
                }
            }
            $frontier = array_values(array_unique($next));
        }
    }
}
