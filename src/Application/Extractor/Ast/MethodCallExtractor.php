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
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class MethodCallExtractor implements ExtractorInterface
{
    private const DISPATCH_METHODS = ['dispatch', 'create', 'emit'];

    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        $dispatchMethods = self::DISPATCH_METHODS;

        $visitor = new class($file, $result, $dispatchMethods) extends NodeVisitorAbstract {
            private string $currentClass = '';

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
                private readonly array $dispatchMethods,
            ) {}

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->currentClass = $node->namespacedName->toString();
                }

                if ($node instanceof AstNode\Expr\MethodCall
                    && $node->var instanceof AstNode\Expr\PropertyFetch
                    && $node->var->var instanceof AstNode\Expr\Variable
                    && $node->var->var->name === 'this'
                ) {
                    $methodName = $node->name instanceof AstNode\Identifier
                        ? $node->name->toString()
                        : null;

                    if (in_array($methodName, $this->dispatchMethods, true)
                        && count($node->args) > 0
                        && $this->currentClass !== ''
                    ) {
                        $firstArg = $node->args[0]->value ?? null;
                        if ($firstArg instanceof AstNode\Expr\New_) {
                            $eventClass = $firstArg->class instanceof AstNode\Name
                                ? $firstArg->class->toString()
                                : null;
                            if ($eventClass !== null) {
                                $this->result->addEdge(new Edge(
                                    sourceId: NodeId::forClass($this->currentClass),
                                    targetId: NodeId::forClass($eventClass),
                                    type:     EdgeType::Emits,
                                    metadata: ['method' => $methodName],
                                ));
                            }
                        }
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
