<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Process;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeUnitDefinitionDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if (
            $observed === null
            || ($observed['runtime_backend_available'] ?? null) === false
            || ($observed['runtime_unit_renderable'] ?? null) === false
            || ! is_array($observed['runtime_units'] ?? null)
        ) {
            return [];
        }

        $drift = [];

        foreach ($this->expectedRuntimeUnits->specifications($process) as $unit) {
            $name = $unit['name'];

            if (! is_array($observed['runtime_units'][$name] ?? null)) {
                $drift[] = new DriftEntry(
                    family: 'process',
                    key: 'process.runtime_unit_missing',
                    kind: DriftKind::Missing,
                    summary: "Process runtime unit {$name} is missing.",
                    detail: $this->runtimeUnitDetail->for($process, $unit),
                );

                continue;
            }

            $runtimeUnit = $observed['runtime_units'][$name];

            if (($runtimeUnit['config_exists'] ?? null) === false) {
                $drift[] = new DriftEntry(
                    family: 'process',
                    key: 'process.runtime_unit_missing',
                    kind: DriftKind::Missing,
                    summary: "Process runtime unit {$name} is missing.",
                    detail: $this->runtimeUnitDetail->for($process, $unit),
                );

                continue;
            }

            $isMismatch = ($runtimeUnit['config_matches'] ?? null) === false;

            if (! in_array(
                $this->runtimeContextResolver->runtime($process),
                [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
                strict: true,
            )) {
                $isMismatch =
                    $isMismatch
                    && ($runtimeUnit['restart_policy_matches'] ?? null) !== false
                    && ($runtimeUnit['environment_matches'] ?? null) !== false;
            }

            if (! $isMismatch) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'process',
                key: 'process.runtime_unit_mismatch',
                kind: DriftKind::Divergent,
                summary: "Process runtime unit {$name} differs from gateway process intent.",
                detail: $this->runtimeUnitDetail->for($process, $unit),
            );
        }

        return $drift;
    }
}
