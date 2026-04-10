<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

final readonly class Edge
{
    public function __construct(
        public ?int     $id,
        public string   $sourceId,
        public string   $targetId,
        public EdgeType $type,
        public array    $metadata = [],
    ) {}
}
