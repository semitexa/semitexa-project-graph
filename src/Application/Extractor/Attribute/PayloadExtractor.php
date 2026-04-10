<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Attribute\AsPayloadPart;
use Semitexa\Core\Attribute\RequiresPermission;
use Semitexa\Core\Attribute\RequiresCapability;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class PayloadExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsPayload::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClassesWithAttribute(AsPayload::class) as $classInfo) {
            $attr = $classInfo->getAttribute(AsPayload::class);
            if ($attr === null) {
                continue;
            }
            $asPayload = $attr->newInstance();

            $payloadNode = new Node(
                id:       NodeId::forClass($classInfo->fqcn),
                type:     NodeType::Payload,
                fqcn:     $classInfo->fqcn,
                file:     $file->path,
                line:     $classInfo->startLine,
                endLine:  $classInfo->endLine,
                module:   $file->module,
                metadata: [
                    'path'          => $asPayload->path ?? '',
                    'methods'       => $asPayload->methods ?? ['GET'],
                    'responseClass' => $asPayload->responseClass ?? null,
                    'produces'      => $asPayload->produces ?? 'text/html',
                ],
            );
            $result->addNode($payloadNode);

            $methods = $asPayload->methods ?? ['GET'];
            foreach ($methods as $method) {
                $routeNode = new Node(
                    id:       NodeId::forRoute($method, $asPayload->path ?? ''),
                    type:     NodeType::Route,
                    fqcn:     $method . ' ' . ($asPayload->path ?? ''),
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->startLine,
                    module:   $file->module,
                    metadata: ['method' => $method, 'path' => $asPayload->path ?? ''],
                );
                $result->addNode($routeNode);

                $result->addEdge(new Edge(
                    sourceId: $payloadNode->id,
                    targetId: $routeNode->id,
                    type:     EdgeType::ServesRoute,
                    metadata: [],
                ));
            }

            foreach ($classInfo->usedTraits as $traitFqcn) {
                $result->addEdge(new Edge(
                    sourceId: $payloadNode->id,
                    targetId: NodeId::forClass($traitFqcn),
                    type:     EdgeType::ComposedOf,
                    metadata: [],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresPermission::class) as $perm) {
                $permInstance = $perm->newInstance();
                $result->addEdge(new Edge(
                    sourceId: $payloadNode->id,
                    targetId: 'permission:' . ($permInstance->slug ?? ''),
                    type:     EdgeType::RequiresPermission,
                    metadata: ['slug' => $permInstance->slug ?? ''],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresCapability::class) as $cap) {
                $capInstance = $cap->newInstance();
                $result->addEdge(new Edge(
                    sourceId: $payloadNode->id,
                    targetId: 'capability:' . ($capInstance->slug ?? ''),
                    type:     EdgeType::RequiresCapability,
                    metadata: ['slug' => $capInstance->slug ?? ''],
                ));
            }
        }

        return $result;
    }
}
