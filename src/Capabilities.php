<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'graph.impact',
    summary: 'A persistent graph of the codebase - real edges, not string matches - answering who uses a class and what a change touches.',
    useWhen: 'You need the blast radius of a change, or every caller of a contract, before editing it.',
    avoidWhen: 'You are looking for a literal string or a filename. Grep answers that directly.',
    replaces: [
        'grep for a class name, missing every alias, subclass and interface reference',
        'opening files one by one to reconstruct who calls what',
    ],
)]
final class Capabilities
{
}
