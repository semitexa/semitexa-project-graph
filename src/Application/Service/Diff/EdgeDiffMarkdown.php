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
     */
    public static function render(EdgeSetDiff $diff, string $baseRef, string $scope, ?array $coverage = null, array $orphans = [], array $unreadable = []): string
    {
        $lines = [sprintf('### Graph diff against `%s` — `%s`', $baseRef, $scope), ''];

        if ($orphans !== [] || $unreadable !== []) {
            $lines[] = '#### ⚠ Left pointing at nothing';
            $lines[] = '';
            foreach ($orphans as $orphan) {
                $lines[] = sprintf(
                    '- **%s** `%s` — still used by %s',
                    str_replace('_', ' ', $orphan['kind']),
                    self::label($orphan['subject']),
                    implode(', ', array_map(static fn (string $id): string => '`' . self::label($id) . '`', $orphan['still_used_by'])),
                );
            }
            foreach ($unreadable as $file) {
                $lines[] = sprintf('- **unreadable** `%s` — the graph could not parse it, so nothing about it is checked', $file);
            }
            $lines[] = '';
        }

        if ($diff->isEmpty()) {
            $lines[] = 'No structural change.';
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
            $order = static fn (array $row): array => [$row[1]->getType()->value, $row[0] === '−' ? 0 : 1, self::label($row[1]->getSourceId()), self::label($row[1]->getTargetId())];
            usort($rows, static fn (array $a, array $b): int => $order($a) <=> $order($b));

            $lines[] = '';
            $lines[] = '#### ' . $title;
            $lines[] = '';
            $lines[] = '| | Edge | From | To |';
            $lines[] = '|---|---|---|---|';
            foreach (array_slice($rows, 0, self::ROWS_PER_SECTION) as [$sign, $edge]) {
                /** @var Edge $edge */
                $lines[] = sprintf('| %s | %s | `%s` | `%s` |', $sign, $edge->getType()->value, self::label($edge->getSourceId()), self::label($edge->getTargetId()));
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

        return implode("\n", $lines) . "\n";
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
            'route' => str_replace(':', ' ', $rest),
            default => $id,
        };
    }
}
