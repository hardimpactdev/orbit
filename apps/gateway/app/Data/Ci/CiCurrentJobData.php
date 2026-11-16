<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** The job a runner runs now. */
final class CiCurrentJobData extends Data
{
    public function __construct(
        public int $job_id,
        public string $repository,
        public string $workflow,
        public string $job,
        public ?string $started_at,
        public ?string $url,
    ) {}
}
