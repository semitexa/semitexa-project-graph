<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

/**
 * An enum case named in an attribute argument (`execution: EventExecution::Async`),
 * read from the enum's AST instead of loading the enum: see
 * {@see ClassDeclarationReader} for why a scan never loads project code.
 *
 * It has the same `name` and `value` a real case has, so `Enum::Case->value`
 * and `->name` evaluate as before, and it JSON-encodes as the case would.
 * It is not the enum: an attribute whose constructor demands the real type
 * refuses it, and ParsedAttribute::newInstance() then hands out an
 * AttributeStandIn instead.
 */
final readonly class EnumCaseReference implements \JsonSerializable
{
    public function __construct(
        public string $class,
        public string $name,
        public int|string|null $value,
    ) {}

    /** Encoded as the enum itself would be: a backed case as its value. */
    public function jsonSerialize(): int|string
    {
        return $this->value ?? $this->name;
    }

    /** The scalar an argument stands for: a backed case's value, a pure case's name. */
    public static function scalarOf(mixed $argument): mixed
    {
        return match (true) {
            $argument instanceof self => $argument->value ?? $argument->name,
            $argument instanceof \BackedEnum => $argument->value,
            $argument instanceof \UnitEnum => $argument->name,
            default => $argument,
        };
    }
}
