<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * Unused classes and dependency loops, filtered and given stable ids.
 *
 * Shared by `ai:review-graph:findings` and the Observatory's Graph view, so
 * the panel shows exactly what the command prints rather than a re-derivation
 * of it that drifts.
 */
final class FindingsReport
{
    public const KINDS = ['unused', 'cycles', 'all'];

    public const CONFIDENCE_RANK = [UnusedClassFinder::LOW => 0, UnusedClassFinder::MEDIUM => 1, UnusedClassFinder::HIGH => 2];

    /**
     * @return array{
     *     unused: list<array{id: string, kind: string, node: string, fqcn: string, file: string, line: int, module: string, confidence: string, evidence: string}>,
     *     cycles: list<array{id: string, kind: string, members: list<string>, cycle: list<string>}>,
     *     coverage: array{complete: bool, gaps: array<string, int>, unresolved_references: int, exclusions: array<string, int>}
     * }
     */
    public function collect(GraphStorage $storage, string $kind = 'all', string $minConfidence = UnusedClassFinder::MEDIUM, ?string $module = null): array
    {
        $index = InboundIndex::of($storage);
        $unused = [];
        $cycles = [];
        $module = $module !== '' ? $module : null;
        $floor = self::CONFIDENCE_RANK[$minConfidence] ?? self::CONFIDENCE_RANK[UnusedClassFinder::MEDIUM];

        if ($kind !== 'cycles') {
            foreach ((new UnusedClassFinder())->find($storage, $index) as $finding) {
                if (self::CONFIDENCE_RANK[$finding['confidence']] < $floor) {
                    continue;
                }
                if ($module !== null && $finding['module'] !== $module) {
                    continue;
                }
                // `id` is the finding's stable id; the class's graph node id stays reachable as `node`.
                $unused[] = ['id' => self::findingId('unused', [$finding['id']]), 'kind' => 'unused', 'node' => $finding['id']] + $finding;
            }
        }

        if ($kind !== 'unused') {
            $modules = null;
            if ($module !== null) {
                $modules = [];
                foreach ($storage->nodes->declaredClasses() as $class) {
                    $modules[$class['id']] = $class['module'];
                }
            }
            foreach ((new CycleFinder())->find($storage, $index) as $cycle) {
                if ($modules !== null && !in_array($module, array_map(static fn (string $id): string => $modules[$id] ?? '', $cycle['members']), true)) {
                    continue;
                }
                $cycles[] = ['id' => self::findingId('cycle', $cycle['members']), 'kind' => 'cycle'] + $cycle;
            }
        }

        return ['unused' => $unused, 'cycles' => $cycles, 'coverage' => (new CoverageReport($storage))->summary()];
    }

    /**
     * Stable across runs: the same class, or the same set of classes in a loop, keeps its id.
     *
     * @param list<string> $nodeIds
     */
    public static function findingId(string $kind, array $nodeIds): string
    {
        sort($nodeIds);

        return $kind . ':' . substr(hash('xxh3', implode("\n", $nodeIds)), 0, 12);
    }
}
