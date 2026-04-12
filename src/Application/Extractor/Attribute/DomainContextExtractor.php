<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Extractor\Attribute;

use Semitexa\Ledger\Attribute\Propagated;
use Semitexa\ProjectGraph\Application\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

final class DomainContextExtractor implements ExtractorInterface
{
    private const DOMAIN_KEYWORDS = [
        'Auth' => ['auth', 'login', 'register', 'permission', 'capability', 'rbac'],
        'Billing' => ['billing', 'invoice', 'payment', 'subscription', 'pricing'],
        'Inventory' => ['inventory', 'stock', 'product', 'warehouse', 'sku'],
        'Ordering' => ['order', 'cart', 'checkout', 'fulfillment', 'shipping'],
        'Notification' => ['notification', 'email', 'sms', 'push', 'alert'],
        'Media' => ['media', 'image', 'video', 'upload', 'storage', 'asset'],
        'Search' => ['search', 'index', 'query', 'filter', 'facet'],
        'Analytics' => ['analytics', 'metric', 'report', 'dashboard', 'tracking'],
        'User' => ['user', 'profile', 'account', 'preference', 'avatar'],
        'Content' => ['content', 'page', 'article', 'post', 'cms', 'block'],
        'Tenancy' => ['tenant', 'organization', 'workspace', 'team'],
        'Workflow' => ['workflow', 'process', 'approval', 'state', 'transition'],
        'Scheduler' => ['schedule', 'cron', 'job', 'task', 'timer'],
        'Ledger' => ['ledger', 'event', 'propagat', 'replay', 'sequence'],
        'Cache' => ['cache', 'redis', 'ttl', 'invalidat'],
        'Locale' => ['locale', 'language', 'translation', 'i18n', 'l10n'],
    ];

    public function supports(ParsedFile $file): bool
    {
        return true;
    }

    public function extract(ParsedFile $file): ExtractionResult
    {
        $result = new ExtractionResult();
        $module = $file->module;

        if ($module === '' || $module === 'App') {
            return $result;
        }

        $domainName = $this->inferDomainName($module);
        if ($domainName === null) {
            return $result;
        }

        $domainId = NodeId::forDomain($domainName);
        $description = $this->generateDescription($domainName, $file);

        $classes = $file->getClasses();
        $entityClasses = [];
        foreach ($classes as $class) {
            $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';
            foreach (['Entity', 'Model', 'Aggregate', 'Root'] as $suffix) {
                if (str_ends_with($shortName, $suffix)) {
                    $entityClasses[] = $shortName;
                    break;
                }
            }
        }

        $domainNode = new Node(
            id: $domainId,
            type: NodeType::DomainContext,
            fqcn: '',
            file: $file->path,
            line: 1,
            endLine: 1,
            module: $module,
            metadata: [
                'name' => $domainName,
                'description' => $description,
                'criticality' => $this->assessCriticality($domainName, $classes),
                'key_entities' => array_unique($entityClasses),
                'inferred_from' => ['module_name', 'namespace_patterns'],
            ],
        );
        $result->addNode($domainNode);

        foreach ($classes as $class) {
            $classId = NodeId::forClass($class->fqcn);
            $result->addEdge(new Edge(
                sourceId: $classId,
                targetId: $domainId,
                type: EdgeType::BelongsToDomain,
            ));
        }

        return $result;
    }

    private function inferDomainName(string $module): ?string
    {
        $normalized = str_replace(['-', '_'], ' ', $module);
        $words = explode(' ', $normalized);

        foreach ($words as $word) {
            $title = ucfirst(strtolower($word));
            if (isset(self::DOMAIN_KEYWORDS[$title])) {
                return $title;
            }
        }

        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($module, $keyword) !== false) {
                    return $domain;
                }
            }
        }

        $title = ucwords(str_replace(['-', '_'], ' ', $module));
        return $title !== '' ? $title : null;
    }

    private function generateDescription(string $domainName, ParsedFile $file): string
    {
        $classes = $file->getClasses();
        $hasHandler = false;
        $hasEvent = false;
        $hasEntity = false;

        foreach ($classes as $class) {
            $shortName = array_slice(explode('\\', $class->fqcn), -1)[0] ?? '';
            if (str_ends_with($shortName, 'Handler')) $hasHandler = true;
            if (str_ends_with($shortName, 'Event')) $hasEvent = true;
            if (str_ends_with($shortName, 'Entity') || str_ends_with($shortName, 'Model')) $hasEntity = true;
        }

        $parts = ["Manages {$domainName} domain"];
        if ($hasHandler) $parts[] = 'with request handlers';
        if ($hasEvent) $parts[] = 'event-driven flows';
        if ($hasEntity) $parts[] = 'data entities';

        return implode(' ', $parts) . '.';
    }

    private function assessCriticality(string $domainName, array $classes): string
    {
        $critical = ['Auth', 'Billing', 'Ordering', 'Tenancy', 'Ledger'];
        if (in_array($domainName, $critical, true)) {
            return 'high';
        }

        foreach ($classes as $class) {
            foreach ($class->attributes as $attr) {
                if (str_contains($attr->getName(), 'Propagated') || str_contains($attr->getName(), 'OwnedAggregate')) {
                    return 'high';
                }
            }
        }

        return 'medium';
    }
}
