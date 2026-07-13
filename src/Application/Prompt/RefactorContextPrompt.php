<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/project-graph.refactor.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    template: 'resources/prompts/project-graph.refactor.twig',
    description: 'Senior-PHP-architect refactor prompt toward a {{ goal }} over a context package.',
)]
final class RefactorContextPrompt
{
    public const ID = 'project-graph.refactor';
}
