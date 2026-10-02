<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Support;

use Semitexa\ProjectGraph\Application\Service\Findings\UnusedClassFinder;
use Semitexa\ProjectGraph\Application\Service\Graph\EdgeType;
use Semitexa\ProjectGraph\Domain\Model\Edge;

/**
 * Helpers for the extractor-campaign regression tests (2026-10-02): small
 * PHP sources written into a fresh GraphFixture copy and scanned with the
 * real pipeline, asserted edge by edge.
 *
 * The sources use the Campaign\ namespace on purpose — nothing can autoload
 * them, so every assertion is about what the parser read from the file.
 */
trait ExtractorCampaign
{
    /** @param array<string, string> $files relative path => contents */
    private static function graphOf(array $files): GraphFixture
    {
        $fixture = GraphFixture::create();
        foreach ($files as $path => $contents) {
            $fixture->write($path, $contents);
        }
        $fixture->build();

        return $fixture;
    }

    /** @return list<Edge> */
    private static function edgesFrom(GraphFixture $fixture, string $sourceId, ?EdgeType $type = null): array
    {
        return $fixture->storage->edges->findBySource($sourceId, $type);
    }

    /** @return list<string> target ids */
    private static function targetsFrom(GraphFixture $fixture, string $sourceId, EdgeType $type): array
    {
        $targets = array_map(static fn (Edge $e): string => $e->getTargetId(), self::edgesFrom($fixture, $sourceId, $type));
        sort($targets);

        return $targets;
    }

    private static function edgeBetween(GraphFixture $fixture, EdgeType $type, string $sourceId, string $targetId): ?Edge
    {
        foreach (self::edgesFrom($fixture, $sourceId, $type) as $edge) {
            if ($edge->getTargetId() === $targetId) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * Edges whose source is no node of the graph. Nothing owns them, so
     * nothing removes them when their file goes.
     *
     * @return list<string>
     */
    private static function danglingSources(GraphFixture $fixture): array
    {
        $nodes = [];
        foreach ($fixture->nodeLines() as $line) {
            $nodes[explode(' ', $line, 2)[0]] = true;
        }
        $dangling = [];
        foreach ($fixture->edgeLines() as $line) {
            $source = explode(' ', $line)[1];
            if (!isset($nodes[$source])) {
                $dangling[] = $line;
            }
        }

        return $dangling;
    }

    /** @return array<string, string> fqcn => confidence */
    private static function unused(GraphFixture $fixture): array
    {
        $unused = [];
        foreach ((new UnusedClassFinder())->find($fixture->storage) as $finding) {
            $unused[$finding['fqcn']] = $finding['confidence'];
        }

        return $unused;
    }
}
