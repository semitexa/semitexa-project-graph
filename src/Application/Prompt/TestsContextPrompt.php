<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/project-graph.tests.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    template: 'resources/prompts/project-graph.tests.twig',
    description: 'Senior-PHP-test-engineer prompt to generate coverage over a context package.',
)]
final class TestsContextPrompt
{
    public const ID = 'project-graph.tests';
}
