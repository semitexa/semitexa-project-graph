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
 * newInstance() still loads the ATTRIBUTE class (a framework class) — never
 * the annotated class, nor a constant holder or enum an argument names: those
 * are read from their ASTs (an enum case arrives as an EnumCaseReference).
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

    /**
     * The attribute object — or, when its constructor refuses an argument read
     * from an AST (an {@see EnumCaseReference} where it types the real enum),
     * an {@see AttributeStandIn} carrying the same named values.
     *
     * Enum cases used to be the loaded enums; reading them from the AST
     * instead (so a scan never runs project code) made 108 edges vanish on
     * the workspace (measured 2026-10-02: AsPublicPayload's transport:
     * TransportType::Sse lost the route, SatisfiesServiceContract's
     * factoryKey lost satisfies_contract, ORM relations lost has_relation and
     * maps_to_table), because every extractor reads attributes through
     * newInstance(). The stand-in keeps what they read.
     */
    public function newInstance(): object
    {
        $class = $this->name;
        $arguments = $this->getArguments();

        try {
            return new $class(...$arguments);
        } catch (\TypeError $e) {
            if (!self::carriesEnumReference($arguments)) {
                throw $e;
            }

            return AttributeStandIn::of($class, $arguments);
        }
    }

    /** @param array<int|string, mixed> $arguments */
    private static function carriesEnumReference(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof EnumCaseReference || is_array($argument) && self::carriesEnumReference($argument)) {
                return true;
            }
        }

        return false;
    }
}
