<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

use Semitexa\ProjectGraph\Application\Graph\EdgeType;

final readonly class Edge
{
    public function __construct(
        public string   $sourceId,
        public string   $targetId,
        public EdgeType $type,
        public array    $metadata = [],
        public ?int     $id = null,
    ) {}
}
