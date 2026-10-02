<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The `file:` node a file's own references hang off when no class in it
 * makes them: a config file naming classes, or PHP outside any class —
 * config/*.php returning objects, bootstrap scripts, functions.
 *
 * One construction for both, so the id and the ownership (the node's file is
 * the file itself, which is what lets re-indexing or deleting the file remove
 * it and every edge leaving it) cannot drift apart between them.
 */
final class FileNode
{
    /** @param array<string, mixed> $metadata */
    public static function of(string $path, string $contents, string $module, array $metadata): Node
    {
        return new Node(
            id:       NodeId::forFile($path),
            type:     NodeType::File,
            fqcn:     basename($path),
            file:     $path,
            line:     1,
            endLine:  substr_count($contents, "\n") + 1,
            module:   $module,
            metadata: $metadata,
        );
    }
}
