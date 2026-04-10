<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class UseStatementExtractor implements ExtractorInterface
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

                if ($node instanceof AstNode\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $usedFqcn = $use->name->toString();
                        if ($this->currentNamespace !== '') {
                            $alias = $use->alias?->toString();
                            $this->result->addEdge(new Edge(
                                sourceId: 'ns:' . $this->currentNamespace,
                                targetId: 'ns:' . $usedFqcn,
                                type:     EdgeType::Imports,
                                metadata: ['alias' => $alias],
                            ));
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
