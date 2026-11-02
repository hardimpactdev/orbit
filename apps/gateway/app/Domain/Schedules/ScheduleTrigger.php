<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

/** Who fires a Schedule: its own Swamp workflow on 209, or a systemd timer on its Node (ADR 0244 exception). */
enum ScheduleTrigger: string
{
    case Swamp = 'swamp';
    case Node = 'node';
}
