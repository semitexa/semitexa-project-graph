<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

/**
 * What kind of fact an edge records — the one classification every consumer
 * of the graph shares, so "is this class still used", "which edges matter in a
 * PR" and "which edges can form a cycle" are answered from the same table.
 *
 * Cases are declared in review-priority order: a PR comment lists wiring
 * changes first, because a dropped route or listener is invisible in a line
 * diff and a changed import is not.
 */
enum EdgeClass: string
{
    /** Framework wiring declared by an attribute: routes, handlers, listeners, injection, contracts, ORM mapping, messaging. */
    case Wiring = 'wiring';

    /** A reference written in the code itself: extends, implements, new, type hints, imports, calls, trait use. */
    case CodeReference = 'code_reference';

    /** Derived by the intelligence layer, not written anywhere: domains, flows, hotspots, intents. */
    case Inferred = 'inferred';

    /** Links to documentation, examples and decision records. */
    case Documentation = 'documentation';

    /** Containment: file, module, namespace. Always true, never interesting on its own. */
    case Structural = 'structural';

    /** Whether an edge of this class is a real dependency: something breaks if its target disappears. */
    public function isDependency(): bool
    {
        return $this === self::Wiring || $this === self::CodeReference;
    }
}
