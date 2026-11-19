<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\UiFlow;

/**
 * Which declared route a path written in a template goes to.
 *
 * In the order the router itself answers: the route of exactly that path,
 * then the first pattern the path fits (`/users/7` is `/users/{id}`), and for
 * a link written as `url('name')`, the route of that name. A path no route
 * fits is tried once more without a leading locale segment (`/uk/orders`),
 * which the locale layer strips before routing; a path that is a route with
 * the segment is never stripped. Given the project's locales, only one of
 * them is stripped; the graph does not hold them today, so by default any
 * two-letter segment (`uk`, `pt-BR`) is taken for one, and a match found
 * that way is never better than "pattern": it rests on a guess about what
 * the segment is.
 *
 * A requirement on a parameter is not in the graph, so every `{parameter}`
 * is one path segment: a match is "pattern", and says so.
 *
 * Two payloads may declare one route (a module's `/` and the scaffold's).
 * Which of them answers is the router's choice at boot, which the graph does
 * not hold; told the module of the template that writes the link, the
 * matcher takes the route of that module when there is one, since a module
 * links to its own home page far more often than to another's of the same
 * path. Without one it is the first listed, as before.
 *
 * Limit: a catch-all route (`/{slug}`, a CMS page) fits every one-segment
 * path, so a dead link `/nowhere` matches it as "pattern" and is never
 * reported missing while such a route exists. Patterns are tried in the
 * order the payloads are listed, not in the router's own order: which
 * pattern is named may differ, whether one matches does not.
 */
final class RouteMatcher
{
    public const EXACT = 'exact';
    public const PATTERN = 'pattern';
    public const UNRESOLVED = 'unresolved';

    /** @var array<string, array<string, array{method: string, path: string, name: string, payload: string}>> method => path => route */
    private array $exact = [];

    /** @var array<string, list<array{regex: string, route: array{method: string, path: string, name: string, payload: string}}>> */
    private array $patterns = [];

    /** @var array<string, list<array{method: string, path: string, name: string, payload: string}>> */
    private array $named = [];

    /** @var array<string, array<string, list<array{method: string, path: string, name: string, payload: string}>>> method => path => every route declaring it */
    private array $declaring = [];

    /** @var array<string, string> payload => its module */
    private array $moduleOf = [];

    /**
     * @param iterable<array{method: string, path: string, name: string, payload: string, module?: string}> $routes
     * @param list<string>|null $locales the project's locale prefixes when known; null = any two-letter segment
     */
    public function __construct(iterable $routes, private readonly ?array $locales = null)
    {
        foreach ($routes as $route) {
            $this->moduleOf[$route['payload']] = $route['module'] ?? '';
            $route = ['method' => strtoupper($route['method']), 'path' => self::declaredPath($route['path']), 'name' => $route['name'], 'payload' => $route['payload']];
            if ($route['path'] === '') {
                continue;
            }
            $route['path'] = self::normalise($route['path']);
            if ($route['name'] !== '') {
                $this->named[$route['name']][] = $route;
            }
            // Every route, literal or with parameters: declaring() answers for both.
            $this->declaring[$route['method']][$route['path']][] = $route;
            if (!str_contains($route['path'], '{')) {
                $this->exact[$route['method']][$route['path']] ??= $route;
                continue;
            }
            $quoted = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($route['path'], '#'));
            $this->patterns[$route['method']][] = ['regex' => '#^' . $quoted . '$#', 'route' => $route];
        }
    }

    /**
     * `env::NAME::/default` is the path unless the environment says
     * otherwise: the default is what the code declares.
     */
    public static function declaredPath(string $path): string
    {
        return preg_match('/\Aenv::[^:]+::(.*)\z/s', $path, $m) === 1 ? $m[1] : $path;
    }

    /**
     * @param string|null $fromModule the module of what writes the link: breaks a tie between payloads declaring one route
     * @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}, locale: ?string}|null
     */
    public function match(string $method, string $path, ?string $fromModule = null): ?array
    {
        $method = strtoupper($method);
        $path = self::normalise($path);
        $found = $this->find($method, $path, $fromModule);
        if ($found !== null) {
            return $found + ['locale' => null];
        }
        if (preg_match('#\A/([a-z]{2}(?:[-_][A-Za-z]{2})?)(/.*)?\z#', $path, $m) === 1
            && ($this->locales === null || in_array($m[1], $this->locales, true))) {
            $found = $this->find($method, self::normalise($m[2] ?? '/'), $fromModule);
            if ($found !== null) {
                return ['confidence' => self::PATTERN, 'route' => $found['route'], 'locale' => $m[1]];
            }
        }

        return null;
    }

    /**
     * @param string|null $fromModule as for {@see match()}
     * @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}, locale: ?string}|null
     */
    public function byName(string $name, ?string $method = null, ?string $fromModule = null): ?array
    {
        $first = null;
        foreach ($this->named[$name] ?? [] as $route) {
            if ($method !== null && $route['method'] !== strtoupper($method)) {
                continue;
            }
            $first ??= $route;
            if ($fromModule !== null && $this->moduleOf[$route['payload']] === $fromModule) {
                $first = $route;
                break;
            }
        }

        return $first === null ? null : ['confidence' => self::EXACT, 'route' => $first, 'locale' => null];
    }

    /**
     * Every payload declaring exactly this route, in the order listed.
     *
     * @return list<string> payload ids
     */
    public function declaring(string $method, string $path): array
    {
        return array_column($this->declaring[strtoupper($method)][self::normalise($path)] ?? [], 'payload');
    }

    /** @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}}|null */
    private function find(string $method, string $path, ?string $fromModule = null): ?array
    {
        if (isset($this->exact[$method][$path])) {
            return ['confidence' => self::EXACT, 'route' => $this->ofModule($this->exact[$method][$path], $fromModule)];
        }
        foreach ($this->patterns[$method] ?? [] as $pattern) {
            if (preg_match($pattern['regex'], $path) === 1) {
                // Which pattern fits is decided by the order listed; between
                // payloads declaring that same pattern, the linking module's own.
                return ['confidence' => self::PATTERN, 'route' => $this->ofModule($pattern['route'], $fromModule)];
            }
        }

        return null;
    }

    /**
     * The payload of $fromModule among those declaring this very route, or
     * the route as given when that module declares none.
     *
     * @param array{method: string, path: string, name: string, payload: string} $route
     * @return array{method: string, path: string, name: string, payload: string}
     */
    private function ofModule(array $route, ?string $fromModule): array
    {
        if ($fromModule !== null) {
            foreach ($this->declaring[$route['method']][$route['path']] ?? [] as $declared) {
                if ($this->moduleOf[$declared['payload']] === $fromModule) {
                    return $declared;
                }
            }
        }

        return $route;
    }

    /** The path alone: no query, no fragment, no trailing slash but the root's. */
    private static function normalise(string $path): string
    {
        $path = (string) preg_replace('/[?#].*\z/s', '', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
