<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\UiFlow\RouteMatcher;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowProjection;

/**
 * Where the UI flow does not close (ep-ui-flow-graph), one finding per place.
 * Warnings: each is something to look at, none proves a defect.
 *
 * `form-to-missing-route`: a template writes a literal link or form target
 * that no declared route answers, exactly or by pattern, with or without a
 * locale prefix. A file to download (`/assets/app.css`, `/favicon.ico`) is
 * no route and is skipped; a target Twig computes is not judged at all.
 *
 * `orphan-post`: a POST payload a visitor's browser is meant to send, which
 * no form in any template targets and no named UI action stands behind.
 * Silent, because nobody expects a form there, for a service payload, a
 * signed URL, a development surface (`/__…`), a payload that consumes JSON
 * and no form encoding, and an API route: an event stream, a render profile
 * without html, a declared `produces` without text/html, a path under /api/.
 * A payload carrying #[UiEntryPoint(reason: …)] is listed as accepted.
 *
 * Blind spot, stated rather than guessed: a POST sent by a script, by a
 * component's own form (`component('platform.form', {submitAction: …})`) or
 * to a computed action is not read, so its payload is reported. That is
 * what #[UiEntryPoint] is for.
 */
final class UiFlowGapReport
{
    public const FORM_TO_MISSING_ROUTE = 'form-to-missing-route';
    public const ORPHAN_POST = 'orphan-post';

    /**
     * @return array{
     *     findings: list<array{id: string, kind: string, rule: string, at: string, module: string, detail: string, subject: string, lines?: list<int>}>,
     *     accepted: list<array{id: string, kind: string, rule: string, at: string, module: string, detail: string, subject: string, reason: string}>,
     *     summary: array{pages: int, ways: int, by_confidence: array<string, int>, post_payloads: int, reached: int, allowed: array<string, int>, by_rule: array<string, int>, test_code_skipped: int}
     * }
     */
    public function collect(GraphStorage $storage, ?string $module = null, ?TestCode $tests = null): array
    {
        $flow = new UiFlowProjection($storage);
        $findings = [];
        $accepted = [];
        $skipped = 0;

        $byConfidence = [RouteMatcher::EXACT => 0, RouteMatcher::PATTERN => 0, RouteMatcher::UNRESOLVED => 0];
        $missing = [];
        $ways = 0;
        foreach ($flow->templateEdges() as $edge) {
            if ($module !== null && $edge['module'] !== $module) {
                continue;
            }
            if ($tests !== null && $tests->contains(substr($edge['template'], strlen('file:')))) {
                $skipped++;
                continue;
            }
            $ways++;
            $byConfidence[$edge['confidence']]++;
            if ($edge['why'] !== 'no route of that path' || self::isAsset($edge['href'])) {
                continue;
            }
            $path = (string) preg_replace('/[?#].*\z/s', '', $edge['href']);
            $key = $edge['template'] . "\0" . $edge['method'] . "\0" . $path;
            $missing[$key] ??= ['edge' => $edge, 'path' => $path, 'lines' => []];
            $missing[$key]['lines'][] = $edge['line'];
        }
        foreach ($missing as $group) {
            $edge = $group['edge'];
            sort($group['lines']);
            $findings[] = [
                // The project-relative path: an absolute one gave the same finding one id in the container and another on the host.
                'id' => FindingsReport::findingId('ui-' . self::FORM_TO_MISSING_ROUTE, [$flow->relative(substr($edge['template'], strlen('file:'))), $edge['method'] . ' ' . $group['path']]),
                'kind' => 'ui_flow',
                'rule' => self::FORM_TO_MISSING_ROUTE,
                'at' => $flow->relative(substr($edge['template'], strlen('file:'))) . ':' . $group['lines'][0],
                'module' => $edge['module'],
                'detail' => sprintf('%s %s, and no route answers %s %s.', $edge['kind'] === 'submit' ? 'A form posts to' : 'A link or form goes to', $group['path'], $edge['method'], $group['path']),
                'subject' => $edge['method'] . ' ' . $group['path'],
                'lines' => $group['lines'],
            ];
        }

        $reached = $flow->submitted();
        $allowed = [];
        $posts = 0;
        $reachedCount = 0;
        foreach ($flow->payloads() as $payload) {
            if ($payload['path'] === '' || !in_array('POST', $payload['methods'], true) || ($module !== null && $payload['module'] !== $module)) {
                continue;
            }
            if ($tests !== null && $tests->contains($payload['file'])) {
                $skipped++;
                continue;
            }
            $posts++;
            if (isset($reached[$payload['id']])) {
                $reachedCount++;
                continue;
            }
            $row = [
                'id' => FindingsReport::findingId('ui-' . self::ORPHAN_POST, [$payload['id']]),
                'kind' => 'ui_flow',
                'rule' => self::ORPHAN_POST,
                'at' => $flow->relative($payload['file']) . ':' . $payload['line'],
                'module' => $payload['module'],
                'detail' => sprintf('POST %s (%s) is not declared an API, and no form in any template and no UI action reaches it.', $payload['path'], self::shortName($payload['fqcn'])),
                'subject' => 'POST ' . $payload['path'],
            ];
            $entry = $payload['metadata']['uiEntryPoint'] ?? null;
            if (is_string($entry)) {
                $accepted[] = $row + ['reason' => $entry];
                continue;
            }
            $why = $this->allowedBecause($payload);
            if ($why !== null) {
                $allowed[$why] = ($allowed[$why] ?? 0) + 1;
                continue;
            }
            $findings[] = $row;
        }

        usort($findings, static fn (array $a, array $b): int => [$a['rule'], $a['at'], $a['subject']] <=> [$b['rule'], $b['at'], $b['subject']]);
        usort($accepted, static fn (array $a, array $b): int => [$a['at'], $a['subject']] <=> [$b['at'], $b['subject']]);
        ksort($allowed);
        $byRule = [self::FORM_TO_MISSING_ROUTE => 0, self::ORPHAN_POST => 0];
        foreach ($findings as $finding) {
            $byRule[$finding['rule']]++;
        }
        $pages = 0;
        foreach ($flow->pages() as $page) {
            $pages += $module === null || $page['module'] === $module ? 1 : 0;
        }

        return [
            'findings' => $findings,
            'accepted' => $accepted,
            'summary' => [
                'pages' => $pages,
                'ways' => $ways,
                'by_confidence' => $byConfidence,
                'post_payloads' => $posts,
                'reached' => $reachedCount,
                'allowed' => $allowed,
                'by_rule' => $byRule,
                'test_code_skipped' => $skipped,
            ],
        ];
    }

    /**
     * Why nobody expects a form to target this payload, or null when a form
     * is what one would expect.
     *
     * A form is expected unless the payload says otherwise. Whether it
     * answers with an HtmlResponse does not say: a form post is as often
     * answered with a redirect or a small acknowledgement, and judging by the
     * response silenced exactly those (2026-11-19: /validation-demo/contact,
     * /ui-playground/admin/leads/demo-add). So a payload that takes a browser
     * form's own encoding is a form target whatever else it declares, and
     * one that declares nothing is one too, unless its route is an API route
     * in the Explorer's sense (RouteKindClassifier in semitexa/dev, which
     * this package cannot require): an event stream, a render profile
     * without html, a declared `produces` without text/html, or a path under
     * /api/.
     *
     * @param array{id: string, path: string, metadata: array<string, mixed>} $payload
     */
    private function allowedBecause(array $payload): ?string
    {
        $metadata = $payload['metadata'];
        $list = static fn (string $key): array => array_values(array_filter(is_array($metadata[$key] ?? null) ? $metadata[$key] : [], 'is_string'));

        if (($metadata['accessType'] ?? null) === 'service') {
            return 'service';
        }
        if (($metadata['signedUrl'] ?? false) === true) {
            return 'signed-url';
        }
        if (str_starts_with($payload['path'], '/__')) {
            return 'internal';
        }
        // Before JSON: a payload taking both is still what a plain form posts to.
        $consumes = $list('consumes');
        foreach ($consumes as $type) {
            if ($type === 'application/x-www-form-urlencoded' || $type === 'multipart/form-data') {
                return null;
            }
        }
        foreach ($consumes as $type) {
            if (str_contains($type, 'json')) {
                return 'json';
            }
        }
        $produces = $list('producesDeclared');
        if (($metadata['transport'] ?? null) === 'sse' || in_array('text/event-stream', $produces, true)) {
            return 'api';
        }
        $profiles = $list('renderProfile');
        if ($profiles !== []) {
            return in_array('html', $profiles, true) ? null : 'api';
        }
        if ($produces !== [] && !in_array('text/html', $produces, true)) {
            return 'api';
        }

        return preg_match('#\A/api(/|\z)#', $payload['path']) === 1 ? 'api' : null;
    }

    /**
     * A path with a file extension, or under a directory only files are
     * served from: the web server answers it, no route does.
     */
    private static function isAsset(string $href): bool
    {
        $path = (string) preg_replace('/[?#].*\z/s', '', $href);

        return preg_match('#\A/(?:assets|static|build|media|uploads|storage)/#', $path) === 1
            || preg_match('#\.[A-Za-z0-9]{1,5}\z#', basename($path)) === 1;
    }

    private static function shortName(string $fqcn): string
    {
        return substr($fqcn, (int) strrpos($fqcn, '\\') + (str_contains($fqcn, '\\') ? 1 : 0));
    }
}
