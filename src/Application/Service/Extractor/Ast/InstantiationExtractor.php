<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class InstantiationExtractor implements ExtractorInterface
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
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                }

                // Top-level code too: `return new Foo()` in config/*.php is
                // the only use of Foo (see ClassScope).
                if ($node instanceof AstNode\Expr\New_) {
                    $class = $node->class;
                    if ($class instanceof AstNode\Expr) {
                        $this->result->gaps[] = new CoverageGap(
                            CoverageGapKind::DynamicReference,
                            $this->file->path,
                            'An instantiation of a class known only at runtime',
                            $this->scope->owner() ?? '',
                            $node->getStartLine(),
                        );
                    }
                    if ($class instanceof AstNode\Name && ($targetFqcn = ClassNames::of($class, $this->scope->parentClass())) !== null) {
                        $this->result->addEdge(new Edge(
                            sourceId: $this->scope->sourceIn($this->result),
                            targetId: NodeId::forClass($targetFqcn),
                            type:     EdgeType::Instantiates,
                            metadata: [],
                        ));
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
        };

        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
