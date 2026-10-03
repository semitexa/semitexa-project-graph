<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassDeclarationReader;
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
 * `$object::class` is not one of those: it is get_class() on an object that
 * exists, so it hides no edge — it was 253 of the workspace's 361
 * dynamic_reference gaps (measured 2026-10-02), enough to turn
 * absence_is_proof off in 8 modules.
 *
 * Attribute arguments are references too (they used to be skipped whole):
 *  - Foo::class there is via 'attribute'. Same-namespace
 *    `#[AsSlotHandler(slot: MySlot::class)]` left MySlot HIGH unused — the
 *    slot edge goes to slot:<FQCN>, not to the class. Where an attribute
 *    extractor already turned the argument into its typed edge (handles,
 *    produces, listens_to, ...) ExtractorPipeline drops this duplicate.
 *  - Foo::BAR there is via 'constant', always: a route read from
 *    `#[AsPublicPayload(path: Routes::ORDERS)]` depends on the file declaring
 *    Routes, and the refresh engine finds the files to re-read by this edge.
 *    When the constant is INHERITED — `path: self::P` with P on the parent,
 *    or Routes::P with P on Routes' parent — the file that matters is the
 *    one DECLARING it, so the edge goes there too (found by walking the
 *    extends/implements/use chain in ASTs, never by loading). Round 2, repro
 *    b4 (measured 2026-10-02): self::P made no edge at all, so editing the
 *    parent's constant left the payload's old route in the graph.
 */
final class ReferenceExtractor implements ExtractorInterface
{
    public const VIA_ATTRIBUTE = 'attribute';

    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        $visitor = new class($file, $result) extends NodeVisitorAbstract {
            private readonly ClassScope $scope;

            /** How many attribute groups the visit is inside. */
            private int $inAttribute = 0;

            /** @var list<AstNode\Stmt\ClassLike> the class bodies being visited, innermost last */
            private array $classLikes = [];

            private readonly ClassDeclarationReader $declarations;

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {
                $this->scope = new ClassScope($file);
                $this->declarations = ClassDeclarationReader::shared();
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                    $this->classLikes[] = $node;
                    return null;
                }
                if ($node instanceof AstNode\AttributeGroup) {
                    $this->inAttribute++;
                    return null;
                }

                if ($this->inAttribute > 0) {
                    if ($node instanceof AstNode\Expr\ClassConstFetch && $node->class instanceof AstNode\Name) {
                        $isClassName = $node->name instanceof AstNode\Identifier && $node->name->toLowerString() === 'class';
                        $this->reference($node->class, $isClassName ? ReferenceExtractor::VIA_ATTRIBUTE : 'constant', $node);
                        if (!$isClassName && $node->name instanceof AstNode\Identifier) {
                            $this->declaringClass($node->class, $node->name->toString());
                        }
                    }
                    return null;
                }

                match (true) {
                    $node instanceof AstNode\Expr\ClassConstFetch => $this->classConstant($node),
                    $node instanceof AstNode\Expr\StaticCall => $this->reference($node->class, 'static_call', $node),
                    $node instanceof AstNode\Expr\StaticPropertyFetch => $this->reference($node->class, 'static_property', $node),
                    $node instanceof AstNode\Expr\Instanceof_ => $this->reference($node->class, 'instanceof', $node),
                    $node instanceof AstNode\Stmt\Catch_ => array_map(fn (AstNode\Name $type) => $this->reference($type, 'catch', $node), $node->types),
                    default => null,
                };

                return null;
            }

            public function leaveNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\AttributeGroup) {
                    $this->inAttribute--;
                }
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->leave($node);
                    array_pop($this->classLikes);
                }

                return null;
            }

            /**
             * The class that declares an attribute's constant, when it is not
             * the one the code names: see the class doc (inherited constants).
             */
            private function declaringClass(AstNode\Name $class, string $constant): void
            {
                $current = $this->classLikes[array_key_last($this->classLikes) ?? -1] ?? null;
                $named = ClassNames::of($class, $this->scope->parentClass());
                $start = $named === null ? $current : $this->declarations->find($named, $this->file->path);
                if ($start === null) {
                    return;
                }

                $found = $this->declarations->declarationOf($start, $constant, 0, $this->file->path);
                $declarer = $found !== null ? $found[0]->namespacedName?->toString() : null;
                if ($found === null && $named === null) {
                    // Not readable from here (the parent's file is not mapped):
                    // the direct parent is still the nearest file it can be in.
                    $declarer = $this->scope->parentClass();
                }
                if ($declarer === null || $declarer === $named || $declarer === $this->scope->owner()) {
                    return;
                }

                $this->result->addEdge(new Edge(
                    sourceId: $this->scope->sourceIn($this->result),
                    targetId: NodeId::forClass($declarer),
                    type:     EdgeType::References,
                    metadata: ['via' => 'constant'],
                ));
            }

            private function classConstant(AstNode\Expr\ClassConstFetch $node): void
            {
                $isClassName = $node->name instanceof AstNode\Identifier && $node->name->toLowerString() === 'class';
                if ($isClassName && $node->class instanceof AstNode\Expr) {
                    return;
                }
                $this->reference($node->class, $isClassName ? 'class_name' : 'constant', $node);
            }

            private function reference(AstNode $class, string $via, AstNode $at): void
            {
                if ($class instanceof AstNode\Name) {
                    $target = ClassNames::of($class, $this->scope->parentClass());
                    if ($target !== null && $target !== $this->scope->owner()) {
                        $this->result->addEdge(new Edge(
                            sourceId: $this->scope->sourceIn($this->result),
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
                    $this->scope->owner() ?? '',
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
