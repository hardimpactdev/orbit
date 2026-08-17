<?php

declare(strict_types=1);

namespace App\Enums\Schedules;

enum ScheduleScope: string
{
    case Orbit = 'orbit';
    case Node = 'node';
    case Instance = 'instance';
}
