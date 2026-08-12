<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Models\Process;

final readonly class ProcessRuntimeUnitDiff
{
    public function __construct(
        private ProcessRuntimeAvailabilityDiff $availabilityDiff,
        private ProcessRuntimeUnitDefinitionDiff $definitionDiff,
        private ProcessRuntimeUnitLivenessDiff $livenessDiff,
        private ProcessRuntimeUnitPolicyDiff $policyDiff,
        private ProcessRuntimeUnitExtrasDiff $extrasDiff,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->availabilityDiff->diff($process, $snapshot),
            ...$this->definitionDiff->diff($process, $snapshot),
            ...$this->livenessDiff->diff($process, $snapshot),
            ...$this->policyDiff->diff($process, $snapshot),
            ...$this->extrasDiff->diff($process, $snapshot),
        ];
    }
}
