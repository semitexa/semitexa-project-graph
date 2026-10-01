<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * References to a class that are neither a type, an instantiation nor
 * inheritance: Foo::class as a value (container lookups, class-string
 * config), static calls and properties, class constants and enum cases,
 * instanceof and catch. Without them a class used only that way looked unused.
 *
 * When the class is only known at runtime ($class::create(), $x instanceof
 * $name) there is nothing to point an edge at; that is recorded as a
 * dynamic_reference gap instead, so "nothing uses this" can be qualified.
 *
 * Attribute arguments are skipped: the attribute extractors already turn
 * them into the wiring edges they mean.
 */
final class ReferenceExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        $visitor = new class($file, $result) extends NodeVisitorAbstract {
            private string $currentClass = '';
            private ?string $parentClass = null;

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {}

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\AttributeGroup) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof AstNode\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->currentClass = $node->namespacedName->toString();
                    $this->parentClass = $node instanceof AstNode\Stmt\Class_ ? $node->extends?->toString() : null;
                    return null;
                }
                if ($this->currentClass === '') {
                    return null;
                }

                match (true) {
                    $node instanceof AstNode\Expr\ClassConstFetch => $this->reference(
                        $node->class,
                        $node->name instanceof AstNode\Identifier && $node->name->toLowerString() === 'class' ? 'class_name' : 'constant',
                        $node,
                    ),
                    $node instanceof AstNode\Expr\StaticCall => $this->reference($node->class, 'static_call', $node),
                    $node instanceof AstNode\Expr\StaticPropertyFetch => $this->reference($node->class, 'static_property', $node),
                    $node instanceof AstNode\Expr\Instanceof_ => $this->reference($node->class, 'instanceof', $node),
                    $node instanceof AstNode\Stmt\Catch_ => array_map(fn (AstNode\Name $type) => $this->reference($type, 'catch', $node), $node->types),
                    default => null,
                };

                return null;
            }

            private function reference(AstNode $class, string $via, AstNode $at): void
            {
                if ($class instanceof AstNode\Name) {
                    $target = ClassNames::of($class, $this->parentClass);
                    if ($target !== null && $target !== $this->currentClass) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->currentClass),
                            targetId: NodeId::forClass($target),
                            type:     EdgeType::References,
                            metadata: ['via' => $via],
                        ));
                    }
                    return;
                }

                $this->result->gaps[] = new CoverageGap(
                    CoverageGapKind::DynamicReference,
                    $this->file->path,
                    sprintf('A %s on a class known only at runtime', str_replace('_', ' ', $via)),
                    $this->currentClass,
                    $at->getStartLine(),
                );
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
