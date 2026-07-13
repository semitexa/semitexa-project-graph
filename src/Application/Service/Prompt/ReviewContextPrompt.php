<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * Code-review prompt built from a context package. Migrated out of
 * {@see \Semitexa\ProjectGraph\Application\Service\Context\PromptFormatter::formatForReview()}.
 *
 * The static instruction text lives here; the dynamic middle (changed files +
 * affected code with snippets) is assembled by the formatter and bound as the
 * single {{ body }} section — the "computed section" pattern.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    description: 'Senior-PHP-reviewer prompt over a context package (changed files + affected code).',
)]
final class ReviewContextPrompt implements PromptDefinitionInterface
{
    public const ID = 'project-graph.review';

    public function system(): string
    {
        return <<<'PROMPT'
        You are a senior PHP code reviewer. Review the following code changes and their potential impact on the codebase.

        {{ body }}
        ## Instructions

        1. Review each changed file for correctness, performance, and security issues
        2. Consider the impact on affected nodes listed above
        3. Flag any breaking changes to public interfaces
        4. Suggest improvements with specific code examples
        5. Check for missing error handling, edge cases, and type safety
        PROMPT;
    }
}
