<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor\Attribute;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractionResult;
use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorInterface;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Parser\ParsedFile;
use Semitexa\ProjectGraph\Domain\Model\Edge;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The business domain a module's classes belong to, inferred from its name.
 *
 * Keywords used to match as SUBSTRINGS of the module name (measured
 * 2026-10-02 on the workspace): local module EventsDemo became
 * domain:Ledger ("event"), AuthDemo merged into the real domain:Auth and
 * CmsDemo into Content, so a demo's classes counted as the product domain's
 * — with its criticality; Authorization became Auth ("auth" in it). Now:
 *  1. a module named exactly like a domain is that domain;
 *  2. a local module (a file under src/modules/<Name>/) is its own module,
 *     so it is its own domain — and so is any module named *Demo, *Harness,
 *     *Playground or *Probe: it exercises a domain, it is not part of it;
 *  3. otherwise a keyword must be a whole WORD of the name (StudlyCase,
 *     kebab or snake split), or that word's plural; a keyword ending in "*"
 *     is a stem and matches as a word prefix (propagat*: Propagation);
 *  4. otherwise the module is its own domain.
 */
final class DomainContextExtractor implements ExtractorInterface
{
    private const OWN_DOMAIN_SUFFIXES = ['Demo', 'Harness', 'Playground', 'Probe'];

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
        'Ledger' => ['ledger', 'event', 'propagat*', 'replay', 'sequence'],
        'Cache' => ['cache', 'redis', 'ttl', 'invalidat*'],
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

        $domainName = $this->inferDomainName($module, $file->path);
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

    private function inferDomainName(string $module, string $path): ?string
    {
        foreach (array_keys(self::DOMAIN_KEYWORDS) as $domain) {
            if (strcasecmp($domain, $module) === 0) {
                return $domain;
            }
        }

        $own = ucwords(str_replace(['-', '_'], ' ', $module));
        $own = str_replace(' ', '', $own);
        if (str_contains(str_replace('\\', '/', $path), '/src/modules/' . $module . '/')) {
            return $own !== '' ? $own : null;
        }
        foreach (self::OWN_DOMAIN_SUFFIXES as $suffix) {
            if (str_ends_with($module, $suffix)) {
                return $own;
            }
        }

        $words = array_map('strtolower', preg_split('/[-_\s]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', $module) ?: []);
        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $keyword) {
                foreach ($words as $word) {
                    if (str_ends_with($keyword, '*')
                        ? str_starts_with($word, rtrim($keyword, '*'))
                        : $word === $keyword || $word === $keyword . 's' || $word === $keyword . 'es'
                    ) {
                        return $domain;
                    }
                }
            }
        }

        return $own !== '' ? $own : null;
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
