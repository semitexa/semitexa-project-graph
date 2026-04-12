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

    public static function forDomain(string $name): string
    {
        return 'domain:' . $name;
    }

    public static function forFlow(string $name): string
    {
        return 'flow:' . $name;
    }

    public static function forEventFlow(string $name): string
    {
        return 'event_flow:' . $name;
    }

    public static function forLifecycle(string $name): string
    {
        return 'lifecycle:' . $name;
    }

    public static function forBoundary(string $name): string
    {
        return 'boundary:' . $name;
    }

    public static function forHotspot(string $name): string
    {
        return 'hotspot:' . $name;
    }

    public static function forStream(string $name): string
    {
        return 'stream:' . $name;
    }

    public static function forSubject(string $pattern): string
    {
        return 'subject:' . $pattern;
    }

    public static function forConsumer(string $name): string
    {
        return 'consumer:' . $name;
    }

    public static function forSchema(string $eventClass): string
    {
        return 'schema:' . $eventClass;
    }

    public static function forAggregate(string $type): string
    {
        return 'aggregate:' . $type;
    }

    public static function forReplayPath(string $name): string
    {
        return 'replay:' . $name;
    }

    public static function forDoc(string $targetNodeId): string
    {
        return 'doc:' . $targetNodeId;
    }

    public static function forExample(string $targetNodeId, string $label): string
    {
        return 'example:' . $targetNodeId . ':' . $label;
    }

    public static function forAdr(string $id): string
    {
        return 'adr:' . $id;
    }

    public static function extractFqcn(string $id): string
    {
        $pos = strpos($id, ':');
        return $pos !== false ? substr($id, $pos + 1) : $id;
    }
}
