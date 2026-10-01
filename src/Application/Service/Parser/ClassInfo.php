<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;

final readonly class ClassInfo
{
    public function __construct(
        public string $fqcn,
        public int    $startLine,
        public int    $endLine,
        /** @var list<ParsedAttribute> */
        public array  $attributes,
        /** @var list<PropertyInfo> */
        public array  $properties,
        /** @var list<string> FQCNs */
        public array  $usedTraits,
        /** @var list<string> FQCNs the class declares it implements (an interface: the ones it extends) */
        public array  $interfaces,
        public ?string $parentClass,
    ) {}

    /**
     * Everything is read from the parsed file itself. This used to reflect the
     * class when it was loadable, which answered for whatever version the
     * process had loaded — stale in watch mode, HEAD's in a worktree of another
     * ref — and gave a class that was not loadable no attributes at all.
     */
    public static function fromAst(ClassLike $stmt, string $file, ?AttributeArgumentEvaluator $evaluator = null): self
    {
        $evaluator ??= new AttributeArgumentEvaluator();
        $fqcn = $stmt->namespacedName ? $stmt->namespacedName->toString() : ($stmt->name ? $stmt->name->toString() : 'Unknown');

        $interfaces = [];
        $declared = match (true) {
            $stmt instanceof Class_, $stmt instanceof Enum_ => $stmt->implements,
            $stmt instanceof Interface_ => $stmt->extends,
            default => [],
        };
        foreach ($declared as $iface) {
            $interfaces[] = $iface->toString();
        }

        $parentClass = null;
        if ($stmt instanceof Class_ && $stmt->extends !== null) {
            $parentClass = $stmt->extends->toString();
        }

        $usedTraits = [];
        $properties = [];
        foreach ($stmt->stmts as $subStmt) {
            if ($subStmt instanceof TraitUse) {
                foreach ($subStmt->traits as $trait) {
                    $usedTraits[] = $trait->toString();
                }
            }
            if ($subStmt instanceof ClassMethod && $subStmt->name->toLowerString() === '__construct') {
                // Promoted constructor parameters are properties too (reflection
                // listed them) — resource models declare relations this way.
                foreach ($subStmt->params as $param) {
                    if ($param->flags === 0 || !$param->var instanceof Variable || !is_string($param->var->name)) {
                        continue;
                    }
                    $properties[] = new PropertyInfo(
                        name:       $param->var->name,
                        typeFqcn:   self::classType($param->type),
                        attributes: self::attributes($param->attrGroups, $stmt, \Attribute::TARGET_PROPERTY, $evaluator),
                    );
                }
            }
            if ($subStmt instanceof Property) {
                $attributes = self::attributes($subStmt->attrGroups, $stmt, \Attribute::TARGET_PROPERTY, $evaluator);
                foreach ($subStmt->props as $prop) {
                    $properties[] = new PropertyInfo(
                        name:       $prop->name->toString(),
                        typeFqcn:   self::classType($subStmt->type),
                        attributes: $attributes,
                    );
                }
            }
        }

        return new self(
            fqcn:       $fqcn,
            startLine:  $stmt->getStartLine(),
            endLine:    $stmt->getEndLine(),
            attributes: self::attributes($stmt->attrGroups, $stmt, \Attribute::TARGET_CLASS, $evaluator),
            properties: $properties,
            usedTraits: $usedTraits,
            interfaces: $interfaces,
            parentClass: $parentClass,
        );
    }

    public function hasAttribute(string $attributeClass): bool
    {
        foreach ($this->attributes as $attr) {
            if ($attr->getName() === $attributeClass) {
                return true;
            }
        }
        return false;
    }

    public function getAttribute(string $attributeClass): ?ParsedAttribute
    {
        foreach ($this->attributes as $attr) {
            if ($attr->getName() === $attributeClass) {
                return $attr;
            }
        }
        return null;
    }

    /** @return list<ParsedAttribute> */
    public function getAttributes(string $attributeClass): array
    {
        return array_values(array_filter(
            $this->attributes,
            fn(ParsedAttribute $a) => $a->getName() === $attributeClass,
        ));
    }

    /**
     * @param list<AttributeGroup> $groups
     * @return list<ParsedAttribute>
     */
    private static function attributes(array $groups, ClassLike $context, int $target, AttributeArgumentEvaluator $evaluator): array
    {
        $attributes = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                [$arguments, $unreadable] = $evaluator->evaluate($attr, $context);
                $attributes[] = new ParsedAttribute($attr->name->toString(), $arguments, $target, $unreadable);
            }
        }

        return $attributes;
    }

    /** The class a property is typed with — ?Foo counts as Foo, as reflection's named type did; unions and builtins give null. */
    private static function classType(mixed $type): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Name ? $type->toString() : null;
    }
}
