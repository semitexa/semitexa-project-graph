<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;

final readonly class ClassInfo
{
    public function __construct(
        public string $fqcn,
        public int    $startLine,
        public int    $endLine,
        /** @var list<\ReflectionAttribute> */
        public array  $attributes,
        /** @var list<PropertyInfo> */
        public array  $properties,
        /** @var list<string> FQCNs */
        public array  $usedTraits,
        /** @var list<string> FQCNs */
        public array  $interfaces,
        public ?string $parentClass,
    ) {}

    public static function fromReflection(\ReflectionClass $ref, string $file): self
    {
        $props = [];
        foreach ($ref->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            $type = $prop->getType();
            $typeFqcn = null;
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeFqcn = $type->getName();
            }
            $props[] = new PropertyInfo(
                name:       $prop->getName(),
                typeFqcn:   $typeFqcn,
                attributes: $prop->getAttributes(),
            );
        }

        return new self(
            fqcn:       $ref->getName(),
            startLine:  $ref->getStartLine(),
            endLine:    $ref->getEndLine(),
            attributes: $ref->getAttributes(),
            properties: $props,
            usedTraits: array_values($ref->getTraitNames()),
            interfaces: array_values($ref->getInterfaceNames()),
            parentClass: $ref->getParentClass() ? $ref->getParentClass()->getName() : null,
        );
    }

    public static function fromAst(ClassLike $stmt, string $file): self
    {
        $fqcn = $stmt->namespacedName ? $stmt->namespacedName->toString() : ($stmt->name ? $stmt->name->toString() : 'Unknown');

        $interfaces = [];
        if ($stmt instanceof Class_ || $stmt instanceof Interface_) {
            foreach ($stmt->implements ?? [] as $iface) {
                $interfaces[] = $iface->toString();
            }
        }

        $parentClass = null;
        if ($stmt instanceof Class_ && $stmt->extends !== null) {
            $parentClass = $stmt->extends->toString();
        }

        $usedTraits = [];
        foreach ($stmt->stmts as $subStmt) {
            if ($subStmt instanceof TraitUse) {
                foreach ($subStmt->traits as $trait) {
                    $usedTraits[] = $trait->toString();
                }
            }
        }

        $properties = [];
        foreach ($stmt->stmts as $subStmt) {
            if ($subStmt instanceof Property) {
                $typeFqcn = null;
                if ($subStmt->type instanceof Name) {
                    $typeFqcn = $subStmt->type->toString();
                }
                $properties[] = new PropertyInfo(
                    name:       $subStmt->props[0]->name->toString(),
                    typeFqcn:   $typeFqcn,
                    attributes: [],
                );
            }
        }

        return new self(
            fqcn:       $fqcn,
            startLine:  $stmt->getStartLine(),
            endLine:    $stmt->getEndLine(),
            attributes: [],
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

    public function getAttribute(string $attributeClass): ?\ReflectionAttribute
    {
        foreach ($this->attributes as $attr) {
            if ($attr->getName() === $attributeClass) {
                return $attr;
            }
        }
        return null;
    }

    /** @return list<\ReflectionAttribute> */
    public function getAttributes(string $attributeClass): array
    {
        return array_filter(
            $this->attributes,
            fn(\ReflectionAttribute $a) => $a->getName() === $attributeClass,
        );
    }
}
