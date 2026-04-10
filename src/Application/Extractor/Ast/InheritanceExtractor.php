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
                    $fqcn = $node->namespacedName?->toString() ?? $this->resolveFqcn($node->name->toString());
                    $nodeId = NodeId::forClass($fqcn);

                    $nodeType = $node->isInterface() ? NodeType::Interface_
                        : ($node->isTrait() ? NodeType::Trait_
                        : ($node->isEnum() ? NodeType::Enum_
                        : NodeType::Class_));

                    $this->result->addNode(new Node(
                        id:       $nodeId,
                        type:     $nodeType,
                        fqcn:     $fqcn,
                        file:     $this->file->path,
                        line:     $node->getStartLine(),
                        endLine:  $node->getEndLine(),
                        module:   $this->file->module,
                        metadata: [
                            'abstract' => $node->isAbstract(),
                            'final'    => $node->isFinal(),
                            'readonly' => $node->isReadonly(),
                        ],
                    ));

                    if ($node->extends) {
                        $parentFqcn = $node->extends->toString();
                        $this->result->addEdge(new Edge(
                            sourceId: $nodeId,
                            targetId: NodeId::forClass($parentFqcn),
                            type:     EdgeType::Extends,
                            metadata: [],
                        ));
                    }

                    foreach ($node->implements as $interface) {
                        $this->result->addEdge(new Edge(
                            sourceId: $nodeId,
                            targetId: NodeId::forClass($interface->toString()),
                            type:     EdgeType::Implements,
                            metadata: [],
                        ));
                    }
                }

                return null;
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
