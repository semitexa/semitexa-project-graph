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

    /**
     * @param iterable<array{method: string, path: string, name: string, payload: string}> $routes
     * @param list<string>|null $locales the project's locale prefixes when known; null = any two-letter segment
     */
    public function __construct(iterable $routes, private readonly ?array $locales = null)
    {
        foreach ($routes as $route) {
            $route['method'] = strtoupper($route['method']);
            $route['path'] = self::declaredPath($route['path']);
            if ($route['path'] === '') {
                continue;
            }
            $route['path'] = self::normalise($route['path']);
            if ($route['name'] !== '') {
                $this->named[$route['name']][] = $route;
            }
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

    /** @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}, locale: ?string}|null */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $path = self::normalise($path);
        $found = $this->find($method, $path);
        if ($found !== null) {
            return $found + ['locale' => null];
        }
        if (preg_match('#\A/([a-z]{2}(?:[-_][A-Za-z]{2})?)(/.*)?\z#', $path, $m) === 1
            && ($this->locales === null || in_array($m[1], $this->locales, true))) {
            $found = $this->find($method, self::normalise($m[2] ?? '/'));
            if ($found !== null) {
                return ['confidence' => self::PATTERN, 'route' => $found['route'], 'locale' => $m[1]];
            }
        }

        return null;
    }

    /** @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}, locale: ?string}|null */
    public function byName(string $name, ?string $method = null): ?array
    {
        foreach ($this->named[$name] ?? [] as $route) {
            if ($method === null || $route['method'] === strtoupper($method)) {
                return ['confidence' => self::EXACT, 'route' => $route, 'locale' => null];
            }
        }

        return null;
    }

    /** @return array{confidence: string, route: array{method: string, path: string, name: string, payload: string}}|null */
    private function find(string $method, string $path): ?array
    {
        if (isset($this->exact[$method][$path])) {
            return ['confidence' => self::EXACT, 'route' => $this->exact[$method][$path]];
        }
        foreach ($this->patterns[$method] ?? [] as $pattern) {
            if (preg_match($pattern['regex'], $path) === 1) {
                return ['confidence' => self::PATTERN, 'route' => $pattern['route']];
            }
        }

        return null;
    }

    /** The path alone: no query, no fragment, no trailing slash but the root's. */
    private static function normalise(string $path): string
    {
        $path = (string) preg_replace('/[?#].*\z/s', '', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
