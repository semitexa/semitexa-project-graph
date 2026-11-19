<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\UiFlow;

use Semitexa\ProjectGraph\Application\Service\Extractor\TemplateNamespaces;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The UI flow, read out of the edges the extractors already wrote
 * (ep-ui-flow-graph): which pages there are, and where a visitor can go or
 * post from each.
 *
 * Read at query time, like FlowTracer and for its reason: a page's ways span
 * its payload, its resource, its template, the layout above it and every
 * route those name, each in a file of its own. Stored, the answer would be
 * stale as soon as one of them changed.
 *
 * A page is a GET payload whose resource renders a template. What the page
 * shows is that template and everything under `renders_template` from it:
 * the layout it extends, what it includes and embeds, the templates of the
 * components it calls. A template is named in the graph as it is written
 * (`template:@Shop/layout.html.twig`); which file that is, is asked of the
 * disk on each read ({@see TemplateNamespaces}), so a partial added or
 * deleted, or a module that came or went, changes the answer without any
 * file being re-indexed. A link belongs to the template that writes it and so
 * to every page that renders that template: a link in a layout is a way out
 * of each page under the layout, not of a file.
 *
 * Every way says how far it can be trusted. `exact`: the path is a declared
 * route. `pattern`: it fits a route with parameters. `unresolved`: Twig
 * computes the target, or no route answers to it; `to` is then null, `why`
 * says which, and nothing is guessed in its place.
 */
final class UiFlowProjection
{
    public const LAYER_NAVIGATION = 'navigation';
    public const LAYER_ACTION = 'action';

    /** @var array<string, array{id: string, fqcn: string, file: string, line: int, module: string, path: string, methods: list<string>, name: string, metadata: array<string, mixed>}>|null */
    private ?array $payloads = null;

    private ?RouteMatcher $matcher = null;

    /** @var array<string, list<string>>|null source => targets of renders_template */
    private ?array $renders = null;

    /** @var array<string, string>|null component name => class id */
    private ?array $components = null;

    private ?TemplateNamespaces $namespaces = null;

    /** @var array<string, ?string> template name => file node id, null when no file answers */
    private array $files = [];

    /** @var list<array{kind: string, layer: string, source: string, confidence: string, why: ?string, from: string, template: string, method: string, href: string, to: ?string, route: ?string, at: string, line: int, module: string}>|null */
    private ?array $templateEdges = null;

    private readonly string $root;

    public function __construct(
        private readonly GraphStorage $storage,
    ) {
        $root = (string) $storage->getMeta('project_root');
        $this->root = $root !== '' ? rtrim($root, '/') . '/' : '';
    }

    /**
     * Every routable payload the graph declares, with what its attribute says.
     *
     * @return array<string, array{id: string, fqcn: string, file: string, line: int, module: string, path: string, methods: list<string>, name: string, metadata: array<string, mixed>}>
     */
    public function payloads(): array
    {
        if ($this->payloads !== null) {
            return $this->payloads;
        }
        $this->payloads = [];
        foreach ($this->storage->nodes->findByType(NodeType::Payload->value) as $node) {
            if ($node->getIsPlaceholder()) {
                continue;
            }
            /** @var array<string, mixed> $metadata */
            $metadata = $node->getMetadata();
            $methods = [];
            foreach (is_array($metadata['methods'] ?? null) ? $metadata['methods'] : [] as $method) {
                if (is_string($method)) {
                    $methods[] = strtoupper($method);
                }
            }
            $this->payloads[$node->getId()] = [
                'id' => $node->getId(),
                'fqcn' => $node->getFqcn(),
                'file' => $node->getFile(),
                'line' => $node->getLine(),
                'module' => $node->getModule(),
                'path' => is_string($metadata['path'] ?? null) ? RouteMatcher::declaredPath($metadata['path']) : '',
                'methods' => $methods,
                'name' => is_string($metadata['name'] ?? null) ? $metadata['name'] : '',
                'metadata' => $metadata,
            ];
        }
        ksort($this->payloads);

        return $this->payloads;
    }

    public function matcher(): RouteMatcher
    {
        if ($this->matcher !== null) {
            return $this->matcher;
        }
        $routes = [];
        foreach ($this->payloads() as $payload) {
            foreach ($payload['methods'] as $method) {
                $routes[] = ['method' => $method, 'path' => $payload['path'], 'name' => $payload['name'], 'payload' => $payload['id']];
            }
        }

        return $this->matcher = new RouteMatcher($routes);
    }

    /**
     * The pages: a GET payload, the resource it answers with, and every
     * template that resource renders, directly or through another.
     *
     * @return list<array{id: string, path: string, name: string, module: string, resource: string, templates: list<string>}>
     */
    public function pages(): array
    {
        $resources = [];
        foreach ($this->storage->edges->findByType(EdgeType::Handles) as $handles) {
            foreach ($this->storage->edges->findBySource($handles->getSourceId(), EdgeType::Produces) as $produces) {
                $resources[$handles->getTargetId()][] = $produces->getTargetId();
            }
        }

        $pages = [];
        foreach ($this->payloads() as $payload) {
            if ($payload['path'] === '' || !in_array('GET', $payload['methods'], true)) {
                continue;
            }
            $declared = $payload['metadata']['responseClass'] ?? null;
            $candidates = is_string($declared) && $declared !== '' ? ['class:' . ltrim($declared, '\\')] : [];
            foreach (array_unique([...$candidates, ...($resources[$payload['id']] ?? [])]) as $resource) {
                $templates = $this->templatesUnder($resource);
                if ($templates === []) {
                    continue;
                }
                $pages[] = [
                    'id' => $payload['id'],
                    'path' => $payload['path'],
                    'name' => $payload['name'],
                    'module' => $payload['module'],
                    'resource' => $resource,
                    'templates' => $templates,
                ];
                break;
            }
        }

        return $pages;
    }

    /**
     * Where a visitor of this page can go or post: the ways of every template
     * the page renders, each now leaving the page.
     *
     * @return list<array{kind: string, layer: string, source: string, confidence: string, why: ?string, from: string, template: string, method: string, href: string, to: ?string, route: ?string, at: string, line: int, module: string}>
     */
    public function edgesOf(string $pageId): array
    {
        $edges = [];
        foreach ($this->pages() as $page) {
            if ($page['id'] !== $pageId) {
                continue;
            }
            $shown = array_fill_keys($page['templates'], true);
            foreach ($this->templateEdges() as $edge) {
                if (isset($shown[$edge['template']])) {
                    $edges[] = ['from' => $pageId] + $edge;
                }
            }
        }

        return $edges;
    }

    /**
     * Every link and form of every template, once, leaving the template that
     * writes it: what a route table check reads, whether or not a page
     * renders the template.
     *
     * @return list<array{kind: string, layer: string, source: string, confidence: string, why: ?string, from: string, template: string, method: string, href: string, to: ?string, route: ?string, at: string, line: int, module: string}>
     */
    public function templateEdges(): array
    {
        if ($this->templateEdges !== null) {
            return $this->templateEdges;
        }
        $matcher = $this->matcher();
        $edges = [];
        $templates = [];
        foreach ($this->storage->nodes->findByType(NodeType::File->value) as $node) {
            if (($node->getMetadata()['template'] ?? false) === true && !$node->getIsPlaceholder()) {
                $templates[$node->getId()] = $node;
            }
        }
        ksort($templates);

        foreach ([EdgeType::NavigatesTo, EdgeType::SubmitsTo] as $type) {
            foreach ($this->storage->edges->findByType($type) as $edge) {
                $template = $templates[$edge->getSourceId()] ?? null;
                if ($template === null || preg_match('/\Aroute:([A-Z]+):(.*)\z/s', $edge->getTargetId(), $m) !== 1) {
                    continue;
                }
                $metadata = $edge->getMetadata();
                $hrefs = array_values(array_filter(is_array($metadata['hrefs'] ?? null) ? $metadata['hrefs'] : [], 'is_string'));
                $lines = array_values(is_array($metadata['lines'] ?? null) ? $metadata['lines'] : []);
                $match = $matcher->match($m[1], $m[2]);
                foreach ($lines as $i => $line) {
                    $edges[] = $this->edge(
                        $template,
                        $type === EdgeType::SubmitsTo ? 'submit' : 'link',
                        $m[1],
                        // One href per line when every line wrote its own; else the path they share.
                        count($hrefs) === count($lines) ? $hrefs[$i] : $m[2],
                        is_int($line) ? $line : 0,
                        $match,
                        'no route of that path',
                    );
                }
            }
        }
        foreach ($templates as $template) {
            $metadata = $template->getMetadata();
            foreach (is_array($metadata['named_links'] ?? null) ? $metadata['named_links'] : [] as $named) {
                if (!is_array($named) || !is_string($named['route'] ?? null)) {
                    continue;
                }
                [$kind, $method] = self::wayOf($named);
                // The route of that name AND that method: a form posting to a
                // name that only answers GET goes nowhere, and is not drawn
                // as if it did.
                $match = $matcher->byName($named['route'], $method);
                $whyNot = $matcher->byName($named['route']) === null ? 'no route of that name' : 'the route of that name does not take ' . $method;
                $edges[] = $this->edge($template, $kind, $method, "url('" . $named['route'] . "')", is_int($named['line'] ?? null) ? $named['line'] : 0, $match, $whyNot);
            }
            foreach (is_array($metadata['unresolved_links'] ?? null) ? $metadata['unresolved_links'] : [] as $computed) {
                if (!is_array($computed) || !is_string($computed['href'] ?? null)) {
                    continue;
                }
                [$kind, $method] = self::wayOf($computed);
                $edges[] = $this->edge($template, $kind, $method, $computed['href'], is_int($computed['line'] ?? null) ? $computed['line'] : 0, null, 'computed');
            }
        }
        usort($edges, static fn (array $a, array $b): int => [$a['at'], $a['href']] <=> [$b['at'], $b['href']]);

        return $this->templateEdges = $edges;
    }

    /**
     * The payloads a visitor's action is known to reach: the target of a form
     * some template writes, or the class behind a named UI action (a form
     * submit, a grid or tree action) or the payload that class handles.
     *
     * A form in a test template reaches nothing a visitor can: given what
     * test code is, its forms are left out, as the report leaves out its
     * links and its payloads.
     *
     * @return array<string, true> payload id => true
     */
    public function submitted(?TestCode $tests = null): array
    {
        // Two payloads may declare one route (an override and what it
        // overrides): the form reaches the route, so both.
        $declaring = [];
        foreach ($this->payloads() as $payload) {
            foreach ($payload['methods'] as $method) {
                $declaring[$method . ' ' . rtrim($payload['path'], '/')][] = $payload['id'];
            }
        }
        $reached = [];
        foreach ($this->templateEdges() as $edge) {
            if ($edge['kind'] !== 'submit' || $edge['to'] === null) {
                continue;
            }
            if ($tests !== null && $tests->contains(substr($edge['template'], strlen('file:')))) {
                continue;
            }
            $reached[$edge['to']] = true;
            foreach ($declaring[rtrim((string) $edge['route'], '/')] ?? [] as $id) {
                $reached[$id] = true;
            }
        }
        foreach ($this->storage->edges->findByType(EdgeType::ImplementedBy) as $edge) {
            if (!str_starts_with($edge->getSourceId(), 'uiaction:')) {
                continue;
            }
            // A UI action declared in test code reaches nothing a visitor can
            // use, as a form in a test template does not (review, project-graph#38).
            if ($tests !== null) {
                $action = $this->storage->nodes->findById($edge->getSourceId());
                if ($action !== null && $action->getFile() !== '' && $tests->contains($action->getFile())) {
                    continue;
                }
            }
            $reached[$edge->getTargetId()] = true;
            foreach ($this->storage->edges->findBySource($edge->getTargetId(), EdgeType::Handles) as $handles) {
                $reached[$handles->getTargetId()] = true;
            }
        }

        return array_intersect_key($reached, $this->payloads());
    }

    /**
     * What names a template the graph cannot follow, and why: a name Twig
     * computes, a template Twig cannot read, a name without a namespace, a
     * namespace or a file that is not there now.
     *
     * @return list<array{from: string, kind: string, name: string, reason: string, at: string}>
     */
    public function unresolvedTemplates(): array
    {
        $unresolved = [];
        foreach ($this->storage->edges->findByType(EdgeType::RendersTemplate) as $edge) {
            $target = $edge->getTargetId();
            if (!str_starts_with($target, 'template:') || $this->fileOf($target) !== null) {
                continue;
            }
            $metadata = $edge->getMetadata();
            $name = substr($target, strlen('template:'));
            $source = $this->storage->nodes->findById($edge->getSourceId());
            $kinds = array_values(array_filter(is_array($metadata['kinds'] ?? null) ? $metadata['kinds'] : [], 'is_string'));
            foreach (is_array($metadata['lines'] ?? null) ? $metadata['lines'] : [] as $i => $line) {
                $unresolved[] = [
                    'from' => $edge->getSourceId(),
                    'kind' => $kinds[$i] ?? $kinds[0] ?? 'include',
                    'name' => $name,
                    'reason' => preg_match('#\A@[^/]+/.#', $name) === 1 ? 'no such namespace or file' : 'no namespace',
                    'at' => $this->relative($source?->getFile() ?? '') . ':' . (is_int($line) ? $line : 0),
                ];
            }
        }
        $holders = [
            ...$this->storage->nodes->findByType(NodeType::File->value),
            ...$this->storage->nodes->findByType(NodeType::Resource->value),
            ...$this->storage->nodes->findByType(NodeType::Component->value),
        ];
        foreach ($holders as $node) {
            $listed = $node->getMetadata()['unresolved_templates'] ?? null;
            foreach (is_array($listed) ? $listed : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $unresolved[] = [
                    'from' => $node->getId(),
                    'kind' => is_string($entry['kind'] ?? null) ? $entry['kind'] : '',
                    'name' => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                    'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : '',
                    'at' => $this->relative($node->getFile()) . ':' . (is_int($entry['line'] ?? null) ? $entry['line'] : 0),
                ];
            }
        }
        usort($unresolved, static fn (array $a, array $b): int => [$a['at'], $a['name']] <=> [$b['at'], $b['name']]);

        return $unresolved;
    }

    /** A graph file path as the project names it. */
    public function relative(string $file): string
    {
        return $this->root !== '' && str_starts_with($file, $this->root) ? substr($file, strlen($this->root)) : $file;
    }

    /**
     * Every template file reachable from a class or a template along
     * `renders_template`, the start excluded unless something renders it back.
     *
     * @return list<string> file node ids, sorted
     */
    private function templatesUnder(string $start): array
    {
        if ($this->renders === null) {
            $this->renders = [];
            foreach ($this->storage->edges->findByType(EdgeType::RendersTemplate) as $edge) {
                $this->renders[$edge->getSourceId()][] = $edge->getTargetId();
            }
            $this->components = [];
            foreach ($this->storage->nodes->findByType(NodeType::Component->value) as $node) {
                $name = $node->getMetadata()['component'] ?? null;
                if (is_string($name) && !$node->getIsPlaceholder()) {
                    $this->components[$name] = $node->getId();
                }
            }
        }
        $found = [];
        $seen = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            foreach ($this->renders[array_shift($queue)] ?? [] as $target) {
                // A component is a name; its class says which template it is.
                // A template is a name too; the disk says which file it is now.
                if (str_starts_with($target, 'component:')) {
                    $target = $this->components[substr($target, strlen('component:'))] ?? null;
                } elseif (str_starts_with($target, 'template:')) {
                    $target = $this->fileOf($target);
                }
                if ($target === null || isset($seen[$target])) {
                    continue;
                }
                $seen[$target] = true;
                $queue[] = $target;
                if (str_starts_with($target, 'file:')) {
                    $found[] = $target;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The kind and method of a named or computed way, as a literal one has
     * them: a link is a GET; a form is what its method says, a GET form a
     * navigation like any link, and a form whose method Twig computes is
     * taken for the POST a form usually is.
     *
     * @param array<mixed> $entry
     * @return array{string, string}
     */
    private static function wayOf(array $entry): array
    {
        if (($entry['kind'] ?? '') !== 'submit') {
            return ['link', 'GET'];
        }

        return ($entry['method'] ?? null) === 'GET' ? ['link', 'GET'] : ['submit', 'POST'];
    }

    /** The file node a `template:<name>` stands for today, or null. */
    private function fileOf(string $templateId): ?string
    {
        if (!array_key_exists($templateId, $this->files)) {
            // Without the root the graph was built from, no name can be placed.
            $this->namespaces ??= $this->root !== '' ? TemplateNamespaces::ofProject($this->root) : null;
            $file = $this->namespaces?->fileOf(substr($templateId, strlen('template:')));
            $this->files[$templateId] = $file !== null ? 'file:' . $file : null;
        }

        return $this->files[$templateId];
    }

    /**
     * @param array{confidence: string, route: array{method: string, path: string, name: string, payload: string}, locale: ?string}|null $match
     * @return array{kind: string, layer: string, source: string, confidence: string, why: ?string, from: string, template: string, method: string, href: string, to: ?string, route: ?string, at: string, line: int, module: string}
     */
    private function edge(Node $template, string $kind, string $method, string $href, int $line, ?array $match, string $whyNot): array
    {
        return [
            'kind' => $kind,
            'layer' => $kind === 'submit' ? self::LAYER_ACTION : self::LAYER_NAVIGATION,
            'source' => 'template',
            'confidence' => $match['confidence'] ?? RouteMatcher::UNRESOLVED,
            'why' => $match === null ? $whyNot : null,
            'from' => $template->getId(),
            'template' => $template->getId(),
            'method' => $method,
            'href' => $href,
            'to' => $match['route']['payload'] ?? null,
            'route' => $match !== null ? $match['route']['method'] . ' ' . $match['route']['path'] : null,
            'at' => $this->relative($template->getFile()) . ':' . $line,
            'line' => $line,
            'module' => $template->getModule(),
        ];
    }
}
