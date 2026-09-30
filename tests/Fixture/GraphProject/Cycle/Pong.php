<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Tests\Fixture\GraphProject\Cycle;

final class Pong
{
    public function next(): Ping
    {
        return new Ping();
    }
}
