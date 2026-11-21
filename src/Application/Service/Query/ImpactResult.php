<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

final readonly class ImpactResult
{
    public function __construct(
        /** @var list<string> */
        public array $changed,
        /** @var array<string, ImpactedNode> */
        public array $impacted,
    ) {}

    public static function empty(): self
    {
        return new self([], []);
    }
}
