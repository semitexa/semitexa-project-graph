<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle;

/** Ping and Pong instantiate each other: the one planted class cycle. */
final class Ping
{
    public function next(): Pong
    {
        return new Pong();
    }
}
