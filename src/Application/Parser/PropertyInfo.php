<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

final readonly class PropertyInfo
{
    public function __construct(
        public string $name,
        public ?string $typeFqcn,
        /** @var list<\ReflectionAttribute> */
        public array $attributes,
    ) {}

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
}
