<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Dynamic;

/** Instantiates a class the graph cannot name: the dynamic-reference coverage gap. */
final class PluginLoader
{
    public function load(string $class): object
    {
        return new $class();
    }
}
