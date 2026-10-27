<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class TaskSwampRunData extends Data
{
    /** @param list<TaskSwampStepData> $steps */
    public function __construct(
        public string $id,
        public string $workflow,
        public string $status,
        public ?string $started_at,
        public ?string $completed_at,
        public array $steps,
        public string $log,
        public bool $log_truncated,
        public bool $steps_truncated,
    ) {}
}
