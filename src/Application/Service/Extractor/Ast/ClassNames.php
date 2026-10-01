<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node;

/**
 * Class names out of AST names and types, the way the graph wants them.
 *
 * NameResolver leaves self, static and parent as written, so they used to
 * reach the graph as nodes called "class:self" and "class:static" — 678
 * edges on the workspace, all pointing at no class. A class referring to
 * itself adds nothing to the graph and is skipped; parent is resolved to the
 * class it names.
 */
final class ClassNames
{
    /** The class a name refers to, or null for self/static (and an unresolvable parent). */
    public static function of(Node\Name $name, ?string $parentClass): ?string
    {
        return match ($name->toLowerString()) {
            'self', 'static' => null,
            'parent' => $parentClass,
            default => $name->toString(),
        };
    }

    /**
     * Every class a type declaration names: ?Foo gives Foo, Foo|Bar and
     * Foo&Bar give both. Builtins give nothing.
     *
     * @return list<string>
     */
    public static function inType(?Node $type, ?string $parentClass): array
    {
        if ($type instanceof Node\NullableType) {
            return self::inType($type->type, $parentClass);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $part) {
                $names = [...$names, ...self::inType($part, $parentClass)];
            }
            return array_values(array_unique($names));
        }
        if ($type instanceof Node\Name) {
            $class = self::of($type, $parentClass);
            return $class === null ? [] : [$class];
        }

        return [];
    }
}
