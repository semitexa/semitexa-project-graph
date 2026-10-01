<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageGapKind;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * Removals that leave something behind pointing at nothing.
 *
 * Removing an edge is not a problem by itself — deleting a route, a handler or
 * a listener is often the point of a change. It becomes one when the head
 * graph still has something that relies on what went away:
 *
 * - a payload that still serves a route but lost its only handler;
 * - a contract that is still injected but lost its only implementation;
 * - an event that is still listened to but no longer emitted by anyone;
 * - a route that something still points at but that nothing serves;
 * - a class that was declared and is gone, while declared classes still
 *   reference it.
 *
 * Only these fail the gate. There is no rule of the "mention it in the pull
 * request description" kind: the gate checks the code, not the prose
 * (suggested in review of the idea by Andrii Liubashchenko).
 */
final class OrphanedRemovals
{
    /**
     * @return list<array{kind: string, subject: string, removed: string, still_used_by: list<string>}>
     */
    public static function find(EdgeSetDiff $diff, GraphStorage $base, GraphStorage $head): array
    {
        $orphans = [];

        foreach ($diff->removed as $edge) {
            $orphan = match ($edge->getType()) {
                EdgeType::Handles => self::unless($head, $edge->getTargetId(), [EdgeType::Handles], 'unhandled_payload', [EdgeType::ServesRoute], outgoing: true),
                EdgeType::SatisfiesContract => self::unless($head, $edge->getTargetId(), [EdgeType::SatisfiesContract], 'unsatisfied_contract', [EdgeType::InjectsReadonly, EdgeType::InjectsMutable, EdgeType::InjectsFactory]),
                EdgeType::Emits => self::unless($head, $edge->getTargetId(), [EdgeType::Emits], 'unemitted_event', [EdgeType::ListensTo]),
                EdgeType::ServesRoute => self::unless($head, $edge->getTargetId(), [EdgeType::ServesRoute], 'unserved_route', null),
                default => null,
            };
            if ($orphan !== null) {
                $orphans[$orphan['kind'] . ' ' . $orphan['subject']] = $orphan + ['removed' => self::describe($edge)];
            }
        }

        foreach (self::vanishedClasses($base, $head) as $id) {
            $users = self::declaredSources($head, $head->edges->findByTarget($id), null);
            if ($users !== []) {
                $orphans['deleted_class_still_referenced ' . $id] = [
                    'kind'          => 'deleted_class_still_referenced',
                    'subject'       => $id,
                    'still_used_by' => $users,
                    'removed'       => 'the declaration of ' . $id,
                ];
            }
        }

        ksort($orphans);

        return array_values(array_map(
            static fn (array $o): array => ['kind' => $o['kind'], 'subject' => $o['subject'], 'removed' => $o['removed'], 'still_used_by' => $o['still_used_by']],
            $orphans,
        ));
    }

    /**
     * Files the head graph could not parse: a gate cannot vouch for code it
     * did not read, so these make it fail rather than pass.
     *
     * @return list<string>
     */
    public static function unreadableFiles(GraphStorage $head): array
    {
        return array_values(array_unique(array_map(
            static fn ($gap): string => $gap->getFile(),
            $head->gaps->findAll(CoverageGapKind::ParseError),
        )));
    }

    /**
     * $target is orphaned when nothing in the head graph provides it any more
     * (no $providers edge into it) while something still relies on it
     * ($relyingTypes edges into it — or out of it, for a payload that still
     * serves a route; null: any edge into it from a declared node).
     *
     * @param list<EdgeType> $providers
     * @param ?list<EdgeType> $relyingTypes
     * @return ?array{kind: string, subject: string, still_used_by: list<string>}
     */
    private static function unless(GraphStorage $head, string $target, array $providers, string $kind, ?array $relyingTypes, bool $outgoing = false): ?array
    {
        foreach ($providers as $type) {
            if ($head->edges->findByTarget($target, $type) !== []) {
                return null;
            }
        }

        if ($outgoing) {
            $node = $head->nodes->findById($target);
            if ($node === null || $node->getIsPlaceholder()) {
                return null; // the payload itself went away: nothing is left unhandled
            }
            $relying = [];
            foreach ($relyingTypes ?? [] as $type) {
                foreach ($head->edges->findBySource($target, $type) as $edge) {
                    $relying[] = $edge->getTargetId();
                }
            }
            return $relying === [] ? null : ['kind' => $kind, 'subject' => $target, 'still_used_by' => array_values(array_unique($relying))];
        }

        $users = self::declaredSources($head, $head->edges->findByTarget($target), $relyingTypes);

        return $users === [] ? null : ['kind' => $kind, 'subject' => $target, 'still_used_by' => $users];
    }

    /**
     * @param list<Edge> $edges
     * @param ?list<EdgeType> $types
     * @return list<string>
     */
    private static function declaredSources(GraphStorage $head, array $edges, ?array $types): array
    {
        $sources = [];
        foreach ($edges as $edge) {
            if ($types !== null && !in_array($edge->getType(), $types, true)) {
                continue;
            }
            if ($types === null && !$edge->getType()->edgeClass()->isDependency()) {
                continue;
            }
            $source = $head->nodes->findById($edge->getSourceId());
            if ($source !== null && !$source->getIsPlaceholder()) {
                $sources[$edge->getSourceId()] = true;
            }
        }
        ksort($sources);

        return array_keys($sources);
    }

    /** @return list<string> class nodes declared in base and not declared in head */
    private static function vanishedClasses(GraphStorage $base, GraphStorage $head): array
    {
        $declared = static function (GraphStorage $graph): array {
            $ids = [];
            foreach ($graph->nodes->classLikeFqcns() as $row) {
                if (!$row['placeholder']) {
                    $ids['class:' . $row['fqcn']] = true;
                }
            }
            return $ids;
        };

        return array_keys(array_diff_key($declared($base), $declared($head)));
    }

    private static function describe(Edge $edge): string
    {
        return $edge->getType()->value . ' ' . $edge->getSourceId() . ' -> ' . $edge->getTargetId();
    }
}
