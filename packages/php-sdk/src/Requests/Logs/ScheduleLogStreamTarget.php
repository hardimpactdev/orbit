<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Logs;

final readonly class ScheduleLogStreamTarget implements LogStreamTarget
{
    public const string MinimumAgentVersion = '0.14.0';

    public function __construct(public string $scheduleId) {}

    public function logStreamsPath(): string
    {
        return '/api/v1/schedules/'.rawurlencode($this->scheduleId).'/log-streams';
    }
}
