<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Projection;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Application\Query\QueryInterface;
use Semitexa\ProjectGraph\Application\Graph\NodeType;

final class CapabilityProjection
{
    public function __construct(
        private readonly QueryInterface $query,
        private readonly GraphStorage $storage,
        private readonly CommandCapabilityEnricher $enricher,
    ) {}

    public function build(string $category = 'all', ?string $module = null): CapabilityManifest
    {
        $commandNodes = $this->query->findNodes(type: NodeType::Command->value, module: $module);

        $commands = [];
        foreach ($commandNodes as $node) {
            $capability = $this->enricher->enrich($node);
            if ($category !== 'all' && $capability->kind !== $category) {
                continue;
            }
            $commands[] = $capability;
        }

        $context = $this->buildProjectContext($module);

        return new CapabilityManifest(
            artifact:        'semitexa.review-graph-capabilities/v2',
            generatedAt:     date('c'),
            graphVersion:    $this->storage->getMeta('schema_version'),
            graphLastUpdated: $this->storage->getMeta('last_update'),
            commands:        $commands,
            projectContext:  $context,
        );
    }

    private function buildProjectContext(?string $module): ProjectContext
    {
        return new ProjectContext(
            modules:           $this->query->getModuleSummaries($module),
            routeSummary:      $this->query->getRouteSummary($module),
            serviceCount:      $this->query->countNodes(NodeType::Service->value, $module),
            contractCount:     $this->query->countSatisfiedContracts($module),
            eventCount:        $this->query->countNodes(NodeType::Event->value, $module),
            listenerCount:     $this->query->countNodes(NodeType::EventListener->value, $module),
            entityCount:       $this->query->countNodes(NodeType::Entity->value, $module),
            relationCount:     $this->query->countEdges('has_relation', $module),
            crossModuleEdges:  $module ? null : $this->query->countCrossModuleEdges(),
        );
    }

    public function renderMarkdown(CapabilityManifest $manifest): string
    {
        $lines = ['# Semitexa Capabilities', ''];

        $grouped = [];
        foreach ($manifest->commands as $cmd) {
            $grouped[$cmd->kind][] = $cmd;
        }

        foreach ($grouped as $kind => $cmds) {
            $lines[] = '## ' . ucfirst($kind) . ' (' . count($cmds) . ' commands)';
            $lines[] = '';
            foreach ($cmds as $cmd) {
                $lines[] = '### ' . $cmd->name;
                $lines[] = $cmd->summary;
                if ($cmd->useWhen) {
                    $lines[] = '- **Use when:** ' . $cmd->useWhen;
                }
                if ($cmd->avoidWhen) {
                    $lines[] = '- **Avoid when:** ' . $cmd->avoidWhen;
                }
                if ($cmd->requiredInputs) {
                    $lines[] = '- **Required:** ' . implode(', ', array_map(fn($k) => '--' . $k, array_keys($cmd->requiredInputs)));
                }
                if ($cmd->followUp) {
                    $lines[] = '- **Follow-up:** ' . implode(', ', $cmd->followUp);
                }
                $lines[] = '';
            }
        }

        $lines[] = '## Project Context';
        $lines[] = '';
        $ctx = $manifest->projectContext;
        $lines[] = '- **Modules:** ' . count($ctx->modules) . ' (' . implode(', ', array_keys($ctx->modules)) . ')';
        $lines[] = '- **Routes:** ' . array_sum($ctx->routeSummary) . ' (' . implode(', ', array_map(fn($m, $c) => $m . ': ' . $c, array_keys($ctx->routeSummary), $ctx->routeSummary)) . ')';
        $lines[] = '- **Services:** ' . $ctx->serviceCount . ' with ' . $ctx->contractCount . ' contracts';
        $lines[] = '- **Events:** ' . $ctx->eventCount . ' with ' . $ctx->listenerCount . ' listeners';
        $lines[] = '- **Entities:** ' . $ctx->entityCount . ' with ' . $ctx->relationCount . ' relations';

        return implode("\n", $lines);
    }
}
