<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

/** Another process holds the graph's write lock, and the caller asked not to wait for it. */
final class GraphIsBeingWritten extends \RuntimeException
{
}
