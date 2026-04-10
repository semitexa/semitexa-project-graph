<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

final readonly class FileIndexEntry
{
    public function __construct(
        public string $path,
        public string $contentHash,
        public int    $indexedAt,
        public string $module,
        public int    $lineCount,
        public bool   $isDirty,
    ) {}
}
