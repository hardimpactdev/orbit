<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Responses\Activity;

final readonly class ActivityListResponse
{
    /**
     * @param  list<array<string, mixed>>  $activities
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $activities,
        public array $meta,
    ) {}
}
