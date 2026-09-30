<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeClass;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Attribute\GraphIgnore;

/**
 * Classes nothing in production code depends on, graded by how sure that is.
 *
 * A class counts as used when a declared, non-test class depends on it — a
 * code reference (extends, new, type hint, static call, ...) or wiring
 * (injection, handles, listens_to, ...). What is left is graded:
 *
 * - high: nothing refers to it, the framework does not discover it, and no
 *   runtime class name in its module could reach it;
 * - medium: nothing refers to it, but the framework discovers it (its node
 *   type or outgoing wiring says command, handler, listener, service, ...)
 *   — or only tests use it;
 * - low: it is named only as Foo::class, or its module builds class names at
 *   runtime (a dynamic_reference gap), so it may be reached in a way the graph
 *   cannot see.
 *
 * Discovery wiring lives ON the discovered class, not as an edge pointing at
 * it, which is why "no inbound edge" alone would call every #[AsCommand] dead.
 * The manual dead-code sweep (ep-dead-code-sweep) found the same: class-level
 * attributes kept 390 of its 432 candidates alive.
 */
final class UnusedClassFinder
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /**
     * @return list<array{id: string, fqcn: string, file: string, line: int, module: string, confidence: string, evidence: string}>
     */
    public function find(GraphStorage $storage, ?InboundIndex $index = null): array
    {
        $index ??= InboundIndex::of($storage);
        $dynamicModules = $this->modulesWithRuntimeClassNames($storage);
        $ignored = [];
        foreach ($storage->gaps->findAll(CoverageGapKind::Ignored) as $gap) {
            $ignored[$gap->getSubject()] = true;
        }

        $findings = [];
        foreach ($storage->nodes->declaredClasses() as $class) {
            if (str_contains($class['file'], '/tests/') || isset($ignored[$class['fqcn']])) {
                continue;
            }

            $uses = $this->uses($index, $class['id']);
            if ($uses['production'] > 0) {
                continue;
            }

            $wiring = $this->discoveryWiring($class['type'], $index->outOf($class['id']));
            [$confidence, $evidence] = match (true) {
                $uses['class_name'] > 0 => [self::LOW, sprintf('named only as a class name (Foo::class) %d time(s)', $uses['class_name'])],
                $wiring !== null => [self::MEDIUM, 'no code refers to it; kept alive by discovery: ' . $wiring],
                $uses['tests'] > 0 => [self::MEDIUM, sprintf('used only by tests (%d edge(s))', $uses['tests'])],
                isset($dynamicModules[$class['module']]) => [self::LOW, sprintf('no reference found, but module %s builds %d class name(s) at runtime', $class['module'], $dynamicModules[$class['module']])],
                default => [self::HIGH, 'nothing refers to it and the framework does not discover it'],
            };

            $findings[] = $class + ['confidence' => $confidence, 'evidence' => $evidence];
        }

        return array_map(
            static fn (array $f): array => [
                'id' => $f['id'], 'fqcn' => $f['fqcn'], 'file' => $f['file'], 'line' => $f['line'],
                'module' => $f['module'], 'confidence' => $f['confidence'], 'evidence' => $f['evidence'],
            ],
            $findings,
        );
    }

    /** @return array{production: int, class_name: int, tests: int} */
    private function uses(InboundIndex $index, string $id): array
    {
        $uses = ['production' => 0, 'class_name' => 0, 'tests' => 0];
        foreach ($index->into($id) as $edge) {
            if (!$edge['sourceDeclared'] || $edge['source'] === $id || !$edge['type']->edgeClass()->isDependency()) {
                continue;
            }
            if ($edge['sourceInTests']) {
                $uses['tests']++;
            } elseif ($edge['type'] === EdgeType::References && $edge['via'] === 'class_name') {
                $uses['class_name']++;
            } else {
                $uses['production']++;
            }
        }

        return $uses;
    }

    /**
     * Why the framework will find this class on its own, or null.
     *
     * @param list<array{type: EdgeType, via: ?string, target: string}> $outgoing
     */
    private function discoveryWiring(string $nodeType, array $outgoing): ?string
    {
        $generic = [NodeType::Class_->value, NodeType::Interface_->value, NodeType::Trait_->value, NodeType::Enum_->value];
        if (!in_array($nodeType, $generic, true)) {
            return 'it is a ' . str_replace('_', ' ', $nodeType);
        }
        foreach ($outgoing as $edge) {
            // A framework attribute on the class itself: #[AsAiSkill],
            // #[AsMapper], #[Capability], ... — the framework finds the class
            // by it. The manual sweep's guard was the same (As*, Satisfies*).
            if ($edge['type'] === EdgeType::AnnotatedWith
                && $edge['via'] === 'class'
                && preg_match('/^class:Semitexa\\\\.+\\\\Attribute\\\\/', $edge['target']) === 1
                && $edge['target'] !== 'class:' . GraphIgnore::class
            ) {
                return 'it carries #[' . substr($edge['target'], (int) strrpos($edge['target'], '\\') + 1) . ']';
            }
        }
        foreach ($outgoing as $edge) {
            if ($edge['type']->edgeClass() === EdgeClass::Wiring && !in_array($edge['type'], [EdgeType::InjectsReadonly, EdgeType::InjectsMutable, EdgeType::InjectsFactory, EdgeType::InjectsConfig], true)) {
                return 'it declares ' . $edge['type']->value;
            }
        }

        return null;
    }

    /** @return array<string, int> module => runtime class names found in it */
    private function modulesWithRuntimeClassNames(GraphStorage $storage): array
    {
        $moduleOfFile = [];
        foreach ($storage->nodes->declaredClasses() as $class) {
            $moduleOfFile[$class['file']] ??= $class['module'];
        }

        $modules = [];
        foreach ($storage->gaps->findAll(CoverageGapKind::DynamicReference) as $gap) {
            $module = $moduleOfFile[$gap->getFile()] ?? '';
            if ($module !== '') {
                $modules[$module] = ($modules[$module] ?? 0) + 1;
            }
        }

        return $modules;
    }
}
