<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Query;

enum Direction: string
{
    case Outgoing = 'outgoing';
    case Incoming = 'incoming';
    case Both     = 'both';
}
