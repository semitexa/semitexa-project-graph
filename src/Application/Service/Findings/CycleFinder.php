<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeClass;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * Classes that depend on each other in a loop, one row per loop.
 *
 * Found as strongly connected components (Tarjan), not by enumerating
 * cycles — their number grows exponentially with a component's size. Each
 * component is reported with its members and one shortest cycle through it,
 * the concrete loop a person can read and break.
 *
 * Only real dependencies count: code references and injection. Imports (a
 * `use` line is not a dependency on its own), a bare Foo::class, attribute annotations and the
 * inferred and structural edges are left out; so are placeholders and test
 * code. This replaces CycleDetector, which walked every edge type from plain
 * class nodes only, one query per node, and was never called.
 */
final class CycleFinder
{
    /**
     * @return list<array{members: list<string>, cycle: list<string>}> members sorted; cycle as a closed path (first repeated last); largest first
     */
    public function find(GraphStorage $storage, ?InboundIndex $index = null): array
    {
        $index ??= InboundIndex::of($storage);

        $nodes = [];
        $tests = TestCode::of($storage);
        foreach ($storage->nodes->declaredClasses() as $class) {
            if (!$tests->contains($class['file'])) {
                $nodes[$class['id']] = true;
            }
        }

        $adjacency = [];
        foreach (array_keys($nodes) as $id) {
            $targets = [];
            foreach ($index->outOf($id) as $edge) {
                if (isset($nodes[$edge['target']]) && $edge['target'] !== $id && self::isDependency($edge['type'], $edge['via'])) {
                    $targets[$edge['target']] = true;
                }
            }
            $adjacency[$id] = array_keys($targets);
        }

        $cycles = [];
        foreach ($this->stronglyConnected($adjacency) as $component) {
            if (count($component) < 2) {
                continue;
            }
            sort($component);
            $cycles[] = ['members' => $component, 'cycle' => $this->shortestCycle($component, $adjacency)];
        }

        usort($cycles, static fn (array $a, array $b): int => [count($b['members']), $a['members'][0]] <=> [count($a['members']), $b['members'][0]]);

        return $cycles;
    }

    /**
     * A bare Foo::class (a `references` edge whose strongest via is
     * class_name) is a name, not a dependency: `const OTHER = N::class` in M
     * and `const OTHER = M::class` in N were reported as a loop while
     * UnusedClassFinder called both "named only as a class name" (measured
     * 2026-10-02). Both finders now read it one way — a name is neither a use
     * nor a dependency — the reading the unused finder already had. The
     * storage keeps the strongest via of merged references as the edge's own
     * (GraphEdgeRepository::mergeMetadata), so `N::make(); N::class` still
     * reads as a static call here.
     */
    private static function isDependency(EdgeType $type, ?string $via = null): bool
    {
        return $type !== EdgeType::Imports
            && $type !== EdgeType::AnnotatedWith
            && !($type === EdgeType::References && $via === 'class_name')
            && match ($type->edgeClass()) {
                EdgeClass::CodeReference => true,
                EdgeClass::Wiring => in_array($type, [EdgeType::InjectsReadonly, EdgeType::InjectsMutable, EdgeType::InjectsFactory], true),
                default => false,
            };
    }

    /**
     * Tarjan's algorithm, iterative (a recursive walk over ~8k nodes can
     * exhaust the stack).
     *
     * @param array<string, list<string>> $adjacency
     * @return list<list<string>>
     */
    private function stronglyConnected(array $adjacency): array
    {
        $index = 0;
        $indices = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $components = [];

        foreach (array_keys($adjacency) as $start) {
            if (isset($indices[$start])) {
                continue;
            }
            $work = [[$start, 0]];
            while ($work !== []) {
                [$node, $next] = array_pop($work);
                if ($next === 0) {
                    $indices[$node] = $low[$node] = $index++;
                    $stack[] = $node;
                    $onStack[$node] = true;
                }
                $recursed = false;
                $neighbours = $adjacency[$node];
                for ($i = $next; $i < count($neighbours); $i++) {
                    $target = $neighbours[$i];
                    if (!isset($indices[$target])) {
                        $work[] = [$node, $i + 1];
                        $work[] = [$target, 0];
                        $recursed = true;
                        break;
                    }
                    if (isset($onStack[$target])) {
                        $low[$node] = min($low[$node], $indices[$target]);
                    }
                }
                if ($recursed) {
                    continue;
                }
                if ($low[$node] === $indices[$node]) {
                    $component = [];
                    do {
                        $member = array_pop($stack);
                        unset($onStack[$member]);
                        $component[] = $member;
                    } while ($member !== $node);
                    $components[] = $component;
                }
                if ($work !== []) {
                    $parent = $work[count($work) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }
            }
        }

        return $components;
    }

    /**
     * @param list<string> $component sorted
     * @param array<string, list<string>> $adjacency
     * @return list<string>
     */
    private function shortestCycle(array $component, array $adjacency): array
    {
        $inComponent = array_flip($component);
        $best = null;
        foreach ($component as $start) {
            $previous = [$start => null];
            $queue = [$start];
            while ($queue !== []) {
                $node = array_shift($queue);
                foreach ($adjacency[$node] as $target) {
                    if (!isset($inComponent[$target])) {
                        continue;
                    }
                    if ($target === $start) {
                        $path = [$start];
                        for ($step = $node; $step !== $start; $step = $previous[$step]) {
                            array_unshift($path, $step);
                        }
                        array_unshift($path, $start);
                        $path = array_values(array_unique($path));
                        $path[] = $start;
                        if ($best === null || count($path) < count($best)) {
                            $best = $path;
                        }
                        continue 3;
                    }
                    if (!array_key_exists($target, $previous)) {
                        $previous[$target] = $node;
                        $queue[] = $target;
                    }
                }
            }
            if ($best !== null && count($best) === 3) {
                break; // a two-class loop: nothing shorter exists
            }
        }

        return $best ?? [];
    }
}
