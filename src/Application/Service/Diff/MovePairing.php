<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeClass;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphDiff;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * Renames and moves, paired before the orphan gate runs — like `git -M`.
 *
 * Node ids are FQCN-based, so moving OrderHandler to another module reads as
 * every one of its edges removed and the same edges added. Reported raw, that
 * is noise at best and a false orphan at worst. Specified by Andrii
 * Liubashchenko (LinkedIn, 2026-10-01):
 *
 * - a removed and an added edge of the same type whose other end is the same
 *   node (same route, event, payload) count as a move; content hashes break
 *   ties (a class split, two handlers moved at once);
 * - only unpaired edges go to the orphan check;
 * - a move that also changes the far end (handler moved AND route renamed in
 *   one commit) stays unpaired, and is reported as a warning with both edges
 *   side by side, not a block;
 * - a detected move is its own line naming from and to, because a move that
 *   keeps every edge can still change behaviour (another module, another
 *   middleware order).
 */
final class MovePairing
{
    /**
     * @param array<string, string> $baseBodies class node id => body hash, see bodyHashes()
     * @param array<string, string> $headBodies
     */
    public static function pair(EdgeSetDiff $diff, GraphStorage $base, GraphStorage $head, array $baseBodies = [], array $headBodies = []): MovedEdges
    {
        $moves = self::nodeMoves($base, $head, $baseBodies, $headBodies);
        $map = static fn (string $id): string => $moves[$id]['to'] ?? $id;

        $added = [];
        foreach ($diff->added as $edge) {
            $added[GraphDiff::edgeKey($edge)] = $edge;
        }

        // 1. An edge that a node move turns into an added edge is the same edge, moved.
        $pairs = [];
        $removed = [];
        foreach ($diff->removed as $edge) {
            $key = GraphDiff::edgeKey(new Edge($map($edge->getSourceId()), $map($edge->getTargetId()), $edge->getType()));
            if (($edge->getSourceId() !== $map($edge->getSourceId()) || $edge->getTargetId() !== $map($edge->getTargetId())) && isset($added[$key])) {
                $pairs[] = ['removed' => $edge, 'added' => $added[$key]];
                unset($added[$key]);
                continue;
            }
            $removed[] = $edge;
        }

        // 2. Wiring whose far end stayed while the class at the near end was
        //    REPLACED: the old one is gone from head, the new one is new in it
        //    (a handler deleted and another written for the same payload).
        //    Both must hold: between two classes that both survive this is a
        //    rewiring, and pairing it hid the change and then reported
        //    "Unchanged: handlers" (measured 2026-10-02, round 2). Shown as
        //    "replaced", never folded away.
        $declaredBase = self::declared($base);
        $declaredHead = self::declared($head);
        $addedByTarget = [];
        foreach ($added as $key => $a) {
            $addedByTarget[$a->getType()->value . '|' . $a->getTargetId()][$key] = $a;
        }
        $replaced = [];
        $stillRemoved = [];
        foreach ($removed as $edge) {
            $bucket = $addedByTarget[$edge->getType()->value . '|' . $edge->getTargetId()] ?? [];
            $gone = isset($declaredBase[$edge->getSourceId()]) && !isset($declaredHead[$edge->getSourceId()]);
            $candidates = !$gone || $edge->getType()->edgeClass() !== EdgeClass::Wiring ? [] : array_filter(
                $bucket,
                static fn (Edge $a): bool => isset($added[GraphDiff::edgeKey($a)])
                    && isset($declaredHead[$a->getSourceId()]) && !isset($declaredBase[$a->getSourceId()]),
            );
            $match = self::onlyOne($candidates, $baseBodies[$edge->getSourceId()] ?? null, $headBodies);
            if ($match === null) {
                $stillRemoved[] = $edge;
                continue;
            }
            $replaced[] = ['removed' => $edge, 'added' => $added[$match]];
            unset($added[$match]);
        }

        // 3. A moved class whose wiring also changed its far end: a warning,
        //    side by side, one to one, and unpaired. Only wiring: a domain or
        //    doc node "changes" with every cross-module move (round 2: every
        //    move warned, one rewiring warned ten times).
        $addedBySource = [];
        foreach ($added as $key => $a) {
            if ($a->getType()->edgeClass() === EdgeClass::Wiring) {
                $addedBySource[$a->getType()->value . '|' . $a->getSourceId()][$key] = $a;
            }
        }
        $farEnd = [];
        foreach ($stillRemoved as $edge) {
            $to = $moves[$edge->getSourceId()]['to'] ?? null;
            if ($to === null || $edge->getType()->edgeClass() !== EdgeClass::Wiring) {
                continue;
            }
            foreach ($addedBySource[$edge->getType()->value . '|' . $to] ?? [] as $key => $a) {
                if ($a->getTargetId() !== $map($edge->getTargetId())) {
                    $farEnd[] = ['removed' => $edge, 'added' => $a];
                    unset($addedBySource[$edge->getType()->value . '|' . $to][$key]);
                    break;
                }
            }
        }

        $moved = [];
        foreach ($moves as $from => $move) {
            $kept = [];
            foreach ($pairs as $pair) {
                if ($pair['removed']->getSourceId() === $from || $pair['removed']->getTargetId() === $from) {
                    $kept[$pair['removed']->getType()->value] = true;
                }
            }
            ksort($kept);
            $moved[] = $move + ['kept' => array_keys($kept)];
        }

        return new MovedEdges(
            pairs: $pairs,
            farEndChanged: $farEnd,
            movedNodes: $moved,
            remaining: new EdgeSetDiff(array_values($added), $stillRemoved),
            replaced: $replaced,
        );
    }

    /**
     * A hash of each declared class's source with its own name taken out, so
     * the same class under another name or namespace hashes the same. The span
     * starts at the attributes (php-parser counts them in), so the name is
     * replaced in the declaration rather than skipped by line. Taken while the
     * checkout exists — the base snapshot is gone once the diff runs.
     *
     * @return array<string, string>
     */
    public static function bodyHashes(GraphStorage $graph, string $root): array
    {
        $hashes = [];
        $files = [];
        foreach ($graph->nodes->declaredClasses() as $row) {
            $node = $graph->nodes->findById($row['id']);
            if ($node === null || $node->getLine() < 1 || $node->getEndLine() < $node->getLine()) {
                continue;
            }
            $path = str_starts_with($node->getFile(), '/') ? $node->getFile() : rtrim($root, '/') . '/' . $node->getFile();
            $files[$path] ??= is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
            $span = array_slice($files[$path], $node->getLine() - 1, $node->getEndLine() - $node->getLine() + 1);
            if ($span === []) {
                continue;
            }
            $source = implode("\n", array_map('trim', $span));
            // Every mention of its own name, not only the declaration: a class
            // returning `new Money(0)` never paired after a rename.
            $source = (string) preg_replace('/\b' . preg_quote(self::shortName($row['fqcn']), '/') . '\b/', '_', $source);
            // A near-empty body proves nothing: two empty classes hash alike
            // and a deleted one was credited with another's rename.
            if (strlen((string) preg_replace('/\s+/', '', $source)) < 48) {
                continue;
            }
            $hashes[$row['id']] = sha1($source);
        }

        return $hashes;
    }

    /**
     * Class nodes declared in base and not in head, matched to ones declared
     * in head and not in base: by short name when that is unambiguous, by body
     * hash when it is not (or when the class was renamed).
     *
     * @param array<string, string> $baseBodies
     * @param array<string, string> $headBodies
     * @return array<string, array{from: string, to: string, from_module: string, to_module: string}>
     */
    private static function nodeMoves(GraphStorage $base, GraphStorage $head, array $baseBodies, array $headBodies): array
    {
        $declared = static function (GraphStorage $graph): array {
            $out = [];
            foreach ($graph->nodes->declaredClasses() as $row) {
                $out[$row['id']] = $row;
            }
            return $out;
        };
        $before = $declared($base);
        $after = $declared($head);
        $vanished = array_diff_key($before, $after);
        $appeared = array_diff_key($after, $before);

        // Indexed, not filtered per class: moving 6000 classes cost 47 s in
        // these loops alone (round 2).
        /** @var array<string, array<string, true>> $appearedByShort */
        $appearedByShort = [];
        /** @var array<string, array<string, true>> $appearedByHash */
        $appearedByHash = [];
        foreach ($appeared as $id => $row) {
            $appearedByShort[self::shortName($row['fqcn'])][$id] = true;
            if (isset($headBodies[$id])) {
                $appearedByHash[$headBodies[$id]][$id] = true;
            }
        }
        /** @var array<string, array<string, true>> $vanishedByHash */
        $vanishedByHash = [];
        foreach ($vanished as $id => $row) {
            if (isset($baseBodies[$id])) {
                $vanishedByHash[$baseBodies[$id]][$id] = true;
            }
        }

        $moves = [];
        foreach ($vanished as $id => $row) {
            $hash = $baseBodies[$id] ?? null;
            $byName = array_intersect_key($appearedByShort[self::shortName($row['fqcn'])] ?? [], $appeared);
            // A hash is evidence only when it is unique on BOTH sides: two
            // vanished classes with one body cannot both have become one class.
            $byHash = $hash !== null && count($vanishedByHash[$hash] ?? []) === 1
                ? array_intersect_key($appearedByHash[$hash] ?? [], $appeared)
                : [];

            $to = null;
            $named = count($byName);
            if ($named === 1) {
                $to = array_key_first($byName);
            } elseif ($named > 1) {
                $both = array_intersect_key($byName, $byHash);
                $to = count($both) === 1 ? array_key_first($both) : null;
            } elseif (count($byHash) === 1) {
                $to = array_key_first($byHash);
            }
            if ($to === null) {
                continue;
            }
            $moves[$id] = ['from' => $id, 'to' => $to, 'from_module' => $row['module'], 'to_module' => $appeared[$to]['module']];
            unset($appeared[$to]);
        }
        ksort($moves);

        return $moves;
    }

    /**
     * @param array<string, Edge>   $candidates keyed by edge key
     * @param array<string, string> $headBodies
     */
    private static function onlyOne(array $candidates, ?string $removedSourceHash, array $headBodies): ?string
    {
        if (count($candidates) === 1) {
            return (string) array_key_first($candidates);
        }
        if ($candidates === [] || $removedSourceHash === null) {
            return null;
        }
        $same = array_filter($candidates, static fn (Edge $e): bool => ($headBodies[$e->getSourceId()] ?? null) === $removedSourceHash);

        return count($same) === 1 ? (string) array_key_first($same) : null;
    }

    /** @return array<string, true> declared class ids */
    private static function declared(GraphStorage $graph): array
    {
        $ids = [];
        foreach ($graph->nodes->declaredClasses() as $row) {
            $ids[$row['id']] = true;
        }

        return $ids;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
