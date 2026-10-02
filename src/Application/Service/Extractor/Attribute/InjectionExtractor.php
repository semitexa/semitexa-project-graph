<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use PhpParser\Node as AstNode;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\ProjectGraph\Application\Service\Extractor\Ast\ClassNames;
use Semitexa\ProjectGraph\Application\Service\Extractor\Ast\DeclaredClassLikes;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Service\Parser\PropertyInfo;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;

final class InjectionExtractor implements ExtractorInterface
{
    /**
     * These attributes sit on PROPERTIES. ParsedFile::hasAttribute() reads
     * class attributes only, and asking it here kept this extractor from ever
     * running: the whole graph carried zero injects_* edges while 668 files
     * declared an injection (measured 2026-09-30).
     */
    public function supports(ParsedFile $file): bool
    {
        return $file->getClassesWithPropertyAttributes([
            InjectAsReadonly::class,
            InjectAsMutable::class,
            InjectAsFactory::class,
            Config::class,
        ]) !== [];
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        $types = self::propertyTypes($file);

        foreach ($file->getClasses() as $classInfo) {
            foreach ($classInfo->properties as $prop) {
                $this->extractInjection($result, $classInfo, $prop, $types[$classInfo->fqcn][$prop->name] ?? null);
            }
        }

        return $result;
    }

    /**
     * One edge per class the declared type names: `A|B $events` is injected
     * as A or B, `?Clock` as Clock.
     *
     * PropertyInfo carries one class or null, and a union (or a missing type)
     * used to point at 'unknown:<property>' — one node per property NAME, so
     * every class injecting a union-typed $events became a neighbour of every
     * other through a shared unknown:events (measured 2026-10-02). An
     * injection with no class type has nothing to point at and makes no edge.
     */
    private function extractInjection(ExtractionResult $result, ClassInfo $classInfo, PropertyInfo $prop, ?AstNode $type): void
    {
        $targets = $type !== null
            ? ClassNames::inType($type, $classInfo->parentClass)
            : ($prop->typeFqcn !== null ? [$prop->typeFqcn] : []);

        foreach ([
            InjectAsReadonly::class => EdgeType::InjectsReadonly,
            InjectAsMutable::class  => EdgeType::InjectsMutable,
            InjectAsFactory::class  => EdgeType::InjectsFactory,
        ] as $attribute => $edgeType) {
            if ($prop->getAttribute($attribute) === null) {
                continue;
            }
            foreach ($targets as $target) {
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: NodeId::forClass($target),
                    type:     $edgeType,
                    metadata: ['property' => $prop->name],
                ));
            }
        }

        if ($attr = $prop->getAttribute(Config::class)) {
            $configAttr = $attr->newInstance();
            $result->addEdge(new Edge(
                sourceId: NodeId::forClass($classInfo->fqcn),
                targetId: 'config:' . ($configAttr->env ?? $configAttr->key ?? $prop->name),
                type:     EdgeType::InjectsConfig,
                metadata: [
                    'property' => $prop->name,
                    'env'      => $configAttr->env ?? null,
                    'default'  => $configAttr->default ?? null,
                ],
            ));
        }
    }

    /**
     * The declared type of every property, promoted ones included, by class.
     *
     * @return array<string, array<string, ?AstNode>>
     */
    private static function propertyTypes(ParsedFile $file): array
    {
        $types = [];
        foreach (DeclaredClassLikes::in($file->ast()) as $classLike) {
            $class = $classLike->namespacedName->toString();
            foreach ($classLike->stmts as $stmt) {
                if ($stmt instanceof AstNode\Stmt\Property) {
                    foreach ($stmt->props as $prop) {
                        $types[$class][$prop->name->toString()] = $stmt->type;
                    }
                }
                if ($stmt instanceof AstNode\Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                    foreach ($stmt->params as $param) {
                        if ($param->flags !== 0 && $param->var instanceof AstNode\Expr\Variable && is_string($param->var->name)) {
                            $types[$class][$param->var->name] = $param->type;
                        }
                    }
                }
            }
        }

        return $types;
    }
}
