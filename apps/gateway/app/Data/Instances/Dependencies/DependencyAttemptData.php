<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyAttemptData extends Data
{
    public function __construct(
        public string $run_id,
        public string $started_at,
        public ?string $finished_at,
        public string $status,
        public ?string $error_code,
    ) {}
}
