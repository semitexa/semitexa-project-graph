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

final class TraitUseExtractor implements ExtractorInterface
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

                // A trait used by an anonymous class is part of the code of
                // the class declaring it (see ClassScope).
                if ($node instanceof AstNode\Stmt\TraitUse) {
                    foreach ($node->traits as $trait) {
                        $this->result->addEdge(new Edge(
                            sourceId: $this->scope->sourceIn($this->result),
                            targetId: NodeId::forClass($trait->toString()),
                            type:     EdgeType::Uses,
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
