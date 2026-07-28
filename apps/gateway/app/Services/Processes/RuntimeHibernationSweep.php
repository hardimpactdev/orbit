<?php

declare(strict_types=1);

namespace App\Services\Processes;

use Carbon\CarbonImmutable;

final readonly class RuntimeHibernationSweep
{
    public function __construct(
        private RuntimeIdleHibernation $hibernation,
        private RuntimeHibernationSweepCadence $cadence,
    ) {}

    public function run(CarbonImmutable $now): void
    {
        if (! $this->cadence->isDue($now)) {
            return;
        }

        $this->hibernation->hibernate($now);
    }
}
