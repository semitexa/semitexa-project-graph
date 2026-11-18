<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Authorization\Attribute\AsServicePayload;
use Semitexa\Authorization\Attribute\RequiresCapability;
use Semitexa\Authorization\Attribute\RequiresPermission;
use Semitexa\Core\Attribute\AbstractPayloadRoute;
use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\RequiresSignedUrl;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Extractor\SafeAttributeResolver;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ClassInfo;
use Semitexa\ProjectGraph\Application\Service\Parser\EnumCaseReference;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Attribute\UiEntryPoint;
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
        AsPublicPayload::class                      => 'public',
        AsProtectedPayload::class                   => 'protected',
        AsServicePayload::class                     => 'service',
        'Semitexa\Core\Attribute\AsPayload'         => 'protected',
        // Routes declared by an AbstractPayloadRoute subclass of another
        // package: a CRUD screen, a settings page, a collection feed. By name,
        // so the graph does not require semitexa/crud; missing, they were not
        // in the graph at all.
        'Semitexa\Crud\Attribute\AsCrud'           => 'protected',
        'Semitexa\Crud\Attribute\AsSettingsPage'   => 'protected',
        // A feed is Protected unless it says Public: its attribute's own access
        // is read in extract(); this is only the fallback.
        'Semitexa\Crud\Attribute\AsCollectionFeed' => 'protected',
        // Since crud#13 a feed's attribute name says who may read it.
        'Semitexa\Crud\Attribute\AsProtectedFeed'  => 'protected',
        'Semitexa\Crud\Attribute\AsPublicFeed'     => 'public',
    ];

    /**
     * The access the attribute says (a feed chooses Public or Protected), the
     * class default only when it says none. An enum argument the parser cannot
     * construct gives a stand-in, which still carries the `access` it was given.
     */
    private static function accessOf(object $instance, string $default): string
    {
        if ($instance instanceof AbstractPayloadRoute) {
            return $instance->getAccessType()->value;
        }
        $access = EnumCaseReference::scalarOf($instance->access ?? null);

        return is_string($access) && $access !== '' ? strtolower($access) : $default;
    }

    /**
     * What the UI flow reads to tell a page from an API and a form's target
     * from a machine's (UiFlowGapReport): only what the attribute states, each
     * key left out when it states nothing. `produces` above falls back to
     * text/html, so what was declared is kept apart.
     *
     * @return array<string, mixed>
     */
    private static function uiFlowFacts(ClassInfo $class, object $instance): array
    {
        $strings = static fn (mixed $value): array => array_values(array_filter(
            array_map(static fn (mixed $item): mixed => EnumCaseReference::scalarOf($item), is_array($value) ? $value : [$value]),
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
        $facts = [
            'consumes'         => $strings($instance->consumes ?? null),
            'producesDeclared' => $strings($instance->produces ?? null),
            'renderProfile'    => $strings($instance->renderProfile ?? null),
            'transport'        => $strings($instance->transport ?? null)[0] ?? null,
            'signedUrl'        => $class->hasAttribute(RequiresSignedUrl::class) ?: null,
        ];
        $entry = $class->getAttribute(UiEntryPoint::class);
        if ($entry !== null) {
            try {
                $reason = $entry->getArguments()['reason'] ?? $entry->getArguments()[0] ?? '';
            } catch (\Throwable) {
                $reason = '';
            }
            $facts['uiEntryPoint'] = is_string($reason) ? $reason : '';
        }

        return array_filter($facts, static fn (mixed $fact): bool => $fact !== null && $fact !== []);
    }

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
                        'name'          => is_string($instance->name ?? null) ? $instance->name : '',
                        'methods'       => $instance->methods ?? ['GET'],
                        'responseClass' => $instance->responseWith ?? null,
                        'produces'      => $instance->produces ?? ['text/html'],
                        'accessType'    => self::accessOf($instance, $accessType),
                    ] + self::uiFlowFacts($classInfo, $instance),
                );
                $result->addNode($payloadNode);

                // A payload addressed by name over HUG/KISS has no path: it made
                // route:GET: — an empty route no request can match (2026-10-16).
                $methods = ($instance->path ?? '') !== '' ? ($instance->methods ?? ['GET']) : [];
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
                        sourceId: $payloadNode->getId(),
                        targetId: $routeNode->getId(),
                        type:     EdgeType::ServesRoute,
                        metadata: [],
                    ));
                }

                foreach ($classInfo->usedTraits as $traitFqcn) {
                    $result->addEdge(new Edge(
                        sourceId: $payloadNode->getId(),
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
                            sourceId: $payloadNode->getId(),
                            targetId: 'permission:' . $slug,
                            type:     EdgeType::RequiresPermission,
                            metadata: ['slug' => $slug],
                        ));
                    }
                }

                foreach ($classInfo->getAttributes(RequiresCapability::class) as $cap) {
                    // The capability is an enum case read from its AST (an
                    // EnumCaseReference): RequiresCapability's constructor wants
                    // a CapabilityInterface and refuses it, so the slug comes
                    // from the argument itself.
                    $args = $this->getAttributeArguments($cap);
                    $capability = $args['capability'] ?? $args[0] ?? null;
                    if ($capability === null) {
                        continue;
                    }
                    $slug = $capability instanceof EnumCaseReference || $capability instanceof \BackedEnum ? (string) $capability->value : '';
                    $result->addEdge(new Edge(
                        sourceId: $payloadNode->getId(),
                        targetId: 'capability:' . $slug,
                        type:     EdgeType::RequiresCapability,
                        metadata: ['slug' => $slug],
                    ));
                }
            }
        }

        return $result;
    }
}
