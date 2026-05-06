<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Schedules;

final readonly class SchedulerHeartbeatResponse
{
    /**
     * @param  array<string, mixed>  $state
     */
    public function __construct(
        public array $state,
    ) {}
}
