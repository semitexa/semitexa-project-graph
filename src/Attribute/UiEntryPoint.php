<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Attribute;

use Attribute;

/**
 * This payload is entered from outside the pages the graph can read, on
 * purpose: a POST a script on the page sends, a form another application
 * posts, an endpoint a device calls. Say why in one sentence.
 *
 * The UI flow findings (`ai:review-graph:findings --kind=ui`) then list it as
 * accepted instead of warning that no form or action reaches it.
 *
 *     #[UiEntryPoint(reason: 'Posted by the checkout widget on partner sites.')]
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UiEntryPoint
{
    public function __construct(
        public readonly string $reason,
    ) {}
}
