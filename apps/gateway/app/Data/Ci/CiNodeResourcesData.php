<?php

declare(strict_types=1);

namespace App\Data\Ci;

use Spatie\LaravelData\Data;

/** A CI Node's CPU pressure, memory and root disk from the Node agent's latest metrics snapshot. */
final class CiNodeResourcesData extends Data
{
    public function __construct(
        public string $observed_at,
        public bool $possibly_outdated,
        public float $cpu_pressure,
        public float $memory_pressure,
        public CiUsageData $memory,
        public ?CiUsageData $disk,
    ) {}
}
