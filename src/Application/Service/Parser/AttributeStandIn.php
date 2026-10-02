<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

/**
 * What an attribute object would have carried, when the attribute cannot be
 * constructed from arguments read off the AST — its constructor types an
 * enum, and the scan holds an {@see EnumCaseReference} because it never loads
 * the enum (see {@see ParsedAttribute::newInstance()}).
 *
 * Each constructor parameter becomes a property of the same name: the
 * argument given (by name or position), else the parameter's default; then
 * the attribute class's other public properties with their defaults. Only
 * the ATTRIBUTE class is reflected — a framework class already loaded to
 * instantiate it. A default that names an enum loads that enum (the
 * attribute's own package); one that cannot be evaluated is left null.
 */
#[\AllowDynamicProperties]
final class AttributeStandIn
{
    /** @param array<int|string, mixed> $arguments */
    public static function of(string $attributeClass, array $arguments): self
    {
        $standIn = new self();
        $reflection = new \ReflectionClass($attributeClass);
        $parameters = $reflection->getConstructor()?->getParameters() ?? [];

        foreach ($parameters as $position => $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $arguments)) {
                $standIn->{$name} = $arguments[$name];
            } elseif (array_key_exists($position, $arguments)) {
                $standIn->{$name} = $parameter->isVariadic() ? array_slice($arguments, $position) : $arguments[$position];
            } else {
                $standIn->{$name} = self::defaultOf($parameter);
            }
        }
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if (!property_exists($standIn, $name) && !$property->isStatic() && $property->hasDefaultValue()) {
                $standIn->{$name} = $property->getDefaultValue();
            }
        }

        return $standIn;
    }

    private static function defaultOf(\ReflectionParameter $parameter): mixed
    {
        try {
            return $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
