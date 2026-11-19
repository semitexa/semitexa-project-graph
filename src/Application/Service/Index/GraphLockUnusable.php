<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Index;

/**
 * The graph's lock file is there and this process cannot open it: a caller that
 * does not wait does not write, and says why instead of reporting a busy graph
 * (review, project-graph#39).
 */
final class GraphLockUnusable extends \RuntimeException
{
}
