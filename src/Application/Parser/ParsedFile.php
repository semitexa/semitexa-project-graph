<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Parser;

use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\ClassLike;

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
        $finder = new NodeFinder();
        /** @var list<ClassLike> $classLikes */
        $classLikes = $finder->findInstanceOf($this->ast, ClassLike::class);

        foreach ($classLikes as $stmt) {
            if ($stmt->namespacedName === null) {
                continue;
            }

            $fqcn = $stmt->namespacedName->toString();

            try {
                $exists = @class_exists($fqcn) || @interface_exists($fqcn) || @trait_exists($fqcn) || @enum_exists($fqcn);
                if ($exists) {
                    $ref = new \ReflectionClass($fqcn);
                    $this->classInfoCache[$fqcn] = ClassInfo::fromReflection($ref, $this->path);
                    continue;
                }
            } catch (\Throwable) {
            }

            $this->classInfoCache[$fqcn] = ClassInfo::fromAst($stmt, $this->path);
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
