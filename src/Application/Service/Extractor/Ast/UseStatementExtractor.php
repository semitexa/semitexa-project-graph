<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Ast;

use PhpParser\Node as AstNode;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\FileNode;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * `use` imports, as edges from the class that uses what they import.
 *
 * Imports belong to the file, and they used to be crossed with every class in
 * it: in a file with two classes each one "imported" what only the other
 * uses, and `use App\Lib;` (a namespace, used as `Lib\Thing`) became a
 * placeholder class:App\Lib. Now an import is attached to the classes whose
 * own code — attributes, signatures, bodies, anonymous classes inside them —
 * names the imported class.
 *
 * An import no class names is kept on the file's class when the namespace
 * holds exactly one: it is that class's (dead) import, and the class still
 * depends on it for "who imports X". With several classes there is no telling
 * whose it is, and a namespace import used only as a prefix is not a class.
 *
 * Round 2 (2026-10-02):
 *  - a docblock type names a class as much as a signature does (`@var
 *    list<Edge>`, `@param array<string, Node>`, `@return`, `@see`): in a
 *    multi-class file an import named only there used to belong to no class
 *    and was dropped;
 *  - code outside every class (a function below the class, bootstrap code)
 *    owns its imports through the FILE node: in a one-class namespace the
 *    fallback pinned a function's import on the class.
 */
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

            private readonly ClassScope $scope;

            /** @var array<string, list<array{fqcn: string, alias: ?string}>> */
            private array $importsByNamespace = [];

            /** @var array<string, list<string>> */
            private array $classesByNamespace = [];

            /** @var array<string, array<string, true>> class => resolved names its code mentions */
            private array $namesByClass = [];

            /** @var array<int, true> function and constant names: never classes */
            private array $notClassNames = [];

            /** @var array<string, array<string, true>> class => names its docblocks write, as written */
            private array $docNamesByClass = [];

            /** @var array<string, array<string, true>> namespace => resolved names code outside any class mentions */
            private array $topLevelNames = [];

            /** @var array<string, array<string, true>> namespace => names docblocks outside any class write */
            private array $topLevelDocNames = [];

            public function __construct(
                private readonly ParsedFile $file,
                private readonly ExtractionResult $result,
            ) {
                $this->scope = new ClassScope($file);
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\Namespace_) {
                    if ($node->name !== null) {
                        $this->notClassNames[spl_object_id($node->name)] = true;
                    }
                    $this->currentNamespace = $node->name?->toString() ?? '';
                    $this->importsByNamespace[$this->currentNamespace] ??= [];
                    $this->classesByNamespace[$this->currentNamespace] ??= [];
                }

                if ($node instanceof AstNode\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $this->notClassNames[spl_object_id($use->name)] = true;
                        // ->type, not ->getType(): since php-parser 5 getType()
                        // is the node's own kind ("UseItem"), which never equals
                        // a Use_::TYPE_* constant — every import was skipped and
                        // the graph carried no imports edges at all.
                        $type = $use->type !== AstNode\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $node->type;
                        if ($type !== AstNode\Stmt\Use_::TYPE_NORMAL) {
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
                    $this->notClassNames[spl_object_id($node->prefix)] = true;
                    foreach ($node->uses as $use) {
                        $this->notClassNames[spl_object_id($use->name)] = true;
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

                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                    if ($node->namespacedName !== null) {
                        $this->classesByNamespace[$this->currentNamespace][] = $node->namespacedName->toString();
                    }
                }

                if ($node instanceof AstNode\Expr\FuncCall || $node instanceof AstNode\Expr\ConstFetch) {
                    $this->notClassNames[spl_object_id($node->name)] = true;
                }

                if ($node instanceof AstNode\Name && !isset($this->notClassNames[spl_object_id($node)])) {
                    $owner = $this->scope->owner();
                    if ($owner !== null) {
                        $this->namesByClass[$owner][$node->toString()] = true;
                    } else {
                        $this->topLevelNames[$this->currentNamespace][$node->toString()] = true;
                    }
                }

                if ($node instanceof AstNode\Stmt && ($doc = $node->getDocComment()) !== null) {
                    $owner = $this->scope->owner();
                    foreach (UseStatementExtractor::docblockNames($doc->getText()) as $name) {
                        if ($owner !== null) {
                            $this->docNamesByClass[$owner][$name] = true;
                        } else {
                            $this->topLevelDocNames[$this->currentNamespace][$name] = true;
                        }
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

            public function afterTraverse(array $nodes): ?array
            {
                foreach ($this->importsByNamespace as $namespace => $imports) {
                    $classes = $this->classesByNamespace[$namespace] ?? [];
                    foreach ($imports as $import) {
                        $topLevel = $this->namedBy($import, $this->topLevelNames[$namespace] ?? [], $this->topLevelDocNames[$namespace] ?? []);
                        foreach ($this->importers($import, $classes, $topLevel) as $classFqcn) {
                            $this->result->addEdge(new Edge(
                                sourceId: NodeId::forClass($classFqcn),
                                targetId: NodeId::forClass($import['fqcn']),
                                type:     EdgeType::Imports,
                                metadata: ['alias' => $import['alias']],
                            ));
                        }
                        if ($topLevel) {
                            $this->result->addNode(FileNode::of($this->file->path, $this->file->code(), $this->file->module, ['top_level_code' => true]));
                            $this->result->addEdge(new Edge(
                                sourceId: NodeId::forFile($this->file->path),
                                targetId: NodeId::forClass($import['fqcn']),
                                type:     EdgeType::Imports,
                                metadata: ['alias' => $import['alias']],
                            ));
                        }
                    }
                }

                return null;
            }

            /**
             * Whether code names an import: a resolved name in code, or the
             * import's alias (or a name it prefixes) written in a docblock.
             *
             * @param array{fqcn: string, alias: ?string} $import
             * @param array<string, true> $names resolved names in code
             * @param array<string, true> $docNames names as docblocks write them
             */
            private function namedBy(array $import, array $names, array $docNames): bool
            {
                if (isset($names[$import['fqcn']])) {
                    return true;
                }
                $alias = $import['alias'] ?? substr($import['fqcn'], (int) strrpos('\\' . $import['fqcn'], '\\'));

                return isset($docNames[$alias]) || isset($docNames['\\' . $import['fqcn']]);
            }

            /**
             * @param array{fqcn: string, alias: ?string} $import
             * @param list<string> $classes
             * @return list<string>
             */
            private function importers(array $import, array $classes, bool $usedAtTopLevel): array
            {
                $fqcn = $import['fqcn'];
                $using = array_values(array_filter($classes, fn (string $class): bool => $this->namedBy($import, $this->namesByClass[$class] ?? [], $this->docNamesByClass[$class] ?? [])));
                if ($using !== [] || count($classes) !== 1 || $usedAtTopLevel) {
                    return $using;
                }
                foreach (array_keys($this->namesByClass[$classes[0]] ?? []) as $name) {
                    if (str_starts_with($name, $fqcn . '\\')) {
                        return [];
                    }
                }

                return $classes;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());

        return $result;
    }

    /**
     * The class names a docblock's tags write, as written (alias or
     * \Fully\Qualified): the type after @var/@param/@return/@throws/@see/...,
     * generics and shapes included (list<Edge>, array{a: Node}). Prose is
     * not read: only the type expression right after a tag.
     *
     * @return list<string>
     */
    public static function docblockNames(string $doc): array
    {
        if (!str_contains($doc, '@')) {
            return [];
        }
        $names = [];
        preg_match_all('/@[A-Za-z-]+(?:\s+|(?=[<(]))/', $doc, $tags, PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as [$tag, $offset]) {
            $type = '';
            $depth = 0;
            for ($i = $offset + strlen($tag), $n = strlen($doc); $i < $n; $i++) {
                $c = $doc[$i];
                if (strpbrk($c, '<{([') !== false) {
                    $depth++;
                } elseif (strpbrk($c, '>})]') !== false) {
                    $depth--;
                } elseif (($c === ' ' || $c === "\n" || $c === "\t" || $c === '*') && $depth <= 0) {
                    break;
                }
                $type .= $c;
            }
            preg_match_all('/(?<![\w$\\\\-])\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', $type, $m);
            foreach ($m[0] as $name) {
                $names[$name] = true;
                // `Lib\Thing` names the alias Lib as well.
                if ($name[0] !== '\\' && str_contains($name, '\\')) {
                    $names[strstr($name, '\\', true)] = true;
                }
            }
        }

        return array_keys($names);
    }
}
