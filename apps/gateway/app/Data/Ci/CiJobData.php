<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** One job of the window that asked for a self-hosted runner (docs/reference/ci-runner-view.md#response). */
final class CiJobData extends Data
{
    public function __construct(
        public int $job_id,
        public int $run_id,
        public string $repository,
        public string $workflow,
        public string $job,
        public ?string $runner_name,
        public string $status,
        public ?string $conclusion,
        public string $created_at,
        public ?string $started_at,
        public ?string $completed_at,
        public ?int $queue_wait_seconds,
        public ?int $run_seconds,
        public ?string $url,
    ) {}
}
