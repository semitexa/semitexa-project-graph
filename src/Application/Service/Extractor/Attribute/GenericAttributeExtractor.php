<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Core\Attribute\AsCommand;
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

        return $result;
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
