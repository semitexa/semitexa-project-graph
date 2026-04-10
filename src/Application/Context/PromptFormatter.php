<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Context;

final class PromptFormatter
{
    public function formatForReview(ContextPackage $context): string
    {
        $lines = [
            'You are a senior PHP code reviewer. Review the following code changes and their potential impact on the codebase.',
            '',
            '## Changed Files',
            '',
        ];

        foreach ($context->changed as $changed) {
            $lines[] = '- ' . $changed;
        }

        $lines[] = '';
        $lines[] = '## Affected Code (by relevance)';
        $lines[] = '';

        foreach ($context->nodes as $ctxNode) {
            $lines[] = '### ' . $ctxNode->node->fqcn . ' (score: ' . round($ctxNode->score, 2) . ')';
            $lines[] = 'Type: ' . $ctxNode->node->type->value;
            $lines[] = 'File: ' . $ctxNode->node->file . ':' . $ctxNode->node->line;
            $lines[] = '';
            if ($ctxNode->snippet !== null) {
                $lines[] = '```php';
                $lines[] = $ctxNode->snippet;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        $lines[] = '## Instructions';
        $lines[] = '';
        $lines[] = '1. Review each changed file for correctness, performance, and security issues';
        $lines[] = '2. Consider the impact on affected nodes listed above';
        $lines[] = '3. Flag any breaking changes to public interfaces';
        $lines[] = '4. Suggest improvements with specific code examples';
        $lines[] = '5. Check for missing error handling, edge cases, and type safety';

        return implode("\n", $lines);
    }

    public function formatForRefactor(ContextPackage $context, string $goal): string
    {
        $lines = [
            'You are a senior PHP architect. Help refactor the following code to achieve: ' . $goal,
            '',
            '## Current State',
            '',
        ];

        foreach ($context->nodes as $ctxNode) {
            $lines[] = '### ' . $ctxNode->node->fqcn;
            $lines[] = 'Type: ' . $ctxNode->node->type->value;
            $lines[] = 'Relevance score: ' . round($ctxNode->score, 2);
            $lines[] = '';
            if ($ctxNode->snippet !== null) {
                $lines[] = '```php';
                $lines[] = $ctxNode->snippet;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        $lines[] = '## Instructions';
        $lines[] = '';
        $lines[] = '1. Analyze the current architecture based on the code above';
        $lines[] = '2. Propose a refactoring strategy to achieve: ' . $goal;
        $lines[] = '3. Identify dependencies that need to be updated';
        $lines[] = '4. Provide step-by-step migration instructions';
        $lines[] = '5. Highlight any risks or breaking changes';

        return implode("\n", $lines);
    }

    public function formatForTests(ContextPackage $context): string
    {
        $lines = [
            'You are a senior PHP test engineer. Generate comprehensive test coverage for the following code.',
            '',
            '## Code to Test',
            '',
        ];

        foreach ($context->nodes as $ctxNode) {
            $lines[] = '### ' . $ctxNode->node->fqcn;
            $lines[] = 'Type: ' . $ctxNode->node->type->value;
            $lines[] = '';
            if ($ctxNode->snippet !== null) {
                $lines[] = '```php';
                $lines[] = $ctxNode->snippet;
                $lines[] = '```';
                $lines[] = '';
            }
        }

        $lines[] = '## Instructions';
        $lines[] = '';
        $lines[] = '1. Identify test cases for each class/method';
        $lines[] = '2. Cover happy paths, edge cases, and error conditions';
        $lines[] = '3. Include integration tests for cross-component interactions';
        $lines[] = '4. Use PHPUnit conventions';
        $lines[] = '5. Prioritize tests by risk and business impact';

        return implode("\n", $lines);
    }
}
