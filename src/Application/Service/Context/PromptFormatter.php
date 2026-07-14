<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Context;

use Semitexa\ProjectGraph\Application\Prompt\RefactorContextPrompt;
use Semitexa\ProjectGraph\Application\Prompt\ReviewContextPrompt;
use Semitexa\ProjectGraph\Application\Prompt\TestsContextPrompt;
use Semitexa\Prompt\Application\Service\PromptRenderer;

/**
 * Formats a {@see ContextPackage} into an LLM prompt. The static instruction
 * text lives in the prompt catalog ({@see ReviewContextPrompt},
 * {@see RefactorContextPrompt}, {@see TestsContextPrompt}); this formatter
 * assembles the dynamic middle (changed files, affected code, snippets) and
 * binds it as the templates' {{ prompt.body }} section.
 */
final class PromptFormatter
{
    private ?PromptRenderer $renderer = null;

    public function formatForReview(ContextPackage $context): string
    {
        $body = ['## Changed Files', ''];

        foreach ($context->changed as $changed) {
            $body[] = '- ' . $changed;
        }

        $body[] = '';
        $body[] = '## Affected Code (by relevance)';
        $body[] = '';

        foreach ($context->nodes as $ctxNode) {
            $body[] = '### ' . $ctxNode->node->fqcn . ' (score: ' . round($ctxNode->score, 2) . ')';
            $body[] = 'Type: ' . $ctxNode->node->type->value;
            $body[] = 'File: ' . $ctxNode->node->file . ':' . $ctxNode->node->line;
            $body[] = '';
            if ($ctxNode->snippet !== null) {
                $body[] = '```php';
                $body[] = $ctxNode->snippet;
                $body[] = '```';
                $body[] = '';
            }
        }

        return $this->renderer()->render(
            (new ReviewContextPrompt())->withData(implode("\n", $body)),
        )->system;
    }

    public function formatForRefactor(ContextPackage $context, string $goal): string
    {
        $body = ['## Current State', ''];

        foreach ($context->nodes as $ctxNode) {
            $body[] = '### ' . $ctxNode->node->fqcn;
            $body[] = 'Type: ' . $ctxNode->node->type->value;
            $body[] = 'Relevance score: ' . round($ctxNode->score, 2);
            $body[] = '';
            if ($ctxNode->snippet !== null) {
                $body[] = '```php';
                $body[] = $ctxNode->snippet;
                $body[] = '```';
                $body[] = '';
            }
        }

        return $this->renderer()->render(
            (new RefactorContextPrompt())->withData($goal, implode("\n", $body)),
        )->system;
    }

    public function formatForTests(ContextPackage $context): string
    {
        $body = ['## Code to Test', ''];

        foreach ($context->nodes as $ctxNode) {
            $body[] = '### ' . $ctxNode->node->fqcn;
            $body[] = 'Type: ' . $ctxNode->node->type->value;
            $body[] = '';
            if ($ctxNode->snippet !== null) {
                $body[] = '```php';
                $body[] = $ctxNode->snippet;
                $body[] = '```';
                $body[] = '';
            }
        }

        return $this->renderer()->render(
            (new TestsContextPrompt())->withData(implode("\n", $body)),
        )->system;
    }

    private function renderer(): PromptRenderer
    {
        return $this->renderer ??= new PromptRenderer();
    }
}
