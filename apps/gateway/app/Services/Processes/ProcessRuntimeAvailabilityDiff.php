<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;

final readonly class ProcessRuntimeAvailabilityDiff
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private ProcessOwnershipDetail $ownershipDetail,
        private ProcessRuntimeUnitDetail $runtimeUnitDetail,
    ) {}

    /** @return list<DriftEntry> */
    public function diff(Process $process, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkBackend($process, $snapshot),
            ...$this->checkRenderability($process, $snapshot),
        ];
    }

    /** @return list<DriftEntry> */
    private function checkBackend(Process $process, ProbeSnapshot $snapshot): array
    {
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $node instanceof Node) {
            return [];
        }

        $observed = $snapshot->get($process->name);

        if ($observed === null || ($observed['runtime_backend_available'] ?? null) !== false) {
            return [];
        }

        $backendName = match ($this->runtimeContextResolver->runtime($process)) {
            ProcessRuntime::Docker, ProcessRuntime::DockerSwarm => 'Docker',
            ProcessRuntime::Launchd => 'launchd',
            ProcessRuntime::Systemd => 'systemd',
        };

        return [
            new DriftEntry(
                family: 'process',
                key: 'process.runtime_backend_unavailable',
                kind: DriftKind::Unverifiable,
                summary: "{$backendName} runtime backend is unavailable for process {$process->name} on node {$node->name}.",
                detail: [
                    'process' => $process->name,
                    ...$this->ownershipDetail->for($process),
                    'node' => $node->name,
                    'runtime' => $this->runtimeContextResolver->runtime($process)->value,
                    ...$this->runtimeUnitDetail->serviceRuntime($process),
                    'exit_code' => $observed['runtime_backend_exit_code'] ?? null,
                    'output' => $observed['runtime_backend_output'] ?? '',
                ],
            ),
        ];
    }

    /** @return list<DriftEntry> */
    private function checkRenderability(Process $process, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($process->name);

        if ($observed === null || ($observed['runtime_unit_renderable'] ?? null) !== false) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'process',
                key: 'process.runtime_unit_unrenderable',
                kind: DriftKind::Unverifiable,
                summary: "Process {$process->name} runtime unit cannot be rendered from gateway intent.",
                detail: array_filter(
                    [
                        'process' => $process->name,
                        ...$this->ownershipDetail->for($process),
                        'runtime' => $this->runtimeContextResolver->runtime($process)->value,
                        ...$this->runtimeUnitDetail->serviceRuntime($process),
                        'reason' => $observed['runtime_unit_render_error'] ?? null,
                    ],
                    static fn (mixed $value): bool => $value !== null && (! is_string($value) || $value !== ''),
                ),
            ),
        ];
    }
}
