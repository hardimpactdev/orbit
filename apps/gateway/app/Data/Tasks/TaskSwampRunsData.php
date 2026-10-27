<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

/** One bounded read of a group's deterministic workflow runs. */
final class TaskSwampRunsData extends Data
{
    /**
     * @param  'available'|'stale'|'unavailable'  $availability
     * @param  list<TaskSwampRunData>  $runs
     */
    public function __construct(
        public string $availability,
        public ?string $observed_at,
        public array $runs,
        public bool $truncated,
    ) {}
}
