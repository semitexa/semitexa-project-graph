<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Parser\PropertyInfo;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class InjectionExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(InjectAsReadonly::class)
            || $file->hasAttribute(InjectAsMutable::class)
            || $file->hasAttribute(InjectAsFactory::class)
            || $file->hasAttribute(Config::class);
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
