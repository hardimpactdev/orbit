<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Schedules;

final readonly class ScheduleListResponse
{
    /**
     * @param  list<array<string, mixed>>  $schedules
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $schedules,
        public array $meta,
    ) {}
}
