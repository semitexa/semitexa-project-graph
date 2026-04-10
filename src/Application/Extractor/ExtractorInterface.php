<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor;

use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

interface ExtractorInterface
{
    public function supports(ParsedFile $file): bool;
    public function extract(ParsedFile $file): ExtractionResult;
}
