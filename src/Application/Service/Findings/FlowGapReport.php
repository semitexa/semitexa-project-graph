<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Intelligence\FlowTracer;
use Semitexa\ProjectGraph\Application\Service\Query\QueryInterface;

/**
 * Where the graph stops seeing a business flow, one finding per place.
 *
 * Every flow is traced and its gaps are grouped by kind and by the node they
 * sit at: one interface with two bindings is one finding however many flows
 * it blinds, and the flows it blinds are its weight. A flow with no gap is
 * counted complete — the only flows whose picture the graph can stand behind.
 */
final class FlowGapReport
{
    /**
     * @return array{
     *     findings: list<array{id: string, kind: string, gap: string, at: string, detail: string, candidates?: list<string>, flows: list<string>, flow_count: int}>,
     *     flows: array{total: int, complete: int, incomplete: int, by_gap: array<string, int>, test_flows_skipped: int}
     * }
     */
    public function collect(QueryInterface $query, ?string $module = null, ?TestCode $tests = null): array
    {
        $tracer = new FlowTracer($query);
        $grouped = [];
        $total = 0;
        $complete = 0;
        $byGap = [];
        $skipped = 0;

        foreach ($query->findNodes(type: NodeType::ExecutionFlow->value) as $node) {
            // Named only by a handler (an abstract feed base, stdClass): no request enters there.
            if ($node->getIsPlaceholder() || ($module !== null && $node->getModule() !== $module)) {
                continue;
            }
            // A payload fixture is no business flow: 28 of the 30 handlerless flows were tests (2026-10-16).
            if ($tests !== null && $tests->contains($node->getFile())) {
                $skipped++;
                continue;
            }
            $flow = $tracer->trace($node);
            $total++;
            if ($flow->isComplete()) {
                $complete++;
                continue;
            }
            $kinds = [];
            foreach ($flow->gaps as $gap) {
                $kinds[$gap['kind']] = true;
                $key = $gap['kind'] . "\0" . $gap['at'];
                $grouped[$key] ??= ['gap' => $gap['kind'], 'at' => $gap['at'], 'detail' => $gap['detail']]
                    + (isset($gap['candidates']) ? ['candidates' => $gap['candidates']] : [])
                    + ['flows' => []];
                $grouped[$key]['flows'][$flow->id] = true;
            }
            foreach (array_keys($kinds) as $kind) {
                $byGap[$kind] = ($byGap[$kind] ?? 0) + 1;
            }
        }

        $findings = [];
        foreach ($grouped as $group) {
            $flows = array_keys($group['flows']);
            sort($flows);
            unset($group['flows']);
            $findings[] = ['id' => FindingsReport::findingId('flow-gap', [$group['gap'], $group['at']]), 'kind' => 'flow_gap'] + $group
                + ['flows' => $flows, 'flow_count' => count($flows)];
        }
        usort($findings, static fn (array $a, array $b): int => [$b['flow_count'], $a['gap'], $a['at']] <=> [$a['flow_count'], $b['gap'], $b['at']]);
        arsort($byGap);

        return [
            'findings' => $findings,
            'flows' => ['total' => $total, 'complete' => $complete, 'incomplete' => $total - $complete, 'by_gap' => $byGap, 'test_flows_skipped' => $skipped],
        ];
    }
}
