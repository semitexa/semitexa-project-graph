<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Scanner;

enum FileStatus: string
{
    case Added    = 'added';
    case Modified = 'modified';
    case Deleted  = 'deleted';
}
