<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Process;

final readonly class ProcessRuntimeUnitPolicyDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        if (in_array(
            $this->runtimeContextResolver->runtime($process),
            [ProcessRuntime::Docker, ProcessRuntime::DockerSwarm],
            strict: true,
        )) {
            return [];
        }

        return [
            ...$this->checkField(
                process: $process,
                snapshot: $snapshot,
                field: 'restart_policy_matches',
                key: 'process.restart_policy_mismatch',
                summary: static fn (string $name): string => "Process runtime unit {$name} restart policy differs from gateway process intent.",
            ),
            ...$this->checkField(
                process: $process,
                snapshot: $snapshot,
                field: 'environment_matches',
                key: 'process.runtime_environment_mismatch',
                summary: static fn (string $name): string => "Process runtime unit {$name} environment differs from gateway process intent.",
            ),
        ];
    }

    /**
     * @param  callable(string): string  $summary
     * @return list<DriftEntry>
     */
    private function checkField(
        Process $process,
        ProbeSnapshot $snapshot,
        string $field,
        string $key,
        callable $summary,
    ): array {
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
                continue;
            }

            $runtimeUnit = $observed['runtime_units'][$name];

            if (
                ($runtimeUnit['config_exists'] ?? null) === false
                || ($runtimeUnit[$field] ?? null) !== false
            ) {
                continue;
            }

            $drift[] = new DriftEntry(
                family: 'process',
                key: $key,
                kind: DriftKind::Divergent,
                summary: $summary($name),
                detail: $this->runtimeUnitDetail->for($process, $unit),
            );
        }

        return $drift;
    }
}
