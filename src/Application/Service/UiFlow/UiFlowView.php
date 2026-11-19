<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\UiFlow;

use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * The UI flow as its readers ask for it (ep-ui-flow-graph, PR 2): the
 * Observatory's "UI flow" tab and `ai:ask ui-flow` read this one model, so a
 * person and an agent are told the same thing.
 *
 * Three questions, each a plain array:
 *  - {@see overview()}: which modules have screens, and how many;
 *  - {@see module()}: one module's screens with their navigation depth, the
 *    wires between them, and every other module it touches as one ghost;
 *  - {@see screen()}: one screen's elements (its links and forms), one hop
 *    out and one hop in.
 *
 * A screen is a page of {@see UiFlowProjection}; an endpoint is a routable
 * payload a link or a form reaches that renders no template (a POST, a
 * redirect, a download). Everything carries `at`, the file and line it was
 * read from. A way whose target is not known is never drawn as a wire: it is
 * counted on its screen (`unresolved`) and listed among its elements with
 * the reason.
 *
 * Every answer starts with `status`. Anything but "ok" means the templates
 * could not be placed on this machine, so "no screens" is then not a fact
 * about the project: `message` says what to do.
 *
 * Screens declared in test code are left out, as the gap report leaves them.
 *
 * @phpstan-type Layers array<string, array{available: bool, on: bool}>
 * @phpstan-type Screen array{kind: 'screen', id: string, path: string, name: string, module: string, fqcn: string, resource: string, via: string, at: string, templates: int, elements: int, unresolved: int}
 * @phpstan-type ScreenAtDepth array{kind: 'screen', id: string, path: string, name: string, module: string, fqcn: string, resource: string, via: string, at: string, templates: int, elements: int, unresolved: int, depth: ?int}
 * @phpstan-type Endpoint array{kind: 'endpoint', id: string, path: string, name: string, module: string, fqcn: string, methods: list<string>, at: string}
 * @phpstan-type Ghost array{kind: 'ghost', id: string, module: string, screens: int, ways_out: int, ways_in: int}
 * @phpstan-type Element array{kind: string, layer: string, source: string, confidence: string, why: ?string, method: string, href: string, to: ?string, route: ?string, at: string, shared: bool}
 * @phpstan-type Wire array{from: string, to: string, layer: string, count: int, confidence: string, shared: bool, tree: bool, at: string}
 * @phpstan-type Hop array{node: Screen|Endpoint, count: int, layers: list<string>}
 * @phpstan-type ModuleRow array{module: string, screens: int, ways: int, unresolved: int}
 * @phpstan-type Overview array{status: string, message: ?string, project_root: string, layers: Layers, modules: list<ModuleRow>, totals: array{screens: int, ways: int, unresolved: int, unresolved_templates: int}}
 * @phpstan-type ModuleView array{status: string, message: ?string, project_root: string, module: string, layers: Layers, entry: string, screens: non-empty-list<ScreenAtDepth>, endpoints: list<Endpoint>, ghosts: list<Ghost>, wires: list<Wire>}
 * @phpstan-type ScreenView array{status: string, message: ?string, project_root: string, screen: Screen, templates: list<array{id: string, at: string}>, elements: list<Element>, out: list<Hop>, in: list<Hop>}
 */
final class UiFlowView
{
    /**
     * The name of the screens that belong to no module. An empty name could
     * not be asked for: `module=` and `--module=` without a value mean "the
     * overview". Parentheses cannot be in a real module's name.
     */
    public const NO_MODULE = '(none)';

    private readonly UiFlowProjection $flow;

    /** @var array<string, Screen>|null */
    private ?array $screens = null;

    /** @var array<string, list<Element>> screen id => its elements */
    private array $elements = [];

    /** @var array<string, list<string>> screen id => template file ids */
    private array $templates = [];

    public function __construct(
        GraphStorage $storage,
        private readonly ?TestCode $tests = null,
    ) {
        $this->flow = new UiFlowProjection($storage);
    }

    /** @return Overview */
    public function overview(): array
    {
        /** @var array<string, ModuleRow> $modules */
        $modules = [];
        $totals = ['screens' => 0, 'ways' => 0, 'unresolved' => 0, 'unresolved_templates' => count($this->flow->unresolvedTemplates())];
        foreach ($this->screens() as $screen) {
            $modules[$screen['module']] ??= ['module' => $screen['module'], 'screens' => 0, 'ways' => 0, 'unresolved' => 0];
            $modules[$screen['module']]['screens']++;
            $modules[$screen['module']]['ways'] += $screen['elements'];
            $modules[$screen['module']]['unresolved'] += $screen['unresolved'];
            $totals['screens']++;
            $totals['ways'] += $screen['elements'];
            $totals['unresolved'] += $screen['unresolved'];
        }
        ksort($modules);

        return $this->status() + [
            'layers' => self::layers(),
            'modules' => array_values($modules),
            'totals' => $totals,
        ];
    }

    /**
     * One module: its screens in columns of navigation depth, the endpoints
     * its forms and links reach, and one ghost per other module on either
     * side of a way.
     *
     * Depth is counted from the module's entry, the screen with the shortest
     * path, along navigation wires inside the module, in their direction; a
     * screen no such wire reaches has depth null, however many of its own
     * links lead back to the entry. A layout's links count like any other: a
     * navigation bar is a real way to a screen. The wire that first reaches
     * a screen is marked `tree`, so a reader can show why the screen stands
     * at its depth.
     *
     * @return ModuleView|null null when the module has no screen
     */
    public function module(string $module): ?array
    {
        $screens = array_filter($this->screens(), static fn (array $screen): bool => $screen['module'] === $module);
        if ($screens === []) {
            return null;
        }

        /** @var array<string, Endpoint> $endpoints */
        $endpoints = [];
        /** @var array<string, Ghost> $ghosts */
        $ghosts = [];
        /** @var array<string, Wire> $wires */
        $wires = [];
        foreach ($this->screens() as $id => $from) {
            $inside = $from['module'] === $module;
            foreach ($this->elements[$id] as $element) {
                if ($element['to'] === null) {
                    continue;
                }
                $target = $this->node($element['to']);
                $targetInside = $target['module'] === $module;
                if (!$inside && !$targetInside) {
                    continue;
                }
                if ($inside && $targetInside) {
                    $to = $target['id'];
                    if ($target['kind'] === 'endpoint') {
                        $endpoints[$to] = $target;
                    }
                } else {
                    $other = $inside ? $target['module'] : $from['module'];
                    $ghosts[$other] ??= ['kind' => 'ghost', 'id' => 'module:' . $other, 'module' => $other, 'screens' => 0, 'ways_out' => 0, 'ways_in' => 0];
                    if (!$inside) {
                        // A way in from another module: counted on its ghost, and
                        // listed on the screen it enters (screen()), not drawn.
                        $ghosts[$other]['ways_in']++;
                        continue;
                    }
                    $ghosts[$other]['ways_out']++;
                    $to = $ghosts[$other]['id'];
                }
                if ($to === $id) {
                    continue;
                }
                $key = $id . "\0" . $to . "\0" . $element['layer'];
                $wires[$key] ??= ['from' => $id, 'to' => $to, 'layer' => $element['layer'], 'count' => 0, 'confidence' => $element['confidence'], 'shared' => true, 'tree' => false, 'at' => $element['at']];
                $wires[$key]['count']++;
                $wires[$key]['shared'] = $wires[$key]['shared'] && $element['shared'];
                if ($element['confidence'] === RouteMatcher::EXACT) {
                    $wires[$key]['confidence'] = RouteMatcher::EXACT;
                }
            }
        }
        foreach ($this->screens() as $screen) {
            if (isset($ghosts[$screen['module']])) {
                $ghosts[$screen['module']]['screens']++;
            }
        }
        ksort($ghosts);
        ksort($endpoints);
        ksort($wires);
        $wires = array_values($wires);

        [$depths, $tree] = self::depths($screens, $wires);
        foreach ($wires as $i => $wire) {
            $wires[$i]['tree'] = $wire['layer'] === UiFlowProjection::LAYER_NAVIGATION && ($tree[$wire['to']] ?? null) === $wire['from'];
        }
        $rows = [];
        foreach ($screens as $id => $screen) {
            $rows[] = $screen + ['depth' => $depths[$id] ?? null];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['depth'] ?? PHP_INT_MAX, $a['path'], $a['id']] <=> [$b['depth'] ?? PHP_INT_MAX, $b['path'], $b['id']]);

        return $this->status() + [
            'module' => $module,
            'layers' => self::layers(),
            'entry' => $rows[0]['id'],
            'screens' => $rows,
            'endpoints' => array_values($endpoints),
            'ghosts' => array_values($ghosts),
            'wires' => $wires,
        ];
    }

    /**
     * One screen: what it shows, every link and form on it, where they lead
     * (one hop out) and which screens lead here (one hop in).
     *
     * @return ScreenView|null null when no screen has that id
     */
    public function screen(string $id): ?array
    {
        $screen = $this->screens()[$id] ?? null;
        if ($screen === null) {
            return null;
        }

        /** @var array<string, array{node: Screen|Endpoint, count: int, layers: array<string, true>}> $out */
        $out = [];
        foreach ($this->elements[$id] as $element) {
            if ($element['to'] !== null && $element['to'] !== $id) {
                $out[$element['to']] ??= ['node' => $this->node($element['to']), 'count' => 0, 'layers' => []];
                $out[$element['to']]['count']++;
                $out[$element['to']]['layers'][$element['layer']] = true;
            }
        }
        /** @var array<string, array{node: Screen|Endpoint, count: int, layers: array<string, true>}> $in */
        $in = [];
        foreach ($this->screens() as $fromId => $from) {
            if ($fromId === $id) {
                continue;
            }
            foreach ($this->elements[$fromId] as $element) {
                if ($element['to'] === $id) {
                    $in[$fromId] ??= ['node' => $from, 'count' => 0, 'layers' => []];
                    $in[$fromId]['count']++;
                    $in[$fromId]['layers'][$element['layer']] = true;
                }
            }
        }
        $templates = [];
        foreach ($this->templates[$id] as $template) {
            $templates[] = ['id' => $template, 'at' => $this->flow->relative(substr($template, strlen('file:')))];
        }

        return $this->status() + [
            'screen' => $screen,
            'templates' => $templates,
            'elements' => $this->elements[$id],
            'out' => self::hops($out),
            'in' => self::hops($in),
        ];
    }

    /**
     * @param array<string, array{node: Screen|Endpoint, count: int, layers: array<string, true>}> $hops
     * @return list<Hop> by path
     */
    private static function hops(array $hops): array
    {
        $rows = [];
        foreach ($hops as $hop) {
            $rows[] = ['node' => $hop['node'], 'count' => $hop['count'], 'layers' => array_keys($hop['layers'])];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['node']['path'], $a['node']['id']] <=> [$b['node']['path'], $b['node']['id']]);

        return $rows;
    }

    /**
     * The screens a reader may mean: by node id (`class:App\Orders\ListPayload`)
     * or payload class name there is one; by path (`/orders`) there are as
     * many as payloads declare it, and two modules may each declare `/`.
     *
     * @return list<string> screen ids; empty when none answers
     */
    public function matches(string $reference): array
    {
        $screens = $this->screens();
        foreach ([$reference, 'class:' . ltrim($reference, '\\')] as $id) {
            if (isset($screens[$id])) {
                return [$id];
            }
        }
        $found = [];
        foreach ($screens as $id => $screen) {
            if ($screen['path'] === $reference) {
                $found[] = $id;
            }
        }

        return $found;
    }

    /** The one screen a reader means, or null when none or more than one answers ({@see matches()} says which). */
    public function find(string $reference): ?string
    {
        $found = $this->matches($reference);

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * A screen as {@see module()} and {@see screen()} give it, or null.
     *
     * @return Screen|null
     */
    public function describe(string $id): ?array
    {
        return $this->screens()[$id] ?? null;
    }

    /** @return array{status: string, message: ?string, project_root: string} */
    public function status(): array
    {
        $status = $this->flow->rootStatus();

        return [
            'status' => $status,
            'message' => match ($status) {
                UiFlowProjection::ROOT_MISSING => sprintf(
                    'The graph was built from %s, which is not a directory on this machine, so no template can be placed and no screen is shown. Rebuild it here with bin/semitexa ai:review-graph:generate --full.',
                    $this->flow->root(),
                ),
                UiFlowProjection::ROOT_UNKNOWN => 'The graph does not say which project it was built from, so no template can be placed and no screen is shown. Rebuild it with bin/semitexa ai:review-graph:generate --full.',
                default => null,
            },
            'project_root' => $this->flow->root(),
        ];
    }

    /**
     * Navigation and actions are read; data and access come with a later
     * slice and are named so a reader sees they are not drawn, rather than
     * taking their absence for "none".
     *
     * @return Layers
     */
    private static function layers(): array
    {
        return [
            UiFlowProjection::LAYER_NAVIGATION => ['available' => true, 'on' => true],
            UiFlowProjection::LAYER_ACTION => ['available' => true, 'on' => true],
            'data' => ['available' => false, 'on' => false],
            'access' => ['available' => false, 'on' => false],
        ];
    }

    /** @return array<string, Screen> */
    private function screens(): array
    {
        if ($this->screens !== null) {
            return $this->screens;
        }
        $payloads = $this->flow->payloads();
        $byTemplate = [];
        foreach ($this->flow->templateEdges() as $edge) {
            $byTemplate[$edge['template']][] = $edge;
        }

        $pages = [];
        $rendered = [];
        foreach ($this->flow->pages() as $page) {
            $payload = $payloads[$page['id']];
            if ($this->tests !== null && $this->tests->contains($payload['file'])) {
                continue;
            }
            $pages[$page['id']] = $page;
            foreach ($page['templates'] as $template) {
                $rendered[$template] = ($rendered[$template] ?? 0) + 1;
            }
        }
        // By path, then id: two payloads may declare one path.
        uasort($pages, static fn (array $a, array $b): int => [$a['path'], $a['id']] <=> [$b['path'], $b['id']]);

        $this->screens = [];
        foreach ($pages as $id => $page) {
            $payload = $payloads[$id];
            $elements = [];
            $unresolved = 0;
            foreach ($page['templates'] as $template) {
                foreach ($byTemplate[$template] ?? [] as $edge) {
                    $unresolved += $edge['to'] === null ? 1 : 0;
                    $elements[] = [
                        'kind' => $edge['kind'],
                        'layer' => $edge['layer'],
                        'source' => $edge['source'],
                        'confidence' => $edge['confidence'],
                        'why' => $edge['why'],
                        'method' => $edge['method'],
                        'href' => $edge['href'],
                        'to' => $edge['to'],
                        'route' => $edge['route'],
                        'at' => $edge['at'],
                        // Written in a template more than one screen renders: a layout's link, not this page's own.
                        'shared' => ($rendered[$template] ?? 0) > 1,
                    ];
                }
            }
            usort($elements, static fn (array $a, array $b): int => [$a['at'], $a['href']] <=> [$b['at'], $b['href']]);
            $this->elements[$id] = $elements;
            $this->templates[$id] = $page['templates'];
            $this->screens[$id] = [
                'kind' => 'screen',
                'id' => $id,
                'path' => $page['path'],
                'name' => $page['name'],
                'module' => $page['module'] !== '' ? $page['module'] : self::NO_MODULE,
                'fqcn' => $payload['fqcn'],
                'resource' => $page['resource'],
                'via' => $page['via'],
                'at' => $this->flow->relative($payload['file']) . ':' . $payload['line'],
                'templates' => count($page['templates']),
                'elements' => count($elements),
                'unresolved' => $unresolved,
            ];
        }

        return $this->screens;
    }

    /**
     * A way's target as a node: the screen of that payload, or the payload
     * itself as an endpoint.
     *
     * @return Screen|Endpoint
     */
    private function node(string $payloadId): array
    {
        $screen = $this->screens()[$payloadId] ?? null;
        if ($screen !== null) {
            return $screen;
        }
        $payload = $this->flow->payloads()[$payloadId];

        return [
            'kind' => 'endpoint',
            'id' => $payloadId,
            'path' => $payload['path'],
            'name' => $payload['name'],
            'module' => $payload['module'] !== '' ? $payload['module'] : self::NO_MODULE,
            'fqcn' => $payload['fqcn'],
            'methods' => $payload['methods'],
            'at' => $this->flow->relative($payload['file']) . ':' . $payload['line'],
        ];
    }

    /**
     * How many navigation wires from the module's entry each screen is.
     *
     * @param array<string, Screen> $screens the module's screens, by id, in path order
     * @param list<Wire> $wires
     * @return array{array<string, int>, array<string, string>} screen id => depth, a screen not reached absent; and screen id => the screen it was first reached from
     */
    private static function depths(array $screens, array $wires): array
    {
        $next = [];
        foreach ($wires as $wire) {
            if ($wire['layer'] === UiFlowProjection::LAYER_NAVIGATION && isset($screens[$wire['from']], $screens[$wire['to']])) {
                $next[$wire['from']][] = $wire['to'];
            }
        }
        // The entry: the shortest path, the first of them in path order.
        $entry = null;
        foreach ($screens as $id => $screen) {
            if ($entry === null || strlen($screen['path']) < strlen($screens[$entry]['path'])) {
                $entry = $id;
            }
        }
        if ($entry === null) {
            return [[], []];
        }
        $tree = [];
        $depths = [$entry => 0];
        $queue = [$entry];
        while ($queue !== []) {
            $at = array_shift($queue);
            foreach ($next[$at] ?? [] as $to) {
                if (!isset($depths[$to])) {
                    $depths[$to] = $depths[$at] + 1;
                    $tree[$to] = $at;
                    $queue[] = $to;
                }
            }
        }

        return [$depths, $tree];
    }
}
