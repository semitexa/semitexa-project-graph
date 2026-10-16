<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Intelligence;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\NodeType;
use Semitexa\ProjectGraph\Application\Service\Query\Direction;
use Semitexa\ProjectGraph\Application\Service\Query\QueryInterface;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * A flow, walked out of the edges the extractors already wrote.
 *
 * Read at query time on purpose. Every stored edge belongs to the file that
 * produced it and is rebuilt with that file; a handler→event→listener edge
 * spans three files, so storing it would leave it stale as soon as one of
 * them changed. The flow node itself only names its payload; everything
 * else here is derived on each call.
 *
 * From the handler the walk follows injections, references and
 * instantiations through services, up to MAX_DEPTH hops, and stops at an
 * entity: that is where the flow touches storage. An injected interface is
 * crossed only when one class can be behind it — the one the container is
 * told to use (#[SatisfiesServiceContract] or #[SatisfiesRepositoryContract]),
 * or else its only implementation.
 *
 * Reachability, not execution: the graph has no method nodes and no call
 * edges, so a handler that injects a service able to write is credited with
 * the write even when it only reads. On Tasks (2026-10-16) the two GET flows
 * were given os_process and ResourceChangedEvent that only save() causes.
 * Tables and events are therefore "can reach" / "may fire"; the steps,
 * listeners and gaps are what the graph actually holds.
 *
 * Wherever the walk cannot vouch for what comes next it records a gap
 * instead of going quiet, so a flow with no gaps is a claim the graph can
 * stand behind and a flow with gaps says where it stopped seeing.
 *
 * Framework infrastructure is a door, not part of the flow. A class that
 * INFRASTRUCTURE_FAN_IN or more other modules import (OrmManager: 23,
 * Environment: 21, TenantContextStoreInterface: 13; a business service: 1-3)
 * is walked through for the events it emits — the ORM's ResourceChangedEvent
 * is a real consequence of saving — but its own tables and its own gaps are
 * the framework's, not the flow's. Without that a task save listed 65 gaps,
 * 60 of them inside the ORM (measured 2026-10-16).
 *
 * A listener that may not act (GAP_CONDITIONAL_LISTENER) is a step, but the
 * walk does not enter it: what it touches is the listener's business, and
 * its tables listed beside the flow's own would read as certain.
 */
final class FlowTracer
{
    /**
     * Measured 2026-10-16 over the workspace's 349 flows, infrastructure cut off:
     * depth 4 left 119 flows at the limit and 134 complete; 6 left 22 and 188;
     * 8 reached more events than it explained and dropped to 131 complete.
     */
    private const MAX_DEPTH = 6;

    /** Measured 2026-10-16: 103 of ~850 walkable classes are imported by 5+ other modules. */
    private const INFRASTRUCTURE_FAN_IN = 5;

    private const FOLLOW = [
        EdgeType::InjectsReadonly,
        EdgeType::InjectsMutable,
        EdgeType::InjectsFactory,
        EdgeType::References,
        EdgeType::Instantiates,
    ];

    private const WALK_THROUGH = [
        NodeType::Service,
        NodeType::Repository,
        NodeType::Class_,
    ];

    /**
     * No handler declares this payload and it names no response to render
     * either: the request has nowhere to go that the graph knows of. A payload
     * with `responseWith:` and no handler is a page its response renders.
     */
    public const GAP_NO_HANDLER = 'no_handler';
    /** Two or more classes can stand behind an injected interface; which one runs is not in the graph. */
    public const GAP_AMBIGUOUS_CONTRACT = 'ambiguous_contract';
    /** A project interface is injected and nothing in the graph implements it. */
    public const GAP_UNBOUND_CONTRACT = 'unbound_contract';
    /** The walk stopped at MAX_DEPTH with classes still left to follow. */
    public const GAP_DEPTH_LIMIT = 'depth_limit';
    /** A class on the way names a class only at runtime (CoverageGapKind::DynamicReference). */
    public const GAP_DYNAMIC_REFERENCE = 'dynamic_reference';
    /** An event is emitted and the graph knows no listener of it. */
    public const GAP_EVENT_WITHOUT_LISTENER = 'event_without_listener';
    /**
     * The event comes from another module than the flow's handler — framework
     * infrastructure such as the ORM's ResourceChangedEvent, fired for every
     * write. A listener of it hears every flow and decides at runtime whether
     * this one concerns it, so it may act here or not.
     */
    public const GAP_CONDITIONAL_LISTENER = 'conditional_listener';

    /** @var array<string, list<array{line: int, detail: string}>>|null */
    private ?array $dynamicBySubject = null;

    /** @var array<string, bool> */
    private array $infrastructure = [];

    /** @var array<string, ?Node> One tracer serves every flow of a report; most share their services. */
    private array $nodes = [];

    /** @var array<string, list<\Semitexa\ProjectGraph\Domain\Model\Edge>> */
    private array $outgoing = [];

    public function __construct(
        private readonly QueryInterface $query,
    ) {}

    public function trace(Node $flow): ExecutionFlow
    {
        $meta = $flow->getMetadata();
        $steps = [];
        $gaps = [];
        $syncBoundary = null;
        $handlers = [];

        $members = $this->query->getEdges($flow->getId(), EdgeType::ParticipatesInFlow->value, Direction::Incoming);
        foreach (['entry', 'handler'] as $role) {
            foreach ($members as $edge) {
                if (($edge->getMetadata()['role'] ?? '') !== $role) {
                    continue;
                }
                $node = $this->query->getNode($edge->getSourceId());
                if ($node === null) {
                    continue;
                }
                $async = $role === 'handler' && ($edge->getMetadata()['execution'] ?? 'sync') !== 'sync';
                if ($async && $syncBoundary === null) {
                    $syncBoundary = count($steps);
                }
                $steps[] = ['order' => count($steps) + 1, 'node' => $node->getFqcn() ?: $node->getId(), 'role' => $role === 'entry' ? 'payload' : 'handler'];
                if ($role === 'handler') {
                    $handlers[$node->getId()] = $node->getModule();
                }
            }
        }
        if ($handlers === []) {
            $payload = $this->node('class:' . substr($flow->getId(), strlen('flow:')));
            $response = (string) ($payload?->getMetadata()['responseClass'] ?? '');
            if ($response !== '') {
                $steps[] = ['order' => count($steps) + 1, 'node' => $response, 'role' => 'response'];
            } else {
                $gaps[] = self::gap(self::GAP_NO_HANDLER, $flow->getId(), 'No #[AsPayloadHandler] declares this payload, and it names no responseWith');
            }
        }

        $tables = [];
        $events = [];
        $walked = [];
        $doors = [];
        $pending = $this->reach(array_keys($handlers), $tables, $gaps, $walked, $doors);
        while ($pending !== []) {
            [$eventId, $emitterId] = array_shift($pending);
            if (isset($events[$eventId])) {
                continue;
            }
            $event = $this->query->getNode($eventId);
            $events[$eventId] = $event?->getFqcn() ?: substr($eventId, strlen('class:'));

            $emitterModule = $this->query->getNode($emitterId)?->getModule() ?? '';
            $certain = in_array($emitterModule, $handlers, true);

            $listeners = $this->query->getEdges($eventId, EdgeType::ListensTo->value, Direction::Incoming);
            if ($listeners === []) {
                $gaps[] = self::gap(self::GAP_EVENT_WITHOUT_LISTENER, $eventId, 'Emitted by ' . self::shortName($emitterId) . '; no #[AsEventListener] for it');
            }
            foreach ($listeners as $edge) {
                $listener = $this->query->getNode($edge->getSourceId());
                if ($listener === null) {
                    continue;
                }
                $mode = (string) ($edge->getMetadata()['executionMode'] ?? 'sync');
                if ($mode !== 'sync' && $syncBoundary === null) {
                    $syncBoundary = count($steps);
                }
                $steps[] = [
                    'order' => count($steps) + 1,
                    'node' => $listener->getFqcn() ?: $listener->getId(),
                    'role' => 'listener',
                    'event' => $events[$eventId],
                    'execution' => $mode,
                    'certain' => $certain,
                ];
                if (!$certain) {
                    $gaps[] = self::gap(self::GAP_CONDITIONAL_LISTENER, $listener->getId(), sprintf(
                        '%s comes from %s (module %s), not from this flow\'s module; the listener decides at runtime whether it acts',
                        self::shortName($eventId),
                        self::shortName($emitterId),
                        $emitterModule !== '' ? $emitterModule : '?',
                    ));
                    continue;
                }
                array_push($pending, ...$this->reach([$listener->getId()], $tables, $gaps, $walked, $doors));
            }
        }

        foreach (array_keys($walked) as $id) {
            foreach ($this->dynamicReferencesIn(substr($id, strlen('class:'))) as $ref) {
                $gaps[] = self::gap(self::GAP_DYNAMIC_REFERENCE, $id, $ref['detail'] . ' (line ' . $ref['line'] . ')');
            }
        }

        ksort($tables);
        ksort($doors);

        return new ExecutionFlow(
            id: $flow->getId(),
            name: (string) ($meta['name'] ?? $flow->getId()),
            entryPoint: (string) ($meta['entry_point'] ?? ''),
            steps: $steps,
            storageTouches: array_keys($tables),
            externalCalls: [],
            syncBoundary: $syncBoundary,
            eventsEmitted: array_values($events),
            gaps: self::unique($gaps),
            infrastructure: array_keys($doors),
        );
    }

    /**
     * Breadth-first from the given classes. Adds every table reached to
     * $tables, every class walked to $walked, every place it could not see
     * past to $gaps, every infrastructure class entered to $doors, and
     * returns the events emitted on the way with their emitter.
     *
     * Past an infrastructure class only events are collected.
     *
     * @param list<string> $startIds
     * @param array<string, true> $tables
     * @param list<array{kind: string, at: string, detail: string, candidates?: list<string>}> $gaps
     * @param array<string, true> $walked
     * @param array<string, true> $doors
     * @return list<array{0: string, 1: string}>
     */
    private function reach(array $startIds, array &$tables, array &$gaps, array &$walked, array &$doors): array
    {
        $depth = array_fill_keys($startIds, 0);
        $inside = [];
        $queue = $startIds;
        $events = [];

        while ($queue !== []) {
            $id = array_shift($queue);
            $infra = isset($inside[$id]);
            if (!$infra) {
                $walked[$id] = true;
            }
            $d = $depth[$id];
            $cutOff = false;
            foreach ($this->outgoing[$id] ??= $this->query->getEdges($id, null, Direction::Outgoing) as $edge) {
                $type = $edge->getType();
                $target = $edge->getTargetId();
                if ($type === EdgeType::Emits) {
                    $events[$target] ??= [$target, $id];
                    continue;
                }
                if (isset($depth[$target]) || !in_array($type, self::FOLLOW, true)) {
                    continue;
                }
                $node = $this->node($target);
                if ($node === null || $node->getIsPlaceholder()) {
                    continue;
                }
                if ($node->getType() === NodeType::Entity) {
                    $depth[$target] = $d + 1;
                    if (!$infra) {
                        foreach ($this->tablesOf($node) as $table) {
                            $tables[$table] = true;
                        }
                    }
                    continue;
                }
                if ($d >= self::MAX_DEPTH) {
                    $cutOff = $cutOff || in_array($node->getType(), [...self::WALK_THROUGH, NodeType::Interface_], true);
                    continue;
                }
                $enters = $infra;
                if (!$enters && $this->isInfrastructure($node)) {
                    $enters = true;
                    if ($this->isDoor($node)) {
                        $doors[$node->getFqcn()] = true;
                    }
                }
                if ($node->getType() === NodeType::Interface_) {
                    $depth[$target] = $d + 1;
                    $seen = [];
                    $node = $this->onlyClassBehind($node, $seen);
                    if (!$enters) {
                        array_push($gaps, ...$seen);
                    }
                    if ($node === null || isset($depth[$node->getId()])) {
                        continue;
                    }
                }
                if (!in_array($node->getType(), self::WALK_THROUGH, true)) {
                    continue;
                }
                $depth[$node->getId()] = $d + 1;
                if ($enters) {
                    $inside[$node->getId()] = true;
                }
                $queue[] = $node->getId();
            }
            if ($cutOff && !$infra) {
                $gaps[] = self::gap(self::GAP_DEPTH_LIMIT, $id, sprintf('Not followed past %d hops from the handler', self::MAX_DEPTH));
            }
        }

        return array_values($events);
    }

    private function node(string $id): ?Node
    {
        return array_key_exists($id, $this->nodes) ? $this->nodes[$id] : $this->nodes[$id] = $this->query->getNode($id);
    }

    /** Imported by INFRASTRUCTURE_FAN_IN or more modules other than its own, tests aside. */
    private function isInfrastructure(Node $node): bool
    {
        if (isset($this->infrastructure[$node->getId()])) {
            return $this->infrastructure[$node->getId()];
        }
        $modules = [];
        foreach ($this->query->getEdges($node->getId(), EdgeType::Imports->value, Direction::Incoming) as $edge) {
            if (str_contains($edge->getSourceId(), '\\Tests\\')) {
                continue;
            }
            $module = $this->node($edge->getSourceId())?->getModule() ?? '';
            if ($module !== '' && $module !== $node->getModule()) {
                $modules[$module] = true;
            }
        }

        return $this->infrastructure[$node->getId()] = count($modules) >= self::INFRASTRUCTURE_FAN_IN;
    }

    /** A class the flow goes through, not an attribute or enum it merely names. */
    private function isDoor(Node $node): bool
    {
        if (!in_array($node->getType(), [...self::WALK_THROUGH, NodeType::Interface_], true)) {
            return false;
        }
        foreach ($this->query->getEdges($node->getId(), EdgeType::AnnotatedWith->value, Direction::Outgoing) as $edge) {
            if ($edge->getTargetId() === 'class:Attribute') {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function tablesOf(Node $entity): array
    {
        $tables = [];
        foreach ($this->query->getEdges($entity->getId(), EdgeType::MapsToTable->value, Direction::Outgoing) as $edge) {
            $tables[] = str_starts_with($edge->getTargetId(), 'table:') ? substr($edge->getTargetId(), strlen('table:')) : $edge->getTargetId();
        }

        // An entity with no #[FromTable] still means storage was touched.
        return $tables !== [] ? $tables : [$entity->getFqcn() ?: $entity->getId()];
    }

    /** @param list<array{kind: string, at: string, detail: string, candidates?: list<string>}> $gaps */
    private function onlyClassBehind(Node $interface, array &$gaps): ?Node
    {
        $bound = $this->query->getContractImplementors($interface->getFqcn());
        // The container's own rule (ServiceContractRegistry): Core is not a
        // registered module, ranks last and loses to any module's binding.
        $overriding = array_values(array_filter($bound, static fn (Node $n): bool => $n->getModule() !== 'Core'));
        if ($overriding !== [] && count($overriding) < count($bound)) {
            $bound = $overriding;
        }
        $candidates = $bound;
        if ($bound === []) {
            foreach ($this->query->getEdges($interface->getId(), EdgeType::Implements->value, Direction::Incoming) as $edge) {
                $node = $this->query->getNode($edge->getSourceId());
                if ($node !== null && !str_contains($node->getFqcn(), '\\Tests\\')) {
                    $candidates[] = $node;
                }
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates === []) {
            $gaps[] = self::gap(self::GAP_UNBOUND_CONTRACT, $interface->getId(), 'Injected, and no scanned class implements it');
        } else {
            $names = array_map(static fn (Node $n): string => $n->getFqcn(), $candidates);
            sort($names);
            $gaps[] = self::gap(
                self::GAP_AMBIGUOUS_CONTRACT,
                $interface->getId(),
                $bound !== []
                    ? sprintf('%d modules bind it with #[SatisfiesServiceContract] or #[SatisfiesRepositoryContract]; the winner follows the modules\' extends order, which the graph does not hold', count($names))
                    : sprintf('%d classes implement it and none is bound with #[SatisfiesServiceContract] or #[SatisfiesRepositoryContract]', count($names)),
            ) + ['candidates' => $names];
        }

        return null;
    }

    /** @return list<array{line: int, detail: string}> */
    private function dynamicReferencesIn(string $fqcn): array
    {
        if ($this->dynamicBySubject === null) {
            $this->dynamicBySubject = [];
            foreach ($this->query->coverageGaps(CoverageGapKind::DynamicReference) as $gap) {
                if ($gap->getSubject() !== '') {
                    $this->dynamicBySubject[$gap->getSubject()][] = ['line' => $gap->getLine(), 'detail' => $gap->getDetail()];
                }
            }
        }

        return $this->dynamicBySubject[$fqcn] ?? [];
    }

    /** @return array{kind: string, at: string, detail: string} */
    private static function gap(string $kind, string $at, string $detail): array
    {
        return ['kind' => $kind, 'at' => $at, 'detail' => $detail];
    }

    /**
     * @param list<array{kind: string, at: string, detail: string, candidates?: list<string>}> $gaps
     * @return list<array{kind: string, at: string, detail: string, candidates?: list<string>}>
     */
    private static function unique(array $gaps): array
    {
        $seen = [];
        foreach ($gaps as $gap) {
            $seen[$gap['kind'] . "\0" . $gap['at'] . "\0" . $gap['detail']] ??= $gap;
        }

        return array_values($seen);
    }

    private static function shortName(string $id): string
    {
        $parts = explode('\\', $id);

        return (string) end($parts);
    }
}
