<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/project-graph.review.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    template: 'resources/prompts/project-graph.review.twig',
    description: 'Senior-PHP-reviewer prompt over a context package (changed files + affected code).',
)]
final class ReviewContextPrompt implements BoundPromptInterface
{
    public const ID = 'project-graph.review';

    public function __construct(
        private readonly ?string $body = null,
    ) {}

    public function withData(string $body): self
    {
        return new self($body);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function body(): string
    {
        return (string) $this->body;
    }
}
