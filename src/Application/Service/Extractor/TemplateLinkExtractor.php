<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Extractor;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * The ways a Twig template writes out (ep-ui-navigation-graph,
 * tk-uigraph-template-links): a link is a way to the route it names, a form
 * a submit to its route. They hang off the template's `file:` node, owned by
 * the template, so editing or deleting it takes them along.
 *
 * Only what the markup states. An `<a href="/path">` navigates to
 * `route:GET:/path`; a `<form action="/path" method="post">` submits to
 * `route:POST:/path` (a form without a method is a GET). A query string or
 * fragment is not part of the route and is kept on the edge. Whether a route
 * of that path exists, or which pattern (`/users/{id}`) a literal path would
 * match, is the route table's to answer at query time; the edge does not
 * pretend to know.
 *
 * Never drawn as a guess: a target computed by Twig (`{{ row.href }}`) is
 * listed on the file node as unresolved, and `url('route.name')` as a named
 * link, which the route's name resolves exactly. A `<link href>` loads a
 * resource and is no way; other hosts, `mailto:`, `#…` and `//host` leave the
 * application. Twig comments are dropped before reading.
 */
final class TemplateLinkExtractor
{
    public const SUFFIX = '.twig';

    public static function handles(string $path): bool
    {
        return str_ends_with($path, self::SUFFIX);
    }

    public function extract(string $path, string $contents, string $module): ExtractionResult
    {
        $result = new ExtractionResult();
        // What never renders as a link is blanked out line for line, so a
        // line number still points at the template as written: Twig and HTML
        // comments, script, style and textarea bodies, {% verbatim %} blocks.
        $markup = (string) preg_replace_callback(
            '/\{#.*?#\}|<!--.*?-->|<(script|style|textarea)\b.*?<\/\1\s*>|\{%-?\s*verbatim\s*-?%\}.*?\{%-?\s*endverbatim\s*-?%\}/is',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $contents,
        );

        /** @var array<string, array{type: EdgeType, target: string, lines: list<int>, hrefs: list<string>}> $ways */
        $ways = [];
        $unresolved = [];
        $named = [];
        // A tag ends at the first > outside quotes and outside Twig: an
        // {% if a > b %} or a {{ "it's" }} inside it, quoted or not, is part
        // of the tag (Twig renders before the browser reads the quotes).
        preg_match_all('/<(a|form)(?=[\s>\/])((?:\{\{.*?\}\}|\{%.*?%\}|"(?:\{\{.*?\}\}|\{%.*?%\}|[^"])*"|\'(?:\{\{.*?\}\}|\{%.*?%\}|[^\'])*\'|[^>"\'{]|\{)*)>/is', $markup, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            $isForm = strtolower($tag[1][0]) === 'form';
            $target = self::attribute($tag[2][0], $isForm ? 'action' : 'href');
            if ($target === null) {
                continue;
            }
            $line = substr_count($markup, "\n", 0, $tag[0][1]) + 1;
            // Named only when url() is the whole target: {{ url('users') }}/edit
            // renders somewhere else, and stays unresolved.
            if (preg_match('/\A\{\{\s*url\(\s*([\'"])([A-Za-z0-9_.:-]+)\1\s*(?:,(?:[^{}]|\{[^{}]*\})*)?\)\s*\}\}\z/', $target, $m) === 1) {
                $named[] = ['line' => $line, 'route' => $m[2], 'kind' => $isForm ? 'submit' : 'link'];
                continue;
            }
            if (str_contains($target, '{{') || str_contains($target, '{%')) {
                $unresolved[] = ['line' => $line, 'href' => $target, 'kind' => $isForm ? 'submit' : 'link'];
                continue;
            }
            $routePath = self::applicationPath($target);
            if ($routePath === null) {
                continue;
            }
            $method = $isForm ? strtoupper(self::attribute($tag[2][0], 'method') ?? 'GET') : 'GET';
            if (!in_array($method, ['GET', 'POST'], true)) {
                continue;
            }
            $type = $isForm && $method === 'POST' ? EdgeType::SubmitsTo : EdgeType::NavigatesTo;
            $routeId = NodeId::forRoute($method, $routePath);
            // One edge per (source, target, type): every place the template
            // writes the same way is a line on it.
            $key = $type->value . ' ' . $routeId;
            $ways[$key] ??= ['type' => $type, 'target' => $routeId, 'lines' => [], 'hrefs' => []];
            $ways[$key]['lines'][] = $line;
            $ways[$key]['hrefs'][] = $target;
        }
        if ($ways === [] && $unresolved === [] && $named === []) {
            return $result;
        }

        $metadata = ['template' => true];
        if ($unresolved !== []) {
            $metadata['unresolved_links'] = $unresolved;
        }
        if ($named !== []) {
            $metadata['named_links'] = $named;
        }
        $fileNode = FileNode::of($path, $contents, $module, $metadata);
        $result->addNode($fileNode);
        foreach ($ways as $way) {
            $result->addEdge(new Edge(
                sourceId: $fileNode->getId(),
                targetId: $way['target'],
                type:     $way['type'],
                metadata: ['source' => 'template', 'lines' => $way['lines'], 'hrefs' => array_values(array_unique($way['hrefs']))],
            ));
        }

        return $result;
    }

    /**
     * An attribute's value as written, quoted or bare; null when the tag has
     * none. Attributes are read left to right, each value consumed whole, so
     * an "href=" inside another attribute's value (title="… href=/x") or a
     * Twig expression is never taken for one.
     */
    private static function attribute(string $attributes, string $name): ?string
    {
        preg_match_all('/\{\{.*?\}\}|\{%.*?%\}|([^\s=\/"\'{}]+)(?:\s*=\s*(?:"((?:\{\{.*?\}\}|\{%.*?%\}|[^"])*)"|\'((?:\{\{.*?\}\}|\{%.*?%\}|[^\'])*)\'|((?:\{\{.*?\}\}|[^\s"\'>])+)))?/s', $attributes, $tokens, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        foreach ($tokens as $token) {
            if ($token[1] !== null && strcasecmp($token[1], $name) === 0) {
                return trim($token[2] ?? $token[3] ?? $token[4] ?? '');
            }
        }

        return null;
    }

    /** The application path a literal target names, or null when it leaves the application. */
    private static function applicationPath(string $target): ?string
    {
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }
        $path = (string) preg_replace('/[?#].*\z/s', '', $target);

        return $path === '' ? '/' : $path;
    }
}
