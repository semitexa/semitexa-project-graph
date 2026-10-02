<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use PhpParser\Node as AstNode;
use Semitexa\Core\Attribute\AsCommand;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Semitexa\ProjectGraph\Application\Service\Extractor\Ast\ClassScope;
use Semitexa\ProjectGraph\Application\Service\Extractor\Ast\DeclaredClassLikes;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class GenericAttributeExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        $members = self::memberAttributes($file);

        foreach ($file->getClasses() as $classInfo) {
            $classId = NodeId::forClass($classInfo->fqcn);

            // Every attribute applied is a reference to the attribute class —
            // without it, an attribute class used only as #[Foo] had no inbound
            // edge and looked unused. The target says where it was applied;
            // a framework attribute on the class itself is how the framework
            // discovers the class.
            foreach ($classInfo->attributes as $attr) {
                $result->addEdge(self::annotatedWith($classId, $attr->getName(), 'class'));
            }
            foreach ($classInfo->properties as $property) {
                foreach ($property->attributes as $attr) {
                    $result->addEdge(self::annotatedWith($classId, $attr->getName(), 'property'));
                }
            }
            foreach ($members[$classInfo->fqcn] ?? [] as [$attribute, $target]) {
                $result->addEdge(self::annotatedWith($classId, $attribute, $target));
            }

            foreach ($classInfo->attributes as $attr) {
                if ($attr->getName() === AsCommand::class) {
                    $instance = $attr->newInstance();
                    $result->addNode(new Node(
                        id:       $classId,
                        type:     NodeType::Command,
                        fqcn:     $classInfo->fqcn,
                        file:     $file->path,
                        line:     $classInfo->startLine,
                        endLine:  $classInfo->endLine,
                        module:   $file->module,
                        metadata: [
                            'commandName' => $instance->name ?? '',
                            'description' => $instance->description ?? '',
                        ],
                    ));
                }
            }
        }

        self::unnamedDeclarationAttributes($file, $result);

        return $result;
    }

    /**
     * Attributes on what ClassInfo does not cover — an anonymous class (and
     * its members) and a function outside any class — are annotations of the
     * code that declares them: the host class, or the file at the top level.
     * They made no annotated_with edge at all (round 2, 2026-10-02), so an
     * attribute class applied only there looked unused.
     */
    private static function unnamedDeclarationAttributes(ParsedFile $file, ExtractionResult $result): void
    {
        // A second walk of every file's AST, only where an attribute is written at all.
        if (!str_contains($file->code(), '#[')) {
            return;
        }
        $visitor = new class($file, $result) extends NodeVisitorAbstract {
            private readonly ClassScope $scope;

            public function __construct(ParsedFile $file, private readonly ExtractionResult $result)
            {
                $this->scope = new ClassScope($file);
            }

            public function enterNode(AstNode $node): ?int
            {
                if ($node instanceof AstNode\Stmt\ClassLike) {
                    $this->scope->enter($node);
                    if ($node->namespacedName === null) {
                        $this->annotate($node->attrGroups, 'anonymous_class');
                        foreach ($node->stmts as $stmt) {
                            match (true) {
                                $stmt instanceof AstNode\Stmt\ClassMethod => $this->function($stmt, 'method'),
                                $stmt instanceof AstNode\Stmt\ClassConst => $this->annotate($stmt->attrGroups, 'constant'),
                                $stmt instanceof AstNode\Stmt\Property => $this->annotate($stmt->attrGroups, 'property'),
                                default => null,
                            };
                        }
                    }
                } elseif ($node instanceof AstNode\Stmt\Function_ && $this->scope->owner() === null) {
                    $this->function($node, 'function');
                }

                return null;
            }

            public function leaveNode(AstNode $node): ?int
            {
                $this->scope->leave($node);

                return null;
            }

            private function function(AstNode\Stmt\ClassMethod|AstNode\Stmt\Function_ $function, string $target): void
            {
                $this->annotate($function->attrGroups, $target);
                foreach ($function->params as $param) {
                    $this->annotate($param->attrGroups, $param->flags === 0 ? 'parameter' : 'property');
                }
            }

            /** @param list<AstNode\AttributeGroup> $groups */
            private function annotate(array $groups, string $target): void
            {
                foreach ($groups as $group) {
                    foreach ($group->attrs as $attr) {
                        $this->result->addEdge(new Edge(
                            sourceId: $this->scope->sourceIn($this->result),
                            targetId: NodeId::forClass($attr->name->toString()),
                            type:     EdgeType::AnnotatedWith,
                            metadata: ['target' => $target],
                        ));
                    }
                }
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($file->ast());
    }

    /**
     * Attributes on methods, their parameters, class constants and enum
     * cases, read from the AST: ClassInfo carries class and property
     * attributes only, so an attribute class applied only to members had no
     * inbound edge and looked unused (on the workspace: #[UiOn] and
     * #[ProvidesUiPart] target methods, #[LiveFilterParam] parameters). A promoted parameter is skipped: it is a
     * property, and its attributes are already read as one.
     *
     * @return array<string, list<array{0: string, 1: string}>> class => [attribute, target]
     */
    private static function memberAttributes(ParsedFile $file): array
    {
        $members = [];
        foreach (DeclaredClassLikes::in($file->ast()) as $classLike) {
            $found = [];
            foreach ($classLike->stmts as $stmt) {
                $groups = match (true) {
                    $stmt instanceof AstNode\Stmt\ClassMethod => [[$stmt->attrGroups, 'method']],
                    $stmt instanceof AstNode\Stmt\ClassConst => [[$stmt->attrGroups, 'constant']],
                    $stmt instanceof AstNode\Stmt\EnumCase => [[$stmt->attrGroups, 'enum_case']],
                    default => [],
                };
                if ($stmt instanceof AstNode\Stmt\ClassMethod) {
                    foreach ($stmt->params as $param) {
                        if ($param->flags === 0) {
                            $groups[] = [$param->attrGroups, 'parameter'];
                        }
                    }
                }
                foreach ($groups as [$attrGroups, $target]) {
                    foreach ($attrGroups as $group) {
                        foreach ($group->attrs as $attr) {
                            $found[] = [$attr->name->toString(), $target];
                        }
                    }
                }
            }
            $members[$classLike->namespacedName->toString()] = $found;
        }

        return $members;
    }

    private static function annotatedWith(string $classId, string $attribute, string $target): Edge
    {
        return new Edge(
            sourceId: $classId,
            targetId: NodeId::forClass($attribute),
            type:     EdgeType::AnnotatedWith,
            metadata: ['target' => $target],
        );
    }
}
