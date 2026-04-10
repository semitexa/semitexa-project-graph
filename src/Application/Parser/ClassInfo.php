<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

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
