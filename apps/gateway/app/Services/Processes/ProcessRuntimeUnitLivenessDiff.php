<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\Process;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeUnitLivenessDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private LaunchdProcessRuntimePolicy $launchdRuntimePolicy,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        $runtime = $this->runtimeContextResolver->runtime($process);

        if ($runtime === ProcessRuntime::Launchd) {
            return $this->launchdDiff($process, $snapshot);
        }

        if ($runtime !== ProcessRuntime::Docker || $process->restart_policy !== ProcessRestartPolicy::Always) {
            return [];
        }

        return $this->dockerDiff($process, $snapshot);
    }

    /** @return list<DriftEntry> */
    private function launchdDiff(Process $process, ProbeSnapshot $snapshot): array
    {
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $node instanceof Node || ! $this->launchdRuntimePolicy->shouldBeLoaded($process, $node)) {
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
                || ($runtimeUnit['loaded'] ?? null) !== false
            ) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'process',
                key: 'process.runtime_unit_unloaded',
                kind: DriftKind::Divergent,
                summary: "Launchd runtime unit {$unit['name']} is not loaded in the current user GUI domain.",
                detail: [
                    ...$this->runtimeUnitDetail->for($process, $unit),
                    'observed_loaded' => false,
                ],
            );
        }

        return $drift;
    }

    /** @return list<DriftEntry> */
    private function dockerDiff(Process $process, ProbeSnapshot $snapshot): array
    {
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
