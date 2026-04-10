<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class CapabilityHint
{
    public function __construct(
        public readonly string $useWhen = '',
        public readonly string $avoidWhen = '',
        public readonly array $outputs = [],
        public readonly array $followUp = [],
    ) {}
}
