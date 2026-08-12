<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Process;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeUnitLivenessDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        if (
            $this->runtimeContextResolver->runtime($process) !== ProcessRuntime::Docker
            || $process->restart_policy !== ProcessRestartPolicy::Always
        ) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if (
            ! is_array($observed)
            || ($observed['runtime_backend_available'] ?? null) === false
            || ($observed['runtime_unit_renderable'] ?? null) === false
            || ! is_array($observed['runtime_units'] ?? null)
        ) {
            return [];
        }

        $drift = [];

        foreach ($this->expectedRuntimeUnits->specifications($process) as $unit) {
            if (! is_array($observed['runtime_units'][$unit['name']] ?? null)) {
                continue;
            }

            $runtimeUnit = $observed['runtime_units'][$unit['name']];

            if (
                ($runtimeUnit['config_exists'] ?? null) !== true
                || ! is_string($runtimeUnit['container_state'] ?? null)
                || $runtimeUnit['container_state'] === 'running'
                || ($runtimeUnit['hibernated'] ?? null) === true
            ) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'process',
                key: 'process.runtime_unit_down',
                kind: DriftKind::Divergent,
                summary: "Always-on process runtime unit {$unit['name']} is not running.",
                detail: [
                    ...$this->runtimeUnitDetail->for($process, $unit),
                    'observed_state' => $runtimeUnit['container_state'],
                ],
            );
        }

        return $drift;
    }
}
