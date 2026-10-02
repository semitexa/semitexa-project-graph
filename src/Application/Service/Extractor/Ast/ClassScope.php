<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\FileNode;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

/**
 * Which declaration the code being visited belongs to: a stack of class
 * bodies, pushed on enterNode and popped on leaveNode.
 *
 * The extractors used to keep one "current class", set when a NAMED class was
 * entered and never cleared. Measured 2026-10-02 on the workspace:
 *  - an anonymous class (no namespacedName) did not change it, so its body
 *    was credited to the host, and parent:: inside it resolved against the
 *    HOST's parent; InheritanceExtractor instead named it after its
 *    namespace — 195 extends/implements edges from a "class:<Namespace>"
 *    node no file declares, so no re-index ever removed them;
 *  - code after a class (a function declared below it) was credited to that
 *    class, and code in a file with no class (config/*.php, bootstrap) made
 *    no edge at all, so the classes it builds were graded HIGH unused.
 *
 * An anonymous class belongs to the named class that declares it (it is part
 * of that class's code), with its OWN parent for parent::. Code outside any
 * named class belongs to the file: its edges leave the file's `file:` node,
 * which the file owns.
 */
final class ClassScope
{
    /** @var list<array{owner: ?string, parent: ?string}> */
    private array $frames = [];

    public function __construct(
        private readonly ParsedFile $file,
    ) {}

    /**
     * Call on enterNode with each ClassLike. Callers test instanceof first:
     * a method call on every AST node, in seven visitors, is measurable on a
     * workspace build. True when $node opened a class body.
     */
    public function enter(Node $node): bool
    {
        if (!$node instanceof Node\Stmt\ClassLike) {
            return false;
        }

        $parent = $node instanceof Node\Stmt\Class_ ? $node->extends?->toString() : null;
        $this->frames[] = $node->namespacedName !== null
            ? ['owner' => $node->namespacedName->toString(), 'parent' => $parent]
            : ['owner' => $this->owner(), 'parent' => $parent];

        return true;
    }

    /** Call on leaveNode. */
    public function leave(Node $node): void
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->frames);
        }
    }

    /** The named class the code belongs to; null outside any. */
    public function owner(): ?string
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['owner'];
    }

    /** What parent:: means here — the innermost class body's own parent. */
    public function parentClass(): ?string
    {
        return $this->frames === [] ? null : $this->frames[array_key_last($this->frames)]['parent'];
    }

    /**
     * The node an edge from the current code leaves: the owning class, or
     * the file. The file node is added to $result when used, so an edge never
     * leaves a node nobody declared.
     */
    public function sourceIn(ExtractionResult $result): string
    {
        $owner = $this->owner();
        if ($owner !== null) {
            return NodeId::forClass($owner);
        }

        $result->addNode(FileNode::of($this->file->path, $this->file->code(), $this->file->module, ['top_level_code' => true]));

        return NodeId::forFile($this->file->path);
    }
}
