<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Models\Schedule;
use SensitiveParameter;

/** Gateway intent for the agent's Schedule installation and inspection commands. */
final readonly class ScheduleSpecification
{
    /** @return array{id: string, user: string, group: string, home: string, working_directory: string, shell: string, login_shell: bool, command: string, calendar: string, timeout_seconds: int, desired: string} */
    public function input(#[SensitiveParameter] Schedule $schedule, ScheduleTarget $target): array
    {
        return [
            'id' => $schedule->id,
            'user' => $target->user,
            'group' => $target->group,
            'home' => $target->home,
            'working_directory' => $target->workingDirectory,
            'shell' => $target->shell,
            'login_shell' => $target->loginShell,
            'command' => $schedule->command,
            'calendar' => $schedule->calendar,
            'timeout_seconds' => $schedule->timeout_seconds,
            'desired' => $schedule->desired_timer_state->value,
        ];
    }

    public function serviceName(Schedule $schedule): string
    {
        return 'orbit-schedule-'.$schedule->id.'.service';
    }
}
