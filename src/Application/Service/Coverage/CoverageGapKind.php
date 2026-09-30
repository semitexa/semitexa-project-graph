<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Coverage;

/**
 * Why part of the code is missing from the graph.
 *
 * A missing edge and an edge the graph could not see look identical in the
 * stored graph. A gap records the second kind, so an absence-based answer
 * ("nothing uses this", "zero blast radius") can say what it did not see
 * instead of stating the absence as proof.
 */
enum CoverageGapKind: string
{
    /** The file could not be parsed; nothing it declares is in the graph. */
    case ParseError = 'parse_error';

    /** An extractor threw on the file; that extractor's edges for it are missing, the rest are there. */
    case ExtractionFailed = 'extraction_failed';

    /**
     * The file declares a class the graph already holds from another file —
     * a second declaration of the same name, or another file claimed it
     * first. Only one of them is in the graph; the detail names the other.
     */
    case DuplicateClass = 'duplicate_class';
}
