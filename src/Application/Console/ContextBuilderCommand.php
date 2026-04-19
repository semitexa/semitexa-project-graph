<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Console;

use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Intelligence\IntelligenceLayer;
use Semitexa\ProjectGraph\Application\Query\Direction;
use Semitexa\ProjectGraph\Application\Query\GraphQueryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:review-graph:context', description: 'Build relevant context for a task')]
final class ContextBuilderCommand extends Command
{
    public function __construct(
        private readonly GraphQueryService $query,
    ) {
        parent::__construct();
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
        $intelligence = new IntelligenceLayer($this->query);
        $task = $input->getArgument('task');
        $format = $input->getOption('format') ?? 'text';
        $depth = (int) ($input->getOption('depth') ?? 2);
        $module = $input->getOption('module');

        $context = $this->buildContext($task, $depth, $module, $intelligence);

        if ($format === 'json') {
            $output->writeln(json_encode($context, JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<comment>=== Context for: ' . $task . ' ===</comment>');
        $output->writeln('');

        if ($context['matched_nodes'] !== []) {
            $output->writeln('<info>Matched Components:</info>');
            foreach ($context['matched_nodes'] as $node) {
                $output->writeln("  → {$node['fqcn']} ({$node['type']})");
                if (!empty($node['file'])) {
                    $output->writeln("    file: {$node['file']}");
                }
                if (!empty($node['intent'])) {
                    $output->writeln("    purpose: {$node['intent']}");
                }
            }
            $output->writeln('');
        }

        if ($context['related_flows'] !== []) {
            $output->writeln('<info>Related Execution Flows:</info>');
            foreach ($context['related_flows'] as $flow) {
                $output->writeln("  → {$flow['name']}");
                $output->writeln("    entry: {$flow['entry_point']}");
                foreach ($flow['steps'] as $step) {
                    $output->writeln("    {$step['order']}. {$step['node']} ({$step['role']})");
                }
            }
            $output->writeln('');
        }

        if ($context['related_events'] !== []) {
            $output->writeln('<info>Related Events:</info>');
            foreach ($context['related_events'] as $event) {
                $output->writeln("  → {$event['class']}");
                if ($event['nats_subject'] !== null) {
                    $output->writeln("    subject: {$event['nats_subject']}");
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
                $output->writeln("  → {$dep['target']} ({$dep['type']})");
            }
            $output->writeln('');
        }

        if ($context['dependents'] !== []) {
            $output->writeln('<info>Components That Depend On This:</info>');
            foreach ($context['dependents'] as $dep) {
                $output->writeln("  ← {$dep['source']} ({$dep['type']})");
            }
            $output->writeln('');
        }

        if ($context['hotspots'] !== []) {
            $output->writeln('<comment>⚠ Hotspots (high risk):</comment>');
            foreach ($context['hotspots'] as $h) {
                $output->writeln("  ⚠ {$h['node_id']} (risk: {$h['risk_score']})");
                if (!empty($h['recommendation'])) {
                    $output->writeln("    → {$h['recommendation']}");
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
            $results = $this->query->search($keyword);
            foreach ($results as $node) {
                if ($module !== null && $node->module !== $module) {
                    continue;
                }
                if (!isset($matchedIds[$node->id])) {
                    $matchedIds[$node->id] = true;
                    $intent = $intelligence->getIntent($node->id);
                    $context['matched_nodes'][] = [
                        'id' => $node->id,
                        'fqcn' => $node->fqcn,
                        'type' => $node->type->value,
                        'file' => $node->file,
                        'intent' => $intent?->purpose,
                    ];

                    if ($depth >= 2) {
                        $this->addDependencies($node->id, $context, $depth);
                        $this->addDependents($node->id, $context, $depth);
                    }
                }
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

    private function addDependencies(string $nodeId, array &$context, int $depth): void
    {
        $edges = $this->query->getEdges($nodeId, direction: Direction::Outgoing);
        foreach ($edges as $edge) {
            if (in_array($edge->type, [EdgeType::Calls, EdgeType::Instantiates, EdgeType::InjectsReadonly, EdgeType::InjectsMutable], true)) {
                $context['dependencies'][] = [
                    'target' => $edge->targetId,
                    'type' => $edge->type->value,
                ];
            }
        }
    }

    private function addDependents(string $nodeId, array &$context, int $depth): void
    {
        $edges = $this->query->getEdges($nodeId, direction: Direction::Incoming);
        foreach ($edges as $edge) {
            if (in_array($edge->type, [EdgeType::Calls, EdgeType::Instantiates, EdgeType::InjectsReadonly, EdgeType::InjectsMutable], true)) {
                $context['dependents'][] = [
                    'source' => $edge->sourceId,
                    'type' => $edge->type->value,
                ];
            }
        }
    }
}
