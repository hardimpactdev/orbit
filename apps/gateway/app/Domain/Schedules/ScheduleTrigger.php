<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

/** Who fires a Schedule: its own Swamp workflow on 209, or a systemd timer on its Node (ADR 0244 exception). */
enum ScheduleTrigger: string
{
    case Swamp = 'swamp';
    case Node = 'node';

    /** The calendar's form picks the trigger: a cron expression is native; a systemd calendar keeps the Node timer. */
    public static function forCalendar(string $calendar): self
    {
        $fields = preg_split('/\s+/', trim($calendar)) ?: [];

        return preg_match('/\A@(?:yearly|annually|monthly|weekly|daily|hourly)\z/D', trim($calendar)) === 1
            || (in_array(count($fields), [5, 6], true) && ! str_contains($calendar, ':')) ? self::Swamp : self::Node;
    }
}
