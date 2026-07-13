<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * Refactor prompt built from a context package. Migrated out of
 * {@see \Semitexa\ProjectGraph\Application\Service\Context\PromptFormatter::formatForRefactor()}.
 *
 * {{ goal }} appears twice (opening line and instruction 2); {{ body }} carries
 * the assembled current-state section.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    description: 'Senior-PHP-architect refactor prompt toward a {{ goal }} over a context package.',
)]
final class RefactorContextPrompt implements PromptDefinitionInterface
{
    public const ID = 'project-graph.refactor';

    public function system(): string
    {
        return <<<'PROMPT'
        You are a senior PHP architect. Help refactor the following code to achieve: {{ goal }}

        {{ body }}
        ## Instructions

        1. Analyze the current architecture based on the code above
        2. Propose a refactoring strategy to achieve: {{ goal }}
        3. Identify dependencies that need to be updated
        4. Provide step-by-step migration instructions
        5. Highlight any risks or breaking changes
        PROMPT;
    }
}
