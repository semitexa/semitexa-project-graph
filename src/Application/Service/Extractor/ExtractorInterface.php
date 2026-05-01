<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

interface ExtractorInterface
{
    public function supports(ParsedFile $file): bool;
    public function extract(ParsedFile $file): ExtractionResult;
}
