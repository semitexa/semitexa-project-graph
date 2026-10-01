<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Domain\Model;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;

/** Something in one file the graph knows it could not see. */
final readonly class CoverageGap
{
    public function __construct(
        private CoverageGapKind $kind,
        private string $file,
        private string $detail,
        private string $subject = '',
        private int $line = 0,
    ) {}

    public function getKind(): CoverageGapKind
    {
        return $this->kind;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    /** Human-readable reason, e.g. the parser or extractor message. */
    public function getDetail(): string
    {
        return $this->detail;
    }

    /** What the gap is about — a class, an extractor — when there is one. */
    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function withFile(string $file): self
    {
        return new self($this->kind, $file, $this->detail, $this->subject, $this->line);
    }
}
