<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Prompt;

use Semitexa\Prompt\Attribute\AsPrompt;
use Semitexa\Prompt\Domain\Contract\BoundPromptInterface;

/**
 * Thin, self-binding prompt — body in resources/prompts/project-graph.refactor.twig.
 */
#[AsPrompt(
    id: self::ID,
    channel: 'project-graph',
    template: 'resources/prompts/project-graph.refactor.twig',
    description: 'Senior-PHP-architect refactor prompt toward a {{ prompt.goal }} over a context package.',
)]
final class RefactorContextPrompt implements BoundPromptInterface
{
    public const ID = 'project-graph.refactor';

    public function __construct(
        private readonly ?string $goal = null,
        private readonly ?string $body = null,
    ) {}

    public function withData(string $goal, string $body): self
    {
        return new self($goal, $body);
    }

    public function promptId(): string
    {
        return self::ID;
    }

    public function goal(): string
    {
        return (string) $this->goal;
    }

    public function body(): string
    {
        return (string) $this->body;
    }
}
