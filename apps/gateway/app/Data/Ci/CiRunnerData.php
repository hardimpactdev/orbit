<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** One runner container: its state, its current job and its completed jobs of the window. */
final class CiRunnerData extends Data
{
    public function __construct(
        public string $name,
        public string $state,
        public string $service,
        public bool $busy,
        public ?string $version,
        public ?CiCurrentJobData $current_job,
        public CiRunnerStatsData $stats,
    ) {}
}
