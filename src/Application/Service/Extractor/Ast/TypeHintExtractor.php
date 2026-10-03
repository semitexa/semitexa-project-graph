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

/**
 * Declared types: what a class accepts (parameters, properties) and returns.
 *
 * Only method signatures used to be read. A class typed only on a property,
 * a closure or arrow-function parameter, a property-hook parameter or a
 * function made no edge and looked unused. A property type is an `accepts`:
 * the class takes an instance of it in, the same as a promoted constructor
 * parameter (which was always read, as a method parameter).
 */
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
            private readonly ClassScope $scope;

            public function __construct(
                ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {
                $this->scope = new ClassScope($file);
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                }

                if ($node instanceof AstNode\Stmt\ClassMethod
                    || $node instanceof AstNode\Stmt\Function_
                    || $node instanceof AstNode\Expr\Closure
                    || $node instanceof AstNode\Expr\ArrowFunction
                ) {
                    $this->params($node->getParams());
                    $name = $node instanceof AstNode\Stmt\ClassMethod || $node instanceof AstNode\Stmt\Function_
                        ? $node->name->toString()
                        : '{closure}';
                    $this->edges($node->getReturnType(), EdgeType::Returns, [$node instanceof AstNode\Stmt\Function_ ? 'function' : 'method' => $name]);
                }

                if ($node instanceof AstNode\PropertyHook) {
                    $this->params($node->params);
                }

                if ($node instanceof AstNode\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $this->edges($node->type, EdgeType::Accepts, ['property' => $prop->name->toString()]);
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

            /** @param array<AstNode\Param> $params */
            private function params(array $params): void
            {
                foreach ($params as $param) {
                    $this->edges($param->type, EdgeType::Accepts, ['param' => $param->var instanceof AstNode\Expr\Variable && is_string($param->var->name) ? $param->var->name : '']);
                }
            }

            /** @param array<string, string> $metadata */
            private function edges(?AstNode $type, EdgeType $edgeType, array $metadata): void
            {
                foreach (ClassNames::inType($type, $this->scope->parentClass()) as $typeFqcn) {
                    $this->result->addEdge(new Edge(
                        sourceId: $this->scope->sourceIn($this->result),
                        targetId: NodeId::forClass($typeFqcn),
                        type:     $edgeType,
                        metadata: $metadata,
                    ));
                }
            }
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
