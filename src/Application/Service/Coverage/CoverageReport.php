<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Coverage;

use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeId;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Domain\Model\CoverageGap;

/**
 * What the graph could not see, as every answer should carry it.
 *
 * A graph answer built on absence — "nothing uses this", "zero blast radius"
 * — is only proof when nothing that could hide the missing edge was left
 * unread. This report says whether that holds for the whole graph and, for a
 * given set of nodes, which gaps could hide an edge to or from them:
 *
 * - parse_error and extraction_failed anywhere: they hide arbitrary edges;
 * - dynamic_reference in the same module as a node: runtime class names
 *   near the code are the likeliest hidden callers;
 * - ignored and duplicate_class about the node itself;
 * - unresolved references in the node's namespace: a class-typed placeholder
 *   whose namespace also has declared classes is probably a project class
 *   nobody declares (a typo, a move, a deletion). Computed from the graph as
 *   it stands, never stored, so it cannot go stale.
 */
final class CoverageReport
{
    private const RELEVANT_CAP = 20;

    public function __construct(
        private readonly GraphStorage $storage,
    ) {}

    /**
     * @return array{complete: bool, gaps: array<string, int>, unresolved_references: int, exclusions: array<string, int>}
     */
    public function summary(): array
    {
        $gaps = $this->storage->gaps->countByKind();
        $unresolved = count($this->unresolvedReferences());

        return [
            'complete'              => $gaps === [] && $unresolved === 0,
            'gaps'                  => $gaps,
            'unresolved_references' => $unresolved,
            'exclusions'            => $this->exclusions(),
        ];
    }

    /**
     * The summary plus the gaps that could hide an edge touching these nodes.
     * absence_is_proof is false whenever any such gap exists.
     *
     * @param list<string> $nodeIds
     * @return array{complete: bool, gaps: array<string, int>, unresolved_references: int, exclusions: array<string, int>, absence_is_proof: bool, relevant_total: int, relevant: list<array{kind: string, file: string, line: int, subject: string, detail: string}>}
     */
    public function forNodes(array $nodeIds): array
    {
        $relevant = $this->relevantGaps($nodeIds);

        return $this->summary() + [
            'absence_is_proof' => $relevant === [],
            'relevant_total'   => count($relevant),
            'relevant'         => array_map(
                static fn (CoverageGap $gap): array => [
                    'kind'    => $gap->getKind()->value,
                    'file'    => $gap->getFile(),
                    'line'    => $gap->getLine(),
                    'subject' => $gap->getSubject(),
                    'detail'  => $gap->getDetail(),
                ],
                array_slice($relevant, 0, self::RELEVANT_CAP),
            ),
        ];
    }

    /**
     * @param list<string> $nodeIds
     * @return list<CoverageGap>
     */
    public function relevantGaps(array $nodeIds): array
    {
        $fqcns = [];
        $modules = [];
        $namespaces = [];
        foreach ($nodeIds as $id) {
            $node = $this->storage->nodes->findById($id);
            $fqcn = $node?->getFqcn() ?? NodeId::extractFqcn($id);
            $fqcns[$fqcn] = true;
            if ($node !== null && $node->getModule() !== '') {
                $modules[$node->getModule()] = true;
            }
            $namespaces[self::namespaceOf($fqcn)] = true;
        }

        $moduleOfFile = [];
        $relevant = [];
        foreach ($this->storage->gaps->findAll() as $gap) {
            $keep = match ($gap->getKind()) {
                CoverageGapKind::ParseError, CoverageGapKind::ExtractionFailed => true,
                CoverageGapKind::Ignored, CoverageGapKind::DuplicateClass => isset($fqcns[$gap->getSubject()]),
                CoverageGapKind::DynamicReference => isset($modules[$moduleOfFile[$gap->getFile()] ??= $this->moduleOfFile($gap->getFile())]),
                CoverageGapKind::UnresolvedReference => false,
            };
            if ($keep) {
                $relevant[] = $gap;
            }
        }

        foreach ($this->unresolvedReferences() as $fqcn) {
            if (isset($namespaces[self::namespaceOf($fqcn)])) {
                $relevant[] = new CoverageGap(
                    CoverageGapKind::UnresolvedReference,
                    '',
                    'Referenced, but no scanned file declares it — while other classes in its namespace are declared',
                    $fqcn,
                );
            }
        }

        return $relevant;
    }

    /**
     * FQCNs of class placeholders whose namespace also holds declared classes.
     *
     * @return list<string>
     */
    public function unresolvedReferences(): array
    {
        $declaredNamespaces = [];
        $placeholders = [];
        foreach ($this->storage->nodes->classLikeFqcns() as $row) {
            if ($row['placeholder']) {
                if ($row['type'] === NodeType::Class_->value) {
                    $placeholders[] = $row['fqcn'];
                }
            } else {
                $declaredNamespaces[self::namespaceOf($row['fqcn'])] = true;
            }
        }

        return array_values(array_filter(
            $placeholders,
            static fn (string $fqcn): bool => self::namespaceOf($fqcn) !== '' && isset($declaredNamespaces[self::namespaceOf($fqcn)]),
        ));
    }

    /**
     * Human-readable lines for a summary() or forNodes() result. Paths are
     * shown relative to $projectRoot, and one gap per file and kind — two
     * extractors failing on the same attribute are one thing to fix.
     *
     * @param array<string, mixed> $report
     * @return list<string>
     */
    public static function describe(array $report, string $projectRoot = ''): array
    {
        /** @var array<string, int> $gaps */
        $gaps = $report['gaps'] ?? [];
        $parts = [];
        foreach ($gaps as $kind => $count) {
            $parts[] = $count . ' ' . str_replace('_', ' ', (string) $kind);
        }
        if (($report['unresolved_references'] ?? 0) > 0) {
            $parts[] = $report['unresolved_references'] . ' unresolved reference(s)';
        }
        $lines = [$parts === [] ? 'Coverage: complete — nothing was left unread.' : 'Coverage: ' . implode(', ', $parts) . '.'];

        if (!isset($report['relevant_total']) || $report['relevant_total'] === 0) {
            return $lines;
        }

        $root = $projectRoot === '' ? '' : rtrim($projectRoot, '/') . '/';
        $shown = [];
        foreach ($report['relevant'] ?? [] as $gap) {
            $file = $root !== '' && str_starts_with($gap['file'], $root) ? substr($gap['file'], strlen($root)) : $gap['file'];
            $where = $file !== '' ? $file . ($gap['line'] > 0 ? ':' . $gap['line'] : '') : $gap['subject'];
            $shown[$gap['kind'] . ' ' . $where] ??= sprintf('  - %s %s — %s', $gap['kind'], $where, $gap['detail']);
        }

        $lines[] = sprintf('%d of them could hide an edge here:', $report['relevant_total']);
        foreach (array_slice(array_values($shown), 0, 5) as $line) {
            $lines[] = $line;
        }
        if ($report['relevant_total'] > 5) {
            $lines[] = sprintf('  … see --json for the list (up to %d)', self::RELEVANT_CAP);
        }

        return $lines;
    }

    /** @return array<string, int> */
    private function exclusions(): array
    {
        $decoded = json_decode((string) $this->storage->getMeta('coverage_exclusions'), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function moduleOfFile(string $file): string
    {
        foreach ($this->storage->nodes->findByFile($file) as $node) {
            if ($node->getModule() !== '') {
                return $node->getModule();
            }
        }

        return '';
    }

    private static function namespaceOf(string $fqcn): string
    {
        $slash = strrpos($fqcn, '\\');

        return $slash === false ? '' : substr($fqcn, 0, $slash);
    }
}
