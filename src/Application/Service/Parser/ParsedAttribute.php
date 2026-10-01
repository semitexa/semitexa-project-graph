<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

/**
 * An attribute as written in the file being parsed — read from its AST, never
 * from the class it annotates.
 *
 * It stands in for \ReflectionAttribute with the same small surface the
 * extractors use (getName, getArguments, getTarget, newInstance). Reflection
 * answered for whatever version of the annotated class the PROCESS had
 * loaded: in watch mode that is the version from before the edit, in a
 * worktree of another git ref it is HEAD's, and a class that could not be
 * autoloaded came back with no attributes at all.
 *
 * newInstance() still loads the ATTRIBUTE class (a framework class) and any
 * enum or class constant an argument names — never the annotated class.
 */
final readonly class ParsedAttribute
{
    /**
     * @param array<int|string, mixed> $arguments positional by index, named by name — as \ReflectionAttribute::getArguments()
     * @param ?string $unreadable why the arguments could not be evaluated, or null when they were
     */
    public function __construct(
        private string $name,
        private array $arguments,
        private int $target,
        private ?string $unreadable = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<int|string, mixed> */
    public function getArguments(): array
    {
        if ($this->unreadable !== null) {
            throw new \RuntimeException(sprintf('Arguments of #[%s] are not readable: %s', $this->name, $this->unreadable));
        }

        return $this->arguments;
    }

    /** One of the \Attribute::TARGET_* constants. */
    public function getTarget(): int
    {
        return $this->target;
    }

    public function unreadableReason(): ?string
    {
        return $this->unreadable;
    }

    public function newInstance(): object
    {
        $class = $this->name;

        return new $class(...$this->getArguments());
    }
}
