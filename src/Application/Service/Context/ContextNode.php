<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Context;

use Semitexa\ProjectGraph\Domain\Model\Node;

final readonly class ContextNode
{
    public function __construct(
        public Node   $node,
        public float  $score,
        public ?string $snippet,
    ) {}
}
