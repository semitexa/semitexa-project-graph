<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\PromptDefinitionInterface;

/**
 * Test-generation prompt built from a context package. Migrated out of
 * {@see \Semitexa\ProjectGraph\Application\Service\Context\PromptFormatter::formatForTests()}.
 *
 * {{ body }} carries the assembled code-to-test section.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    description: 'Senior-PHP-test-engineer prompt to generate coverage over a context package.',
)]
final class TestsContextPrompt implements PromptDefinitionInterface
{
    public const ID = 'project-graph.tests';

    public function system(): string
    {
        return <<<'PROMPT'
        You are a senior PHP test engineer. Generate comprehensive test coverage for the following code.

        {{ body }}
        ## Instructions

        1. Identify test cases for each class/method
        2. Cover happy paths, edge cases, and error conditions
        3. Include integration tests for cross-component interactions
        4. Use PHPUnit conventions
        5. Prioritize tests by risk and business impact
        PROMPT;
    }
}
