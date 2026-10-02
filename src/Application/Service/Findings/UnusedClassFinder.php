<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\Core\Registry\CanonicalRegistryPaths;
use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Coverage\UnreadCode;
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
 *   code the graph could not read can reach it;
 * - medium: nothing refers to it, but the framework discovers it (its node
 *   type or outgoing wiring says command, handler, listener, service, ...)
 *   — or only tests use it — or code the graph could not read may name it:
 *   a runtime class name in a module that depends on its module, a
 *   #[GraphIgnore]'d class or a shadowed duplicate there (see UnreadCode);
 * - low: it is named only as Foo::class, or its own module builds class
 *   names at runtime (a dynamic_reference gap), so it may be reached in a way
 *   the graph cannot see.
 *
 * Unread code elsewhere is medium, not low: its reach is a whole module and
 * everything that module depends on — a bound, not a sighting. Runtime names
 * in the class's own module stay low, as they were: the code that builds
 * them sits next to the class.
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

    /** Attributes PHP itself defines: they change how a class behaves, nothing looks classes up by them. */
    private const LANGUAGE_ATTRIBUTES = ['Attribute', 'AllowDynamicProperties', 'Deprecated', 'Override', 'ReturnTypeWillChange', 'SensitiveParameter', 'NoDiscard'];

    /**
     * @return list<array{id: string, fqcn: string, file: string, line: int, module: string, confidence: string, evidence: string}>
     */
    public function find(GraphStorage $storage, ?InboundIndex $index = null): array
    {
        $index ??= InboundIndex::of($storage);
        $unread = UnreadCode::of($storage, $index);
        $unreadIn = $this->unreadCodeByModule($storage, $unread);
        $ignored = [];
        foreach ($storage->gaps->findAll(CoverageGapKind::Ignored) as $gap) {
            $ignored[$gap->getSubject()] = true;
        }
        $attributes = $this->attributeClasses($storage, $index);

        $findings = [];
        $reaching = [];
        $tests = TestCode::of($storage);
        foreach ($storage->nodes->declaredClasses() as $class) {
            if ($tests->contains($class['file']) || isset($ignored[$class['fqcn']])) {
                continue;
            }

            $uses = $this->uses($index, $class['id']);
            if ($uses['production'] > 0) {
                continue;
            }

            $module = $unread->keyOfClass($class['id']) ?? $class['module'];
            $ownRuntimeNames = $unreadIn[CoverageGapKind::DynamicReference->value][$module] ?? 0;
            $reaching[$module] ??= $this->unreadReaching($module, $unreadIn, $unread);
            $wiring = $this->discoveryWiring($class['type'], $index->outOf($class['id']), $attributes, $class['fqcn']);
            [$confidence, $evidence] = match (true) {
                $uses['class_name'] > 0 => [self::LOW, sprintf('named only as a class name (Foo::class) %d time(s)', $uses['class_name'])],
                $wiring !== null => [self::MEDIUM, 'no code refers to it; kept alive by discovery: ' . $wiring],
                $uses['tests'] > 0 => [self::MEDIUM, sprintf('used only by tests (%d edge(s))', $uses['tests'])],
                $ownRuntimeNames > 0 => [self::LOW, sprintf('no reference found, but module %s builds %d class name(s) at runtime', UnreadCode::label($module), $ownRuntimeNames)],
                $reaching[$module] !== null => [self::MEDIUM, 'no reference found, but code the graph could not read may name it: ' . $reaching[$module]],
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
            // A `use` line is not a use: an import nothing else backs keeps a
            // dead class alive (measured 2026-10-02: Importer's dead `use
            // OnlyImported` hid OnlyImported). CycleFinder already left imports
            // out; both finders now agree. A Name::class inside an attribute
            // argument is no longer only an import: the extractor records it
            // as a reference (via: 'attribute').
            if (!$edge['sourceDeclared'] || $edge['source'] === $id || $edge['type'] === EdgeType::Imports || !$edge['type']->edgeClass()->isDependency()) {
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
     * @param array{declared: array<string, bool>} $attributes
     */
    private function discoveryWiring(string $nodeType, array $outgoing, array $attributes, string $fqcn = ''): ?string
    {
        // `registry:sync:contracts` writes resolvers and factories into this
        // namespace, and the container loads them by name
        // (App\Registry\Contracts\<Contract>Resolver) — no code names them.
        // Once a `$e::class` stopped counting as a runtime name, nine of them
        // graded HIGH (measured 2026-10-02).
        if (str_starts_with($fqcn, CanonicalRegistryPaths::REGISTRY_NAMESPACE . '\\')) {
            return 'generated into ' . CanonicalRegistryPaths::REGISTRY_NAMESPACE . ', loaded by the container by name';
        }
        $generic = [NodeType::Class_->value, NodeType::Interface_->value, NodeType::Trait_->value, NodeType::Enum_->value];
        if (!in_array($nodeType, $generic, true)) {
            return 'it is a ' . str_replace('_', ' ', $nodeType);
        }
        foreach ($outgoing as $edge) {
            if ($edge['type'] === EdgeType::AnnotatedWith && $edge['via'] === 'class' && $this->isDiscoveryAttribute($edge['target'], $attributes)) {
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

    /**
     * Whether a class-level attribute is one the framework can find classes
     * by — #[AsAiSkill], #[AsMapper], #[AsSitemapProvider], a project's own.
     *
     * It used to be a namespace pattern (`Semitexa\...\Attribute\`), which
     * missed attributes kept elsewhere: measured 2026-10-02,
     * `Semitexa\Ssr\Application\Service\Seo\Sitemap\AsSitemapProvider`
     * (SitemapProviderRegistry finds its classes by findClassesWithAttribute)
     * and `Semitexa\Testing\Attributes\*` graded their classes HIGH.
     *
     * Now: an attribute class the scan declared counts when it carries
     * #[Attribute] itself — the graph records that as an annotated_with edge,
     * so a declared class that is not an attribute never counts. One the scan
     * did not read (vendor/ in a consumer project) is known only by name; it
     * counts when it is a Semitexa class, the framework that does discovery.
     * PHP's own attributes and #[GraphIgnore] never count.
     *
     * @param array{declared: array<string, bool>} $attributes declared class id => carries #[Attribute]
     */
    private function isDiscoveryAttribute(string $target, array $attributes): bool
    {
        $fqcn = str_starts_with($target, 'class:') ? substr($target, 6) : $target;
        if ($fqcn === GraphIgnore::class || in_array($fqcn, self::LANGUAGE_ATTRIBUTES, true)) {
            return false;
        }
        if (isset($attributes['declared'][$target])) {
            return $attributes['declared'][$target];
        }

        return str_starts_with($fqcn, 'Semitexa\\');
    }

    /**
     * Every declared class, and whether it carries #[Attribute].
     *
     * @return array{declared: array<string, bool>}
     */
    private function attributeClasses(GraphStorage $storage, InboundIndex $index): array
    {
        $declared = [];
        foreach ($storage->nodes->declaredClasses() as $class) {
            $declared[$class['id']] = false;
            foreach ($index->outOf($class['id']) as $edge) {
                if ($edge['type'] === EdgeType::AnnotatedWith && $edge['target'] === 'class:Attribute') {
                    $declared[$class['id']] = true;
                    break;
                }
            }
        }

        return ['declared' => $declared];
    }

    /**
     * Gaps that are code the graph did not read, counted by the module key
     * of the file they are in.
     *
     * @return array<string, array<string, int>> gap kind => module key => count
     */
    private function unreadCodeByModule(GraphStorage $storage, UnreadCode $unread): array
    {
        $counts = [];
        foreach ([CoverageGapKind::DynamicReference, CoverageGapKind::Ignored, CoverageGapKind::DuplicateClass] as $kind) {
            foreach ($storage->gaps->findAll($kind) as $gap) {
                $module = $unread->keyOfFile($gap->getFile());
                $counts[$kind->value][$module] = ($counts[$kind->value][$module] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The unread code that could name a class of $module, as evidence text,
     * or null when there is none. Runtime names in $module itself are left out:
     * they grade low on their own.
     *
     * @param array<string, array<string, int>> $unreadIn
     */
    private function unreadReaching(string $module, array $unreadIn, UnreadCode $unread): ?string
    {
        $what = [
            CoverageGapKind::DynamicReference->value => 'runtime class name(s)',
            CoverageGapKind::Ignored->value          => '#[GraphIgnore]\'d class(es)',
            CoverageGapKind::DuplicateClass->value   => 'shadowed duplicate class(es)',
        ];
        $parts = [];
        foreach ($what as $kind => $label) {
            $count = 0;
            $where = [];
            foreach ($unreadIn[$kind] ?? [] as $from => $n) {
                if (($kind === CoverageGapKind::DynamicReference->value && $from === $module) || !$unread->reaches($from, $module)) {
                    continue;
                }
                $count += $n;
                $where[] = UnreadCode::label($from);
            }
            if ($count > 0) {
                sort($where);
                $shown = array_slice($where, 0, 3);
                $parts[] = sprintf('%d %s in %s%s', $count, $label, implode(', ', $shown), count($where) > 3 ? sprintf(' and %d more module(s)', count($where) - 3) : '');
            }
        }

        return $parts === [] ? null : implode('; ', $parts);
    }
}
