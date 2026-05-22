<?php

declare(strict_types=1);

namespace App\Services\Apps;

enum NodeRuntimeContainersProbeStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Error = 'error';
}
