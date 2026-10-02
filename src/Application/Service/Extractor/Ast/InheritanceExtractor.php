<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class InheritanceExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        $visitor = new class($file, $result) extends NodeVisitorAbstract {
            private readonly ClassScope $scope;

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {
                $this->scope = new ClassScope($file);
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\Class_ && $node->namespacedName === null) {
                    $this->scope->enter($node);
                    $this->anonymousClass($node);

                    return null;
                }
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                }

                if ($node instanceof AstNode\Stmt\Class_ && $node->namespacedName !== null) {
                    $fqcn = $node->namespacedName->toString();
                    $this->registerClassLike(
                        node: $node,
                        fqcn: $fqcn,
                        nodeType: NodeType::Class_,
                        metadata: [
                            'abstract' => $node->isAbstract(),
                            'final' => $node->isFinal(),
                            'readonly' => $node->isReadonly(),
                        ],
                    );

                    if ($node->extends !== null) {
                        $this->edge($fqcn, $node->extends->toString(), EdgeType::Extends);
                    }
                    foreach ($node->implements as $interface) {
                        $this->edge($fqcn, $interface->toString(), EdgeType::Implements);
                    }
                }

                if ($node instanceof AstNode\Stmt\Interface_ && $node->namespacedName !== null) {
                    $fqcn = $node->namespacedName->toString();
                    $this->registerClassLike($node, $fqcn, NodeType::Interface_);
                    foreach ($node->extends as $parent) {
                        $this->edge($fqcn, $parent->toString(), EdgeType::Extends);
                    }
                }

                if ($node instanceof AstNode\Stmt\Trait_ && $node->namespacedName !== null) {
                    $this->registerClassLike($node, $node->namespacedName->toString(), NodeType::Trait_);
                }

                if ($node instanceof AstNode\Stmt\Enum_ && $node->namespacedName !== null) {
                    $fqcn = $node->namespacedName->toString();
                    $this->registerClassLike($node, $fqcn, NodeType::Enum_);
                    foreach ($node->implements as $interface) {
                        $this->edge($fqcn, $interface->toString(), EdgeType::Implements);
                    }
                }

                return null;
            }

            public function leaveNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->leave($node);
                }

                return null;
            }

            /**
             * An anonymous class has no node of its own. What it extends and
             * implements is a dependency of the code that declares it — the
             * host class, or the file at the top level — so it is a
             * REFERENCE from there ({via: anonymous_class, relation}). It used
             * to be stored as the host's own extends/implements, flagged
             * anonymous:true: 293 edges on the workspace (measured
             * 2026-10-02), e.g. LedgerBootstrap "implementing"
             * QueueTransportFactoryInterface, and no reader of inheritance
             * (query --usages, blast radius, relevance, cross-module edges)
             * looks at the flag. Before that it left "class:<Namespace>"
             * (see ClassScope).
             */
            private function anonymousClass(AstNode\Stmt\Class_ $node): void
            {
                $owner = $this->scope->owner();
                $parents = [];
                if ($node->extends !== null) {
                    $parents[] = [$node->extends->toString(), EdgeType::Extends];
                }
                foreach ($node->implements as $interface) {
                    $parents[] = [$interface->toString(), EdgeType::Implements];
                }

                foreach ($parents as [$target, $type]) {
                    if ($target === $owner) {
                        continue;
                    }
                    $this->result->addEdge(new Edge(
                        $this->scope->sourceIn($this->result),
                        NodeId::forClass($target),
                        EdgeType::References,
                        ['via' => 'anonymous_class', 'relation' => $type->value],
                    ));
                }
            }

            private function edge(string $source, string $target, EdgeType $type): void
            {
                $this->result->addEdge(new Edge(
                    sourceId: NodeId::forClass($source),
                    targetId: NodeId::forClass($target),
                    type: $type,
                    metadata: [],
                ));
            }

            /** @param array<string, mixed> $metadata */
            private function registerClassLike(
                AstNode\Stmt\ClassLike $node,
                string $fqcn,
                NodeType $nodeType,
                array $metadata = [],
            ): void {
                $this->result->addNode(new Node(
                    id: NodeId::forClass($fqcn),
                    type: $nodeType,
                    fqcn: $fqcn,
                    file: $this->file->path,
                    line: $node->getStartLine(),
                    endLine: $node->getEndLine(),
                    module: $this->file->module,
                    metadata: $metadata,
                ));
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
