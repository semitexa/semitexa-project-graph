<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Findings;

use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * Every edge in the graph, by target and by source, read in one paged pass —
 * a whole-graph question asked node by node is ~8k queries (the old
 * CycleDetector worked that way; GraphQueryService::buildView still does).
 *
 * Each edge is packed into one integer (node ids interned, the type as an
 * index, the source's file reduced to "is it test code"), and `via` is kept
 * only where there is one: on the workspace (~67k edges) arrays of strings
 * peaked at 116M of a 128M limit, arrays of small arrays at 98M.
 */
final class InboundIndex
{
    /** @var array<string, int> */
    private array $idOf = [];

    /** @var list<string> */
    private array $ids = [];

    /** @var array<int, list<int>> target => packed (source << 8 | type << 2 | flags) */
    private array $inbound = [];

    /** @var array<int, list<int>> source => packed (target << 8 | type << 2) */
    private array $outbound = [];

    /** @var array<string, string> "target:source:type" => via, only for edges that carry one */
    private array $via = [];

    /** @var list<EdgeType> */
    private array $types;

    private const DECLARED = 1;
    private const IN_TESTS = 2;

    private function __construct()
    {
        $this->types = EdgeType::cases();
        if (count($this->types) > 64) {
            // The packed layout gives the type 6 bits.
            throw new \LogicException('InboundIndex packs an edge type into 6 bits; EdgeType has ' . count($this->types) . ' cases.');
        }
    }

    public static function of(GraphStorage $storage): self
    {
        $index = new self();
        $tests = TestCode::of($storage);
        $typeOf = array_flip(array_map(static fn (EdgeType $t): string => $t->value, $index->types));

        foreach ($storage->edges->withSources() as $row) {
            $type = $typeOf[$row['type']] ?? null;
            if ($type === null) {
                continue;
            }
            $source = $index->intern($row['source_id']);
            $target = $index->intern($row['target_id']);
            $flags = ($row['source_declared'] ? self::DECLARED : 0)
                | ($tests->contains($row['source_file']) ? self::IN_TESTS : 0);

            $index->inbound[$target][] = ($source << 8) | ($type << 2) | $flags;
            $index->outbound[$source][] = ($target << 8) | ($type << 2);
            if ($row['via'] !== null) {
                $index->via[$target . ':' . $source . ':' . $type] = $row['via'];
            }
        }

        return $index;
    }

    /** @return list<array{type: EdgeType, via: ?string, source: string, sourceDeclared: bool, sourceInTests: bool}> */
    public function into(string $nodeId): array
    {
        $id = $this->idOf[$nodeId] ?? null;
        if ($id === null) {
            return [];
        }

        return array_map(
            function (int $packed) use ($id): array {
                $source = $packed >> 8;
                $type = ($packed >> 2) & 0x3F;

                return [
                    'type'           => $this->types[$type],
                    'via'            => $this->via[$id . ':' . $source . ':' . $type] ?? null,
                    'source'         => $this->ids[$source],
                    'sourceDeclared' => ($packed & self::DECLARED) !== 0,
                    'sourceInTests'  => ($packed & self::IN_TESTS) !== 0,
                ];
            },
            $this->inbound[$id] ?? [],
        );
    }

    /** @return list<array{type: EdgeType, via: ?string, target: string}> */
    public function outOf(string $nodeId): array
    {
        $id = $this->idOf[$nodeId] ?? null;
        if ($id === null) {
            return [];
        }

        return array_map(
            function (int $packed) use ($id): array {
                $target = $packed >> 8;
                $type = ($packed >> 2) & 0x3F;

                return ['type' => $this->types[$type], 'via' => $this->via[$target . ':' . $id . ':' . $type] ?? null, 'target' => $this->ids[$target]];
            },
            $this->outbound[$id] ?? [],
        );
    }

    private function intern(string $id): int
    {
        if (!isset($this->idOf[$id])) {
            $this->idOf[$id] = count($this->ids);
            $this->ids[] = $id;
        }

        return $this->idOf[$id];
    }
}
