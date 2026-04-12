<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

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
            private string $currentNamespace = '';

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {}

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                }

                if ($node instanceof AstNode\Stmt\Class_) {
                    $this->registerClassLike(
                        node: $node,
                        nodeType: NodeType::Class_,
                        metadata: [
                            'abstract' => $node->isAbstract(),
                            'final' => $node->isFinal(),
                            'readonly' => $node->isReadonly(),
                        ],
                    );

                    if ($node->extends !== null) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->resolveNodeFqcn($node)),
                            targetId: NodeId::forClass($node->extends->toString()),
                            type: EdgeType::Extends,
                            metadata: [],
                        ));
                    }

                    foreach ($node->implements as $interface) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->resolveNodeFqcn($node)),
                            targetId: NodeId::forClass($interface->toString()),
                            type: EdgeType::Implements,
                            metadata: [],
                        ));
                    }
                }

                if ($node instanceof AstNode\Stmt\Interface_) {
                    $this->registerClassLike($node, NodeType::Interface_);
                    foreach ($node->extends as $parent) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->resolveNodeFqcn($node)),
                            targetId: NodeId::forClass($parent->toString()),
                            type: EdgeType::Extends,
                            metadata: [],
                        ));
                    }
                }

                if ($node instanceof AstNode\Stmt\Trait_) {
                    $this->registerClassLike($node, NodeType::Trait_);
                }

                if ($node instanceof AstNode\Stmt\Enum_) {
                    $this->registerClassLike($node, NodeType::Enum_);
                    foreach ($node->implements as $interface) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->resolveNodeFqcn($node)),
                            targetId: NodeId::forClass($interface->toString()),
                            type: EdgeType::Implements,
                            metadata: [],
                        ));
                    }
                }

                return null;
            }

            private function registerClassLike(
                AstNode\Stmt\ClassLike $node,
                NodeType $nodeType,
                array $metadata = [],
            ): void {
                if ($node->name === null) {
                    return;
                }

                $fqcn = $this->resolveNodeFqcn($node);

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

            private function resolveNodeFqcn(AstNode\Stmt\ClassLike $node): string
            {
                if ($node->name === null) {
                    return $this->currentNamespace;
                }

                return $node->namespacedName?->toString() ?? $this->resolveFqcn($node->name->toString());
            }

            private function resolveFqcn(string $shortName): string
            {
                return $this->currentNamespace
                    ? $this->currentNamespace . '\\' . $shortName
                    : $shortName;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
