<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Scanner;

final readonly class FileScanResult
{
    public function __construct(
        public string    $path,
        public string    $hash,
        public FileStatus $status,
    ) {}
}
