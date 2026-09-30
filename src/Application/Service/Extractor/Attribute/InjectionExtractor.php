<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
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

        foreach ($file->getClasses() as $classInfo) {
            foreach ($classInfo->properties as $prop) {
                $this->extractInjection($result, $classInfo, $prop);
            }
        }

        return $result;
    }

    private function extractInjection(ExtractionResult $result, ClassInfo $classInfo, PropertyInfo $prop): void
    {
        if ($attr = $prop->getAttribute(InjectAsReadonly::class)) {
            $result->addEdge(new Edge(
                sourceId: NodeId::forClass($classInfo->fqcn),
                targetId: $prop->typeFqcn ? NodeId::forClass($prop->typeFqcn) : 'unknown:' . $prop->name,
                type:     EdgeType::InjectsReadonly,
                metadata: ['property' => $prop->name],
            ));
        }

        if ($attr = $prop->getAttribute(InjectAsMutable::class)) {
            $result->addEdge(new Edge(
                sourceId: NodeId::forClass($classInfo->fqcn),
                targetId: $prop->typeFqcn ? NodeId::forClass($prop->typeFqcn) : 'unknown:' . $prop->name,
                type:     EdgeType::InjectsMutable,
                metadata: ['property' => $prop->name],
            ));
        }

        if ($attr = $prop->getAttribute(InjectAsFactory::class)) {
            $result->addEdge(new Edge(
                sourceId: NodeId::forClass($classInfo->fqcn),
                targetId: $prop->typeFqcn ? NodeId::forClass($prop->typeFqcn) : 'unknown:' . $prop->name,
                type:     EdgeType::InjectsFactory,
                metadata: ['property' => $prop->name],
            ));
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
}
