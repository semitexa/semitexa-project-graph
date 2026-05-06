<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Auth\Attribute\AsAuthHandler;
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class AuthExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsAuthHandler::class)
            || $file->hasAttribute(RequiresPermission::class)
            || $file->hasAttribute(RequiresCapability::class)
            || $file->hasAttribute(AsPublicPayload::class)
            || $file->hasAttribute(AsServicePayload::class);
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();

        foreach ($file->getClasses() as $classInfo) {
            if ($classInfo->hasAttribute(AsAuthHandler::class)) {
                $attr = $classInfo->getAttribute(AsAuthHandler::class);
                $instance = $attr?->newInstance();

                $result->addNode(new Node(
                    id:       NodeId::forClass($classInfo->fqcn),
                    type:     NodeType::AuthHandler,
                    fqcn:     $classInfo->fqcn,
                    file:     $file->path,
                    line:     $classInfo->startLine,
                    endLine:  $classInfo->endLine,
                    module:   $file->module,
                    metadata: [
                        'priority' => $instance?->priority ?? 0,
                    ],
                ));

                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'auth:handler',
                    type:     EdgeType::Authenticates,
                    metadata: [],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresPermission::class) as $permAttr) {
                $perm = $permAttr->newInstance();
                $slug = $perm->permission ?? '';
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'permission:' . $slug,
                    type:     EdgeType::RequiresPermission,
                    metadata: ['slug' => $slug],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresCapability::class) as $capAttr) {
                $cap = $capAttr->newInstance();
                $capability = $cap->capability ?? null;
                $slug = $capability instanceof \BackedEnum ? (string) $capability->value : '';
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'capability:' . $slug,
                    type:     EdgeType::RequiresCapability,
                    metadata: ['slug' => $slug],
                ));
            }

            if ($classInfo->hasAttribute(AsPublicPayload::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'accessType', 'public');
            } elseif ($classInfo->hasAttribute(AsServicePayload::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'accessType', 'service');
            }
        }

        return $result;
    }
}
