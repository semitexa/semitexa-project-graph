<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;

final readonly class Edge
{
    public function __construct(
        private string   $sourceId,
        private string   $targetId,
        private EdgeType $type,
        private array    $metadata = [],
        private ?int     $id = null,
    ) {}

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getType(): EdgeType
    {
        return $this->type;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
