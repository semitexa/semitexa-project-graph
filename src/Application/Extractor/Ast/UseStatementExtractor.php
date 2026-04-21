<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;

final class UseStatementExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        $visitor = new class($result) extends NodeVisitorAbstract {
            private string $currentNamespace = '';

            /** @var array<string, list<array{fqcn: string, alias: ?string}>> */
            private array $importsByNamespace = [];

            /** @var array<string, list<string>> */
            private array $classesByNamespace = [];

            public function __construct(
                private readonly ExtractionResult $result,
            ) {}

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\Namespace_) {
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->importsByNamespace[$this->currentNamespace] ??= [];
                    $this->classesByNamespace[$this->currentNamespace] ??= [];
                }

                if ($node instanceof AstNode\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        if ($use->type !== AstNode\Stmt\Use_::TYPE_NORMAL
                            && $use->type !== AstNode\Stmt\Use_::TYPE_UNKNOWN) {
                            continue;
                        }
                        $this->importsByNamespace[$this->currentNamespace][] = [
                            'fqcn'  => $use->name->toString(),
                            'alias' => $use->alias?->toString(),
                        ];
                    }
                }

                if ($node instanceof AstNode\Stmt\GroupUse) {
                    $prefix = $node->prefix->toString();
                    foreach ($node->uses as $use) {
                        $type = $use->type !== AstNode\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
                        if ($type !== AstNode\Stmt\Use_::TYPE_NORMAL) {
                            continue;
                        }
                        $this->importsByNamespace[$this->currentNamespace][] = [
                            'fqcn'  => $prefix . '\\' . $use->name->toString(),
                            'alias' => $use->alias?->toString(),
                        ];
                    }
                }

                if ($node instanceof AstNode\Stmt\ClassLike && $node->namespacedName !== null) {
                    $this->classesByNamespace[$this->currentNamespace][] = $node->namespacedName->toString();
                }

                return null;
            }

            public function afterTraverse(array $nodes): ?array
            {
                foreach ($this->classesByNamespace as $namespace => $classes) {
                    foreach ($classes as $classFqcn) {
                        foreach ($this->importsByNamespace[$namespace] ?? [] as $import) {
                            $this->result->addEdge(new Edge(
                                sourceId: NodeId::forClass($classFqcn),
                                targetId: NodeId::forClass($import['fqcn']),
                                type:     EdgeType::Imports,
                                metadata: ['alias' => $import['alias']],
                            ));
                        }
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }
}
