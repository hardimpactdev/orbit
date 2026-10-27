<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class TaskSwampStepData extends Data
{
    public function __construct(
        public string $job,
        public string $name,
        public string $status,
        public ?string $started_at,
        public ?string $completed_at,
        public string $output,
        public bool $truncated,
    ) {}
}
