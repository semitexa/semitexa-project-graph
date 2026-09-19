<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

final readonly class FileIndexEntry
{
    public function __construct(
        private string $path,
        private string $contentHash,
        private int    $indexedAt,
        private string $module,
        private int    $lineCount,
        private bool   $isDirty,
    ) {}

    public function getPath(): string
    {
        return $this->path;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getIndexedAt(): int
    {
        return $this->indexedAt;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function getLineCount(): int
    {
        return $this->lineCount;
    }

    public function getIsDirty(): bool
    {
        return $this->isDirty;
    }
}
