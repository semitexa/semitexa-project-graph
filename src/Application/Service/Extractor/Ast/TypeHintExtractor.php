<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class TypeHintExtractor implements ExtractorInterface
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
                if ($node instanceof AstNode\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->currentClass = $node->namespacedName->toString();
                    $this->parentClass = $node instanceof AstNode\Stmt\Class_ ? $node->extends?->toString() : null;
                }

                if ($node instanceof AstNode\Stmt\ClassMethod && $this->currentClass !== '') {
                    foreach ($node->getParams() as $param) {
                        foreach (ClassNames::inType($param->type, $this->parentClass) as $typeFqcn) {
                            $this->result->addEdge(new Edge(
                                sourceId: NodeId::forClass($this->currentClass),
                                targetId: NodeId::forClass($typeFqcn),
                                type:     EdgeType::Accepts,
                                metadata: ['param' => $param->var->name ?? ''],
                            ));
                        }
                    }

                    foreach (ClassNames::inType($node->returnType, $this->parentClass) as $returnFqcn) {
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->currentClass),
                            targetId: NodeId::forClass($returnFqcn),
                            type:     EdgeType::Returns,
                            metadata: ['method' => $node->name->toString()],
                        ));
                    }
                }

                return null;
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
