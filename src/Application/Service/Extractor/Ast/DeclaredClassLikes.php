<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node;

/**
 * The named class-likes a file declares, found by walking statements only.
 *
 * A named class is declared by a statement — at the top, in a namespace, or
 * conditionally in an if/function body — never inside an expression (that is
 * an anonymous class, which has no name to report). NodeFinder visits every
 * expression node too: as a ninth traversal per file it cost ~1.1s of a
 * workspace build (4552 files, measured 2026-10-02); this costs next to
 * nothing.
 */
final class DeclaredClassLikes
{
    /**
     * @param array<mixed> $stmts
     * @return list<Node\Stmt\ClassLike>
     */
    public static function in(array $stmts): array
    {
        $found = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassLike) {
                if ($stmt->namespacedName !== null) {
                    $found[] = $stmt;
                }
                continue;
            }
            if (!$stmt instanceof Node\Stmt) {
                continue;
            }
            foreach ($stmt->getSubNodeNames() as $name) {
                $child = $stmt->$name;
                if (is_array($child)) {
                    $found = [...$found, ...self::in($child)];
                } elseif ($child instanceof Node\Stmt) {
                    $found = [...$found, ...self::in([$child])];
                }
            }
        }

        return $found;
    }
}
