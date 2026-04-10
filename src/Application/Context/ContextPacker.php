<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Context;

use Semitexa\ProjectGraph\Application\Analysis\ImpactResult;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class ContextPacker
{
    public function __construct(
        private readonly RelevanceScorer $scorer,
        private readonly SourceSnippetLoader $snippetLoader,
    ) {}

    public function pack(ImpactResult $impact, int $maxNodes = 20, int $maxTokens = 8000): ContextPackage
    {
        $ranked = $this->scorer->rank($impact->impacted);
        $nodes = [];
        $edges = [];
        $tokens = 0;

        foreach ($ranked as $entry) {
            if (count($nodes) >= $maxNodes) {
                break;
            }
            if ($tokens >= $maxTokens) {
                break;
            }

            /** @var Node $node */
            $node = $entry['node'];
            $snippet = $this->snippetLoader->loadSnippet($node);
            $snippetTokens = $snippet !== null ? (int)(strlen($snippet) / 4) : 0;

            if ($tokens + $snippetTokens > $maxTokens && count($nodes) > 0) {
                break;
            }

            $nodes[] = new ContextNode(
                node:    $node,
                score:   $entry['score'],
                snippet: $snippet,
            );
            $tokens += $snippetTokens + 50;
        }

        foreach ($impact->impacted as $impacted) {
            foreach ($impacted->paths as $path) {
                foreach ($path as $edge) {
                    $edges[] = $edge;
                }
            }
        }

        return new ContextPackage(
            nodes:       $nodes,
            edges:       $edges,
            totalTokens: $tokens,
            changed:     $impact->changed,
        );
    }
}

final readonly class ContextPackage
{
    public function __construct(
        /** @var list<ContextNode> */
        public array $nodes,
        /** @var list<Edge> */
        public array $edges,
        public int   $totalTokens,
        /** @var list<string> */
        public array $changed,
    ) {}

    public function toMarkdown(): string
    {
        $lines = ['# Context Package', ''];
        $lines[] = '**Changed:** ' . implode(', ', $this->changed);
        $lines[] = '**Nodes:** ' . count($this->nodes);
        $lines[] = '**Edges:** ' . count($this->edges);
        $lines[] = '**Estimated tokens:** ' . $this->totalTokens;
        $lines[] = '';

        foreach ($this->nodes as $ctxNode) {
            $lines[] = '## ' . $ctxNode->node->fqcn;
            $lines[] = '**Type:** ' . $ctxNode->node->type->value;
            $lines[] = '**Score:** ' . round($ctxNode->score, 2);
            $lines[] = '**File:** ' . $ctxNode->node->file . ':' . $ctxNode->node->line;
            $lines[] = '';
            if ($ctxNode->snippet !== null) {
                $lines[] = '```php';
                $lines[] = $ctxNode->snippet;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }
}

final readonly class ContextNode
{
    public function __construct(
        public Node   $node,
        public float  $score,
        public ?string $snippet,
    ) {}
}
