<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Schedules;

final readonly class ScheduleAddResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
    ) {}
}
