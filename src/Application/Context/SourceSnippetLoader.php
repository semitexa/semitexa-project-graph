<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Context;

use Semitexa\ProjectGraph\Application\Db\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class SourceSnippetLoader
{
    public function loadSnippet(Node $node, int $contextLines = 5): ?string
    {
        if ($node->file === '' || !is_file($node->file)) {
            return null;
        }

        $lines = file($node->file);
        if ($lines === false) {
            return null;
        }

        $start = max(0, $node->line - 1 - $contextLines);
        $end = min(count($lines), $node->endLine + $contextLines);
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
