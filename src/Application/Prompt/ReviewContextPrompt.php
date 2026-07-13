<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;

/**
 * Thin prompt definition — the body lives in resources/prompts/project-graph.review.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    description: 'Senior-PHP-reviewer prompt over a context package (changed files + affected code).',
)]
final class ReviewContextPrompt
{
    public const ID = 'project-graph.review';
}
