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
        /** Impacted nodes the budget left out — the reader must know the list is a part. */
        public int   $omitted = 0,
    ) {}

    public function toMarkdown(): string
    {
        $lines = ['# Context Package', ''];
        $lines[] = '**Changed:** ' . implode(', ', $this->changed);
        $lines[] = '**Nodes:** ' . count($this->nodes)
            . ($this->omitted > 0 ? sprintf(' (%d more impacted, left out by the size budget — ranked by relevance)', $this->omitted) : '');
        $lines[] = '**Edges:** ' . count($this->edges);
        $lines[] = '**Estimated tokens:** ' . $this->totalTokens;
        $lines[] = '';

        foreach ($this->nodes as $ctxNode) {
            $lines[] = '## ' . $ctxNode->node->getFqcn();
            $lines[] = '**Type:** ' . $ctxNode->node->getType()->value;
            $lines[] = '**Score:** ' . round($ctxNode->score, 2);
            $lines[] = '**File:** ' . $ctxNode->node->getFile() . ':' . $ctxNode->node->getLine();
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
