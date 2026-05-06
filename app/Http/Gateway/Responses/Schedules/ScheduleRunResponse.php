<?php

declare(strict_types=1);

namespace App\Http\Gateway\Responses\Schedules;

final readonly class ScheduleRunResponse
{
    /**
     * @param  array<string, mixed>  $run
     */
    public function __construct(
        public array $run,
    ) {}
}
