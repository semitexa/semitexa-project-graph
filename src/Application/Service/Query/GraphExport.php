<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * The graph view as ONE self-contained HTML file: `ai:review-graph:show
 * --format=html --output=<file>`.
 *
 * Same client as the Observatory's Graph view — graph-view.js and .css are
 * inlined, not copied into a second viewer — with the data embedded as a
 * JSON block instead of fetched: the client's embedded data source answers
 * the same questions from it. The file opens from file://, so a PR can carry
 * it as an artifact and a reviewer needs no running stack.
 *
 * What is embedded is a slice, and the filters exist to keep it small: a
 * focus and depth (that node's dependency walk), a module, or node types.
 * Without one, the whole graph — noise edges and documentation nodes left
 * out, as everywhere the graph is browsed.
 */
final class GraphExport
{
    public function __construct(
        private readonly GraphStorage $storage,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * @param list<string>|null $types
     * @return array{meta: array<string, mixed>, summary: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array{s: string, t: string, k: string, c: string}>, gaps: array<string, list<array<string, mixed>>>, findings: array<string, mixed>}
     */
    public function data(?string $focus = null, int $depth = 3, ?string $module = null, ?array $types = null): array
    {
        $browser = new GraphBrowser($this->storage, $this->projectRoot);

        $focusId = null;
        if ($focus !== null && $focus !== '') {
            $node = $this->storage->nodes->findById($focus) ?? $this->storage->nodes->findByFqcn($focus);
            if ($node === null) {
                throw new \InvalidArgumentException(sprintf('The graph has no node "%s".', $focus));
            }
            $focusId = $node->getId();
        }

        $keep = [];
        if ($focusId !== null) {
            $slice = $browser->subgraph($focusId, max(1, min(GraphBrowser::MAX_DEPTH, $depth))) ?? ['nodes' => []];
            foreach ($slice['nodes'] as $row) {
                if (!($row['ghost'] ?? false) && is_string($row['id'] ?? null)) {
                    $keep[$row['id']] = true;
                }
            }
        }

        /** @var array<string, Node> $nodes */
        $nodes = [];
        foreach ($this->storage->nodes->all() as $node) {
            $type = $node->getType()->value;
            if (in_array($type, GraphBrowser::HIDDEN_NODES, true)
                || ($focusId !== null && !isset($keep[$node->getId()]))
                || ($module !== null && $module !== '' && $node->getModule() !== $module)
                || ($types !== null && $types !== [] && !in_array($type, $types, true))) {
                continue;
            }
            $nodes[$node->getId()] = $node;
        }

        $edges = [];
        $fanIn = [];
        // Paged rows, not Edge objects: ~70k of those do not fit a 128M CLI.
        foreach ($this->storage->edges->withSources() as $edge) {
            $kind = $edge['type'];
            $type = EdgeType::tryFrom($kind);
            if ($type === null || in_array($kind, GraphBrowser::NOISE_EDGES, true)) {
                continue;
            }
            $fanIn[$edge['target_id']] = ($fanIn[$edge['target_id']] ?? 0) + 1;
            if (isset($nodes[$edge['source_id']], $nodes[$edge['target_id']])) {
                $edges[] = ['s' => $edge['source_id'], 't' => $edge['target_id'], 'k' => $kind, 'c' => $type->edgeClass()->value];
            }
        }

        $rows = [];
        $gaps = [];
        $entries = ['route' => [], 'command' => [], 'handler' => []];
        foreach ($nodes as $id => $node) {
            $row = $browser->node($node) + ['fanIn' => $fanIn[$id] ?? 0];
            $rows[] = $row;
            if ($row['gaps'] > 0) {
                $gaps[$id] = $browser->gapsOf($node->getFile());
            }
            $type = $node->getType();
            if (!$node->getIsPlaceholder() && in_array($type, [NodeType::Route, NodeType::Command, NodeType::Handler], true)) {
                $entries[$type->value][] = $browser->node($node);
            }
        }
        foreach ($entries as &$list) {
            usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        }
        unset($list);

        $findings = $browser->findings();
        $findings['unused'] = array_values(array_filter($findings['unused'], static fn (array $u): bool => isset($nodes[$u['node']['id']])));
        // A loop that crosses the slice's edge keeps the members inside it —
        // the viewer focuses members[0], and one outside the file cannot load.
        // `cycle` still names the whole loop.
        $cycles = [];
        foreach ($findings['cycles'] as $c) {
            $c['members'] = array_values(array_filter($c['members'], static fn (array $m): bool => isset($nodes[$m['id']])));
            if ($c['members'] !== []) {
                $cycles[] = $c;
            }
        }
        $findings['cycles'] = $cycles;

        $byType = [];
        $modules = [];
        foreach ($nodes as $node) {
            $byType[$node->getType()->value] = ($byType[$node->getType()->value] ?? 0) + 1;
            if ($node->getModule() !== '') {
                $modules[$node->getModule()] = true;
            }
        }
        ksort($modules);
        $built = $this->storage->getMeta('last_update');

        return [
            'meta' => [
                'generatedAt' => date('c'),
                'focus' => $focusId,
                'filter' => array_filter(['focus' => $focusId, 'depth' => $focusId !== null ? $depth : null, 'module' => $module, 'types' => $types]),
            ],
            'summary' => [
                'builtAt' => $built !== null && $built !== '' ? (int) $built : null,
                'stale' => false,
                'counts' => ['nodes' => count($rows), 'edges' => count($edges), 'byType' => $byType],
                'modules' => array_keys($modules),
                'entries' => $entries,
                'coverage' => ['gaps' => $this->storage->gaps->countByKind()],
            ],
            'nodes' => $rows,
            'edges' => $edges,
            'gaps' => $gaps,
            'findings' => $findings,
        ];
    }

    /**
     * Write the file, streamed: the whole graph of this workspace embeds ~8 MB
     * of JSON, and building it as one string — then once more inside the page
     * — does not fit a 128M CLI. Nodes and edges are encoded in chunks; every
     * piece goes through JSON_HEX_TAG, so nothing in the data can close the
     * script block it sits in.
     *
     * @param array{meta: array<string, mixed>, summary: array<string, mixed>, nodes: list<array<string, mixed>>, edges: list<array{s: string, t: string, k: string, c: string}>, gaps: array<string, list<array<string, mixed>>>, findings: array<string, mixed>} $data from {@see data()}
     * @return int bytes written
     */
    public function write(string $path, array $data, string $title): int
    {
        $css = GraphViewerAssets::read('graph-view.css');
        $js = GraphViewerAssets::read('graph-view.js');
        if ($css === null || $js === null) {
            throw new \RuntimeException('Graph viewer assets are missing under ' . GraphViewerAssets::dir());
        }
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Could not write ' . $path);
        }

        $enc = static fn (mixed $v): string => self::json($v);

        try {
            $title = htmlspecialchars($title, ENT_QUOTES);
            // The file carries its own policy: it runs its two inline scripts and
            // its stylesheet, and loads nothing else from anywhere. A graph opened
            // from a PR artifact has no business reaching the network.
            $nonce = base64_encode(random_bytes(16));
            $policy = "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src data:";
            self::put($out, self::HEAD_START . $title . "</title>\n"
                . '<meta http-equiv="Content-Security-Policy" content="' . $policy . "\">\n"
                . '<style nonce="' . $nonce . "\">\n" . self::PAGE_CSS . $css . "\n</style>\n</head>\n<body>\n<div id=\"graph\"></div>\n"
                . '<script type="application/json" id="graph-data">');
            self::put($out, '{"meta":' . $enc($data['meta']) . ',"summary":' . $enc($data['summary']) . ',"nodes":');
            self::writeList($out, $data['nodes']);
            self::put($out, ',"edges":');
            self::writeList($out, $data['edges']);
            self::put($out, ',"gaps":' . ($data['gaps'] === [] ? '{}' : $enc($data['gaps'])) . ',"findings":' . $enc($data['findings']) . '}');
            // A script body must not contain "</script"; the viewer has none, and this keeps it so.
            self::put($out, "</script>\n" . '<script nonce="' . $nonce . "\">\n" . str_ireplace('</script', '<\/script', $js) . "\n</script>\n"
                . '<script nonce="' . $nonce . "\">\n" . self::BOOT . "</script>\n</body>\n</html>\n");
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($path);

            throw new \RuntimeException('Could not write ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
        fclose($out);

        return (int) filesize($path);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * A short write is a failure, not a smaller file: a full disk must not be
     * reported as "Wrote …" over a page that will not load.
     *
     * @param resource $out
     */
    private static function put($out, string $bytes): void
    {
        if (fwrite($out, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Short write while exporting the graph');
        }
    }

    /**
     * A JSON array written 500 items at a time.
     *
     * @param resource $out
     * @param list<mixed> $items
     */
    private static function writeList($out, array $items): void
    {
        self::put($out, '[');
        foreach (array_chunk($items, 500) as $i => $chunk) {
            self::put($out, ($i > 0 ? ',' : '') . substr(self::json($chunk), 1, -1));
        }
        self::put($out, ']');
    }

    private const HEAD_START = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<meta name=\"robots\" content=\"noindex, nofollow\">\n<title>";

    private const PAGE_CSS = "html,body{height:100%;margin:0}\n"
        . "body{background:#070a12;padding:10px;box-sizing:border-box;color-scheme:dark}\n"
        . "@media (prefers-color-scheme: light){body{background:#f4f6fb;color-scheme:light}}\n#graph{height:100%}\n";

    /** Mount the viewer on the embedded document; the address keeps the selected node. */
    private const BOOT = <<<'JS'
(() => {
  const data = JSON.parse(document.getElementById('graph-data').textContent);
  // A malformed fragment must not leave a blank page: no selection instead.
  let fromHash = null;
  try { fromHash = decodeURIComponent(location.hash.slice(1)) || null; } catch (e) { fromHash = null; }
  window.SemitexaGraphView.mount(document.getElementById('graph'), window.SemitexaGraphView.embeddedSource(data), {
    initial: fromHash || data.meta.focus || null,
    onSelect: id => history.replaceState(null, '', '#' + encodeURIComponent(id)),
  });
})();

JS;
}
