<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Graph;

final class NodeId
{
    public static function forClass(string $fqcn): string
    {
        return 'class:' . $fqcn;
    }

    public static function forMethod(string $classFqcn, string $methodName): string
    {
        return 'method:' . $classFqcn . '::' . $methodName;
    }

    public static function forProperty(string $classFqcn, string $propertyName): string
    {
        return 'prop:' . $classFqcn . '::$' . $propertyName;
    }

    public static function forRoute(string $method, string $path): string
    {
        return 'route:' . $method . ':' . $path;
    }

    public static function forModule(string $moduleName): string
    {
        return 'module:' . $moduleName;
    }

    public static function forFile(string $path): string
    {
        return 'file:' . $path;
    }

    public static function forNamespace(string $namespace): string
    {
        return 'ns:' . $namespace;
    }

    public static function extractFqcn(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos !== false ? substr($id, $pos + 1) : $id;
    }
}
