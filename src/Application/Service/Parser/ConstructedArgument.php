<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Parser;

/**
 * `new X(...)` inside an attribute argument, recorded and NOT executed.
 *
 * Scanning reads every file in the project, including ones nobody loads; an
 * attribute argument that constructed the object would run that class's
 * constructor in the scanning process for any file that names it. No
 * extractor reads a constructed object, so the graph keeps the class and its
 * (already evaluated) arguments instead.
 */
final class ConstructedArgument
{
    /** @param array<int|string, mixed> $arguments */
    public function __construct(
        public readonly string $class,
        public readonly array $arguments,
    ) {
    }
}
