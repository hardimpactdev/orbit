<?php

declare(strict_types=1);

namespace App\Services\Apps;

enum NodeRuntimeConfigsProbeStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Error = 'error';
}
