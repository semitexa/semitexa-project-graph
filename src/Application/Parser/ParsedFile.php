<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

final class ParsedFile
{
    /** @var array<int, mixed> */
    private readonly array $ast;
    private readonly string $code;
    /** @var array<string, ClassInfo>|null */
    private ?array $classInfoCache = null;

    /**
     * @param array<int, mixed> $ast
     */
    public function __construct(
        public readonly string $path,
        array $ast,
        string $code,
        public readonly string $module = '',
    ) {
        $this->ast  = $ast;
        $this->code = $code;
    }

    /** @return array<int, mixed> */
    public function ast(): array
    {
        return $this->ast;
    }

    public function code(): string
    {
        return $this->code;
    }

    /** @return list<ClassInfo> */
    public function getClasses(): array
    {
        if ($this->classInfoCache !== null) {
            return array_values($this->classInfoCache);
        }

        $this->classInfoCache = [];

        foreach ($this->ast as $stmt) {
            if ($stmt instanceof ClassLike && $stmt->namespacedName !== null) {
                $fqcn = $stmt->namespacedName->toString();
                try {
                    $ref = new \ReflectionClass($fqcn);
                    $this->classInfoCache[$fqcn] = ClassInfo::fromReflection($ref, $this->path);
                } catch (\ReflectionException) {
                }
            }
        }

        return array_values($this->classInfoCache);
    }

    public function hasAttribute(string $attributeClass): bool
    {
        foreach ($this->getClasses() as $info) {
            if ($info->hasAttribute($attributeClass)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<ClassInfo> */
    public function getClassesWithAttribute(string $attributeClass): array
    {
        $result = [];
        foreach ($this->getClasses() as $info) {
            if ($info->hasAttribute($attributeClass)) {
                $result[] = $info;
            }
        }
        return $result;
    }

    /** @return list<ClassInfo> */
    public function getClassesWithPropertyAttributes(array $attributeClasses): array
    {
        $result = [];
        foreach ($this->getClasses() as $info) {
            foreach ($info->properties as $prop) {
                foreach ($attributeClasses as $attrClass) {
                    if ($prop->hasAttribute($attrClass)) {
                        $result[] = $info;
                        break 2;
                    }
                }
            }
        }
        return $result;
    }
}
