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
            private string $currentClass = '';

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {}

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->currentClass = $node->namespacedName->toString();
                }

                if ($node instanceof AstNode\Stmt\TraitUse && $this->currentClass !== '') {
                    foreach ($node->traits as $trait) {
                        $traitFqcn = $trait->toString();
                        $this->result->addEdge(new Edge(
                            sourceId: NodeId::forClass($this->currentClass),
                            targetId: NodeId::forClass($traitFqcn),
                            type:     EdgeType::Uses,
                            metadata: [],
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
