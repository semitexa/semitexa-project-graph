<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Diff;

use Semitexa\ProjectGraph\Application\Service\Coverage\CoverageReport;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeClass;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * An edge diff as a pull-request comment.
 *
 * Wiring first — a dropped route, handler or listener is exactly what a line
 * diff hides — then code references; imports and the inferred, documentation
 * and structural edges are folded into one count line, because a reviewer
 * reads them only when something above looks wrong. Informational: it never
 * decides anything.
 */
final class EdgeDiffMarkdown
{
    private const ROWS_PER_SECTION = 50;

    /**
     * @param array<string, mixed>|null $coverage CoverageReport::summary() of the head graph
     * @param list<array{kind: string, subject: string, removed: string, still_used_by: list<string>}> $orphans
     * @param list<string> $unreadable files the head graph could not parse
     * @param MovedEdges|null $moves   pairs already taken out of $diff (MovePairing::pair()->remaining)
     * @param list<string>    $unchanged wiring a reviewer can rely on, named when no edge of it moved ("routes")
     * @param string|null     $scopeNote said when --path left part of the repository unread
     * @param list<string>|null $newlyUnreadable which of $unreadable this change made unreadable; null = all of them
     */
    public static function render(EdgeSetDiff $diff, string $baseRef, string $scope, ?array $coverage = null, array $orphans = [], array $unreadable = [], ?MovedEdges $moves = null, array $unchanged = [], ?string $scopeNote = null, ?array $newlyUnreadable = null): string
    {
        $lines = [sprintf('### Graph diff against %s — %s', self::code($baseRef), self::code($scope)), ''];
        if ($scopeNote !== null) {
            $lines[] = '> ' . $scopeNote;
            $lines[] = '';
        }
        $label = self::labeller($diff, $orphans);

        if ($orphans !== [] || $unreadable !== []) {
            $lines[] = '#### ⚠ Left pointing at nothing';
            $lines[] = '';
            foreach ($orphans as $orphan) {
                $lines[] = sprintf(
                    '- **%s** %s — still used by %s',
                    str_replace('_', ' ', $orphan['kind']),
                    self::code($label($orphan['subject'])),
                    implode(', ', array_map(static fn (string $id): string => self::code($label($id)), $orphan['still_used_by'])),
                );
            }
            foreach ($unreadable as $file) {
                $lines[] = sprintf(
                    '- **unreadable** %s — the graph could not parse it, so nothing about it is checked%s',
                    self::code($file),
                    $newlyUnreadable === null || in_array($file, $newlyUnreadable, true) ? '' : ' (already unreadable at the base; does not fail the gate)',
                );
            }
            $lines[] = '';
        }

        $movedLines = self::movedLines($moves);
        if ($diff->isEmpty()) {
            $lines[] = $movedLines === [] ? 'No structural change.' : 'No structural change besides the moves below.';
            if ($unchanged !== []) {
                $lines[] = '';
                $lines[] = 'Unchanged: ' . implode(', ', $unchanged) . '.';
            }
            array_push($lines, ...$movedLines);
            return implode("\n", $lines) . "\n";
        }

        $lines[] = sprintf('**+%d / −%d edges**', count($diff->added), count($diff->removed));
        if ($coverage !== null && !($coverage['complete'] ?? true)) {
            $lines[] = '';
            $lines[] = '> ' . CoverageReport::describe($coverage)[0] . ' Edges the graph could not see are not in this diff.';
        }

        $sections = [
            'Wiring' => [],
            'Code references' => [],
        ];
        $folded = [];
        foreach (['−' => $diff->removed, '+' => $diff->added] as $sign => $edges) {
            foreach ($edges as $edge) {
                $section = self::section($edge->getType());
                if ($section === null) {
                    $key = $edge->getType()->value;
                    $folded[$key][$sign] = ($folded[$key][$sign] ?? 0) + 1;
                    continue;
                }
                $sections[$section][] = [$sign, $edge];
            }
        }

        foreach ($sections as $title => $rows) {
            if ($rows === []) {
                continue;
            }
            // By edge type, then what was removed before what was added.
            $order = static fn (array $row): array => [$row[1]->getType()->value, $row[0] === '−' ? 0 : 1, $label($row[1]->getSourceId()), $label($row[1]->getTargetId())];
            usort($rows, static fn (array $a, array $b): int => $order($a) <=> $order($b));

            $lines[] = '';
            $lines[] = '#### ' . $title;
            $lines[] = '';
            $lines[] = '| | Edge | From | To |';
            $lines[] = '|---|---|---|---|';
            foreach (array_slice($rows, 0, self::ROWS_PER_SECTION) as [$sign, $edge]) {
                /** @var Edge $edge */
                $lines[] = sprintf('| %s | %s | %s | %s |', $sign, $edge->getType()->value, self::cell($label($edge->getSourceId())), self::cell($label($edge->getTargetId())));
            }
            if (count($rows) > self::ROWS_PER_SECTION) {
                $lines[] = sprintf('| | … | %d more | |', count($rows) - self::ROWS_PER_SECTION);
            }
        }

        if ($folded !== []) {
            ksort($folded);
            $parts = [];
            foreach ($folded as $type => $counts) {
                $parts[] = sprintf('%s +%d/−%d', $type, $counts['+'] ?? 0, $counts['−'] ?? 0);
            }
            $lines[] = '';
            $lines[] = '<sub>Also changed: ' . implode(', ', $parts) . '.</sub>';
        }

        if ($unchanged !== []) {
            $lines[] = '';
            $lines[] = 'Unchanged: ' . implode(', ', $unchanged) . '.';
        }
        array_push($lines, ...$movedLines);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Moves get a line of their own, folded: a move that keeps every edge can
     * still change behaviour (another module, other middleware), so it names
     * from and to. A move whose far end changed too is a warning, both edges
     * side by side — not a block (Andrii Liubashchenko, 2026-10-01).
     *
     * @return list<string>
     */
    private static function movedLines(?MovedEdges $moves): array
    {
        if ($moves === null || ($moves->movedNodes === [] && $moves->farEndChanged === [] && $moves->replaced === [])) {
            return [];
        }
        $lines = [];
        foreach ($moves->replaced as $pair) {
            $lines[] = '';
            $lines[] = sprintf(
                '> **replaced** %s %s: %s → %s',
                $pair['removed']->getType()->value,
                self::code(self::fqcnOf($pair['removed']->getTargetId())),
                self::code(self::fqcnOf($pair['removed']->getSourceId())),
                self::code(self::fqcnOf($pair['added']->getSourceId())),
            );
        }
        foreach ($moves->farEndChanged as $pair) {
            $lines[] = '';
            $lines[] = sprintf(
                '> ⚠ **moved and rewired** %s: − %s → %s, + %s → %s',
                $pair['removed']->getType()->value,
                self::code(self::fqcnOf($pair['removed']->getSourceId())),
                self::code(self::fqcnOf($pair['removed']->getTargetId())),
                self::code(self::fqcnOf($pair['added']->getSourceId())),
                self::code(self::fqcnOf($pair['added']->getTargetId())),
            );
        }
        if ($moves->movedNodes !== []) {
            $lines[] = '';
            $lines[] = sprintf('<details><summary>Moved: %d class%s</summary>', count($moves->movedNodes), count($moves->movedNodes) === 1 ? '' : 'es');
            $lines[] = '';
            $shortCount = [];
            foreach ($moves->movedNodes as $move) {
                $key = self::shortOf(self::fqcnOf($move['from']));
                $shortCount[$key] = ($shortCount[$key] ?? 0) + 1;
            }
            foreach ($moves->movedNodes as $move) {
                $from = self::fqcnOf($move['from']);
                $to = self::fqcnOf($move['to']);
                // Two Handlers moved at once printed two identical lines; a
                // rename in one namespace printed "(App\Shop → App\Shop)".
                $ambiguous = ($shortCount[self::shortOf($from)] ?? 0) > 1;
                $where = match (true) {
                    $ambiguous => self::namespaceOf($from) . ' → ' . self::namespaceOf($to),
                    $move['from_module'] !== $move['to_module'] && $move['from_module'] !== '' && $move['to_module'] !== '' => $move['from_module'] . ' → ' . $move['to_module'],
                    self::namespaceOf($from) === self::namespaceOf($to) => 'renamed in place',
                    default => self::namespaceOf($from) . ' → ' . self::namespaceOf($to),
                };
                $short = self::shortOf($from) === self::shortOf($to) ? self::shortOf($to) : self::shortOf($from) . ' → ' . self::shortOf($to);
                $lines[] = sprintf(
                    '- moved: %s (%s)%s',
                    self::code($short),
                    $where,
                    $move['kept'] === [] ? '' : ', ' . implode(', ', $move['kept']) . ' unchanged',
                );
            }
            $lines[] = '';
            $lines[] = '</details>';
        }

        return $lines;
    }

    /**
     * Short names unless two different ids in this comment share one: a moved
     * class read as identical −/+ rows (measured 2026-10-02).
     *
     * @param list<array{subject: string, still_used_by: list<string>}> $orphans
     * @return \Closure(string): string
     */
    private static function labeller(EdgeSetDiff $diff, array $orphans): \Closure
    {
        $ids = [];
        foreach ([...$diff->added, ...$diff->removed] as $edge) {
            $ids[$edge->getSourceId()] = true;
            $ids[$edge->getTargetId()] = true;
        }
        foreach ($orphans as $orphan) {
            $ids[$orphan['subject']] = true;
            foreach ($orphan['still_used_by'] as $id) {
                $ids[$id] = true;
            }
        }
        $byShort = [];
        foreach (array_keys($ids) as $id) {
            $byShort[self::label((string) $id)][(string) $id] = true;
        }

        return static fn (string $id): string => count($byShort[self::label($id)] ?? []) > 1 ? self::fqcnOf($id) : self::label($id);
    }

    /**
     * Inline code that survives any content: a fence one backtick longer than
     * the longest run inside, padded when the text starts or ends with one.
     */
    private static function code(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        preg_match_all('/`+/', $text, $runs);
        $longest = max([0, ...array_map('strlen', $runs[0])]);
        $fence = str_repeat('`', $longest + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') ? ' ' : '';

        return $fence . $pad . $text . $pad . $fence;
    }

    /** A table cell: inline code, with the pipe that would split the row escaped. */
    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', self::code($text));
    }

    private static function fqcnOf(string $id): string
    {
        return str_starts_with($id, 'class:') ? substr($id, 6) : $id;
    }

    private static function shortOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private static function namespaceOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? '(global)' : substr($fqcn, 0, $pos);
    }

    private static function section(EdgeType $type): ?string
    {
        if ($type === EdgeType::Imports) {
            return null;
        }

        return match ($type->edgeClass()) {
            EdgeClass::Wiring => 'Wiring',
            EdgeClass::CodeReference => 'Code references',
            EdgeClass::Inferred, EdgeClass::Documentation, EdgeClass::Structural => null,
        };
    }

    /** A node id as a reviewer reads it: a class by its short name, a route as "GET /path". */
    private static function label(string $id): string
    {
        [$prefix, $rest] = array_pad(explode(':', $id, 2), 2, '');

        return match ($prefix) {
            'class' => substr($rest, (int) strrpos('\\' . $rest, '\\')),
            // Only the method separator: a path may hold colons of its own ({id:\d+}, 12:30).
            'route' => preg_replace('/:/', ' ', $rest, 1) ?? $rest,
            default => $id,
        };
    }
}
