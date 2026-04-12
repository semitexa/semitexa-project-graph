<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\AsEventListener;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\ExecutionScoped;
use Semitexa\Core\Attribute\InjectAsFactory;
use Semitexa\Core\Attribute\InjectAsMutable;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class GenericAttributeExtractor implements ExtractorInterface
{
    private const HANDLED = [
        AsPayload::class,
        AsPayloadHandler::class,
        AsResource::class,
        AsService::class,
        AsEventListener::class,
        InjectAsReadonly::class,
        InjectAsMutable::class,
        InjectAsFactory::class,
        ExecutionScoped::class,
    ];

    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            foreach ($classInfo->attributes as $attr) {
                if ($attr->getName() === AsCommand::class) {
                    $instance = $attr->newInstance();
                    $result->addNode(new Node(
                        id:       NodeId::forClass($classInfo->fqcn),
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

                    continue;
                }

                if (in_array($attr->getName(), self::HANDLED, true)) {
                    continue;
                }

                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'attributes', [
                    'name'   => $attr->getName(),
                    'args'   => $attr->getArguments(),
                    'target' => $attr->getTarget(),
                ]);
            }
        }

        return $result;
    }
}
