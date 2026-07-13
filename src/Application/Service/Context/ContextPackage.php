<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Context;

use Semitexa\ProjectGraph\Domain\Model\Edge;

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
