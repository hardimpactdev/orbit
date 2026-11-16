<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** A runner's completed jobs of the window. */
final class CiRunnerStatsData extends Data
{
    public function __construct(
        public int $jobs,
        public int $failed,
        public ?int $queue_wait_seconds_median,
        public ?int $run_seconds_median,
        public int $busy_seconds,
    ) {}
}
