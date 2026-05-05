<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * Extracts route + capability/permission edges for every routable payload.
 *
 * The graph is access-type-agnostic — Public/Protected/Service all become
 * Payload nodes that serve a Route. The access classification flows separately
 * via AuthExtractor. Project-graph callers inspect node metadata for the
 * 'accessType' field when they need to filter by access class.
 */
final class PayloadExtractor implements ExtractorInterface
{
    use SafeAttributeResolver;

    private const ACCESS_ATTRIBUTES = [
        AsPublicPayload::class    => 'public',
        AsProtectedPayload::class => 'protected',
        AsServicePayload::class   => 'service',
    ];

    public function supports(ParsedFile $file): bool
    {
        foreach (array_keys(self::ACCESS_ATTRIBUTES) as $attributeClass) {
            if ($file->hasAttribute($attributeClass)) {
                return true;
            }
        }
        return false;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach (self::ACCESS_ATTRIBUTES as $attributeClass => $accessType) {
            foreach ($file->getClassesWithAttribute($attributeClass) as $classInfo) {
                $attr = $classInfo->getAttribute($attributeClass);
                if ($attr === null) {
                    continue;
                }
                $instance = $this->safeNewInstance($attr);
                if ($instance === null) {
                    continue;
                }

                $payloadNode = new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::Payload,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [
                        'path'          => $instance->path ?? '',
                        'methods'       => $instance->methods ?? ['GET'],
                        'responseClass' => $instance->responseWith ?? null,
                        'produces'      => $instance->produces ?? ['text/html'],
                        'accessType'    => $accessType,
                    ],
                );
                $result->addNode($payloadNode);

                $methods = $instance->methods ?? ['GET'];
                foreach ($methods as $method) {
                    $routeNode = new Node(
                        id:       NodeId::forRoute($method, $instance->path ?? ''),
                        type:     NodeType::Route,
                        fqcn:     $method . ' ' . ($instance->path ?? ''),
                        file:     $file->path,
                        line:     $classInfo->startLine,
                        endLine:  $classInfo->startLine,
                        module:   $file->module,
                        metadata: ['method' => $method, 'path' => $instance->path ?? ''],
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
                    $permInstance = $this->safeNewInstance($perm);
                    if ($permInstance !== null) {
                        $slug = $permInstance->permission ?? '';
                        $result->addEdge(new Edge(
                            sourceId: $payloadNode->id,
                            targetId: 'permission:' . $slug,
                            type:     EdgeType::RequiresPermission,
                            metadata: ['slug' => $slug],
                        ));
                    }
                }

                foreach ($classInfo->getAttributes(RequiresCapability::class) as $cap) {
                    $capInstance = $this->safeNewInstance($cap);
                    if ($capInstance !== null) {
                        $capability = $capInstance->capability ?? null;
                        $slug = $capability instanceof \BackedEnum ? (string) $capability->value : '';
                        $result->addEdge(new Edge(
                            sourceId: $payloadNode->id,
                            targetId: 'capability:' . $slug,
                            type:     EdgeType::RequiresCapability,
                            metadata: ['slug' => $slug],
                        ));
                    }
                }
            }
        }

        return $result;
    }
}
