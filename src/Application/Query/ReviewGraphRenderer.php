<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Query;

final class ReviewGraphRenderer
{
    public function render(GraphView $view, string $format, ?string $lastUpdate = null, ?string $schemaVersion = null): string
    {
        return match ($format) {
            'summary'  => $this->renderSummary($view, $lastUpdate, $schemaVersion),
            'json'     => $this->renderJson($view),
            'dot'      => $this->renderDot($view),
            'markdown' => $this->renderMarkdown($view),
            default    => $this->renderSummary($view, $lastUpdate, $schemaVersion),
        };
    }

    private function renderSummary(GraphView $view, ?string $lastUpdate, ?string $schemaVersion): string
    {
        $lines = [];
        $lines[] = 'Review Graph';
        $lines[] = str_repeat('═', 40);

        if ($lastUpdate !== null) {
            $lines[] = 'Last generated: ' . date('Y-m-d H:i:s', (int)$lastUpdate);
        }
        if ($schemaVersion !== null) {
            $lines[] = 'Schema version: ' . $schemaVersion;
        }
        $lines[] = '';

        $lines[] = 'Nodes: ' . number_format($view->totalNodes);
        $typeLines = [];
        foreach ($view->nodeTypeCounts as $type => $count) {
            $typeLines[] = $type . ': ' . $count;
        }
        $lines[] = '  ' . implode('    ', $typeLines);
        $lines[] = '';

        $lines[] = 'Edges: ' . number_format($view->totalEdges);
        $edgeLines = [];
        foreach ($view->edgeTypeCounts as $type => $count) {
            $edgeLines[] = $type . ': ' . $count;
        }
        $lines[] = '  ' . implode('    ', $edgeLines);
        $lines[] = '';

        $lines[] = 'Modules: ' . count($view->moduleCounts);
        $topModules = array_slice($view->moduleCounts, 0, 5, true);
        $moduleStrs = [];
        foreach ($topModules as $mod => $cnt) {
            $moduleStrs[] = $mod . ': ' . $cnt;
        }
        $lines[] = '  Top: ' . implode('    ', $moduleStrs);
        $lines[] = '';
        $lines[] = 'Cross-module edges: ' . $view->crossModuleEdges;
        $lines[] = 'Orphan nodes: ' . $view->orphanNodes;
        $lines[] = 'Placeholder nodes: ' . $view->placeholderNodes;

        return implode("\n", $lines);
    }

    private function renderJson(GraphView $view): string
    {
        return json_encode([
            'nodes'       => array_map(fn($n) => [
                'id'       => $n->id,
                'type'     => $n->type->value,
                'fqcn'     => $n->fqcn,
                'file'     => $n->file,
                'line'     => $n->line,
                'endLine'  => $n->endLine,
                'module'   => $n->module,
                'metadata' => $n->metadata,
            ], $view->nodes),
            'edges'       => array_map(fn($e) => [
                'sourceId' => $e->sourceId,
                'targetId' => $e->targetId,
                'type'     => $e->type->value,
                'metadata' => $e->metadata,
            ], $view->edges),
            'node_types'  => $view->nodeTypeCounts,
            'edge_types'  => $view->edgeTypeCounts,
            'total_nodes' => $view->totalNodes,
            'total_edges' => $view->totalEdges,
            'modules'     => $view->moduleCounts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function renderDot(GraphView $view): string
    {
        $lines = ['digraph review_graph {', '  rankdir=LR;', '  node [shape=box, fontsize=10];', ''];

        foreach ($view->nodes as $node) {
            $label = $this->escapeDot($node->name());
            $color = $this->nodeColor($node->type->value);
            $lines[] = sprintf('  "%s" [label="%s", color="%s"];', $this->escapeDot($node->id), $label, $color);
        }

        $lines[] = '';

        foreach ($view->edges as $edge) {
            $lines[] = sprintf('  "%s" -> "%s" [label="%s"];',
                $this->escapeDot($edge->sourceId),
                $this->escapeDot($edge->targetId),
                $this->escapeDot($edge->type->value),
            );
        }

        $lines[] = '}';
        return implode("\n", $lines);
    }

    private function renderMarkdown(GraphView $view): string
    {
        $lines = ['# Review Graph', ''];
        $lines[] = '**Nodes:** ' . $view->totalNodes . ' | **Edges:** ' . $view->totalEdges;
        $lines[] = '**Cross-module:** ' . $view->crossModuleEdges . ' | **Orphans:** ' . $view->orphanNodes;
        $lines[] = '';

        $lines[] = '## Node Types';
        $lines[] = '| Type | Count |';
        $lines[] = '|------|-------|';
        foreach ($view->nodeTypeCounts as $type => $count) {
            $lines[] = '| ' . $type . ' | ' . $count . ' |';
        }
        $lines[] = '';

        $lines[] = '## Modules';
        $lines[] = '| Module | Nodes |';
        $lines[] = '|--------|-------|';
        foreach ($view->moduleCounts as $mod => $cnt) {
            $lines[] = '| ' . $mod . ' | ' . $cnt . ' |';
        }

        return implode("\n", $lines);
    }

    private function nodeColor(string $type): string
    {
        return match ($type) {
            'payload'        => '#4CAF50',
            'handler'        => '#2196F3',
            'service'        => '#FF9800',
            'entity'         => '#9C27B0',
            'resource'       => '#00BCD4',
            'event_listener' => '#E91E63',
            'event'          => '#F44336',
            'command'        => '#795548',
            'component'      => '#607D8B',
            default          => '#9E9E9E',
        };
    }

    private function escapeDot(string $s): string
    {
        return str_replace(
            ['"', '\\', '<', '>', '&', '{', '}', '|', "\n"],
            ['\\"', '\\\\', '\\<', '\\>', '\\&', '\\{', '\\}', '\\|', '\\n'],
            $s,
        );
    }
}
