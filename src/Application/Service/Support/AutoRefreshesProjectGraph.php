<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Support;

use Semitexa\ProjectGraph\Application\Service\Extractor\ExtractorPipeline;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphBuilder;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Index\IncrementalEngine;
use Semitexa\ProjectGraph\Application\Service\Parser\PhpParserAdapter;
use Semitexa\ProjectGraph\Application\Service\Scanner\FileScanner;
use Semitexa\ProjectGraph\Application\Service\Scanner\IgnorePatternLoader;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Auto-refresh guard for read commands (query/impact): a stale — or worse,
 * never-generated — graph silently answers "no usages", which an agent reads
 * as an authoritative empty blast radius. Every read first runs the
 * incremental engine (seconds when little changed; a first run on an empty
 * store is effectively a full build), so answers always reflect the code as
 * it is NOW. `--no-refresh` skips it for scripted speed.
 *
 * Consumers must also use {@see UsesProjectGraphConnection} (getProjectRoot
 * comes from BaseCommand).
 */
trait AutoRefreshesProjectGraph
{
    /**
     * @param bool $quiet suppress the refresh note (JSON/NDJSON output modes —
     *                    a note on stdout would corrupt the machine payload)
     */
    private function refreshProjectGraph(GraphStorage $storage, SymfonyStyle $io, bool $skip, bool $quiet): void
    {
        if ($skip) {
            return;
        }

        try {
            $engine = new IncrementalEngine(
                new FileScanner(new IgnorePatternLoader()),
                new PhpParserAdapter(),
                new ExtractorPipeline(ExtractorPipeline::default()),
                new GraphBuilder($storage),
                $storage,
            );
            $result = $engine->update($this->getProjectRoot());

            if (!$quiet && !$result->isNoChanges()) {
                $io->note(sprintf(
                    'Graph auto-refreshed: %d file(s) rescanned, +%d/-%d nodes, +%d/-%d edges (%dms). Use --no-refresh to skip.',
                    $result->filesScanned,
                    $result->nodesAdded,
                    $result->nodesRemoved,
                    $result->edgesAdded,
                    $result->edgesRemoved,
                    $result->duration,
                ));
            }
        } catch (\Throwable $e) {
            // A failed refresh must not block the query — answer from the
            // existing snapshot, but SAY it may be stale (stderr-safe: notes
            // are suppressed in machine-output modes anyway).
            if (!$quiet) {
                $io->warning('Graph auto-refresh failed (' . $e->getMessage() . ') — results may be stale.');
            }
        }
    }
}
