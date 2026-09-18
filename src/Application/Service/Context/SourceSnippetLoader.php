<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Context;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class SourceSnippetLoader
{
    public function loadSnippet(Node $node, int $contextLines = 5): ?string
    {
        if ($node->getFile() === '' || !is_file($node->getFile())) {
            return null;
        }

        $lines = file($node->getFile());
        if ($lines === false) {
            return null;
        }

        $start = max(0, $node->getLine() - 1 - $contextLines);
        $end = min(count($lines), $node->getEndLine() + $contextLines);
        $snippetLines = array_slice($lines, $start, $end - $start);

        return implode('', $snippetLines);
    }

    public function loadFullFile(string $filePath): ?string
    {
        if (!is_file($filePath)) {
            return null;
        }
        return file_get_contents($filePath);
    }
}
