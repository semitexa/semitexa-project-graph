<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class GraphIgnore
{
    public function __construct(
        public readonly string $reason = '',
    ) {}
}
