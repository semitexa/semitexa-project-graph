<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;

final readonly class Node
{
    public function __construct(
        private string   $id,
        private NodeType $type,
        private string   $fqcn,
        private string   $file,
        private int      $line,
        private int      $endLine,
        private string   $module,
        private array    $metadata,
        private bool     $isPlaceholder = false,
    ) {}

    public function name(): string
    {
        $pos = strrpos($this->fqcn, '\\');
        return $pos !== false ? substr($this->fqcn, $pos + 1) : $this->fqcn;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): NodeType
    {
        return $this->type;
    }

    public function getFqcn(): string
    {
        return $this->fqcn;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getEndLine(): int
    {
        return $this->endLine;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getIsPlaceholder(): bool
    {
        return $this->isPlaceholder;
    }
}
