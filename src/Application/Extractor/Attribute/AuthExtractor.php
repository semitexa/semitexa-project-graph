<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Core\Attribute\AsAuthHandler;
use Semitexa\Core\Attribute\AuthLevel;
use Semitexa\Core\Attribute\PublicEndpoint;
use Semitexa\Core\Attribute\RequiresCapability;
use Semitexa\Core\Attribute\RequiresPermission;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Node;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;

final class AuthExtractor implements ExtractorInterface
{
    public function supports(ParsedFile $file): bool
    {
        return $file->hasAttribute(AsAuthHandler::class)
            || $file->hasAttribute(RequiresPermission::class)
            || $file->hasAttribute(RequiresCapability::class)
            || $file->hasAttribute(PublicEndpoint::class);
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

                if ($classInfo->hasAttribute(AuthLevel::class)) {
                    $levelAttr = $classInfo->getAttribute(AuthLevel::class);
                    $levelInstance = $levelAttr?->newInstance();
                    $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'authLevel', $levelInstance);
                }

                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'auth:handler',
                    type:     EdgeType::Authenticates,
                    metadata: [],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresPermission::class) as $permAttr) {
                $perm = $permAttr->newInstance();
                $slug = $perm->slug ?? '';
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'permission:' . $slug,
                    type:     EdgeType::RequiresPermission,
                    metadata: ['slug' => $slug],
                ));
            }

            foreach ($classInfo->getAttributes(RequiresCapability::class) as $capAttr) {
                $cap = $capAttr->newInstance();
                $slug = $cap->slug ?? '';
                $result->addEdge(new Edge(
                    sourceId: NodeId::forClass($classInfo->fqcn),
                    targetId: 'capability:' . $slug,
                    type:     EdgeType::RequiresCapability,
                    metadata: ['slug' => $slug],
                ));
            }

            if ($classInfo->hasAttribute(PublicEndpoint::class)) {
                $result->addNodeMetadata(NodeId::forClass($classInfo->fqcn), 'public', true);
            }
        }

        return $result;
    }
}
