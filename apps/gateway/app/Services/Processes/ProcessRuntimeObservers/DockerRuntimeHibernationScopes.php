<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Models\Instance;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeContextResolver;

final readonly class DockerRuntimeHibernationScopes
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
    ) {}

    /**
     * @param  list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>  $expectedUnits
     * @param  array<string, array<string, mixed>>  $runtimeUnits
     * @return array<string, string>
     */
    public function byStoppedUnit(
        Process $process,
        Instance $instance,
        array $expectedUnits,
        array $runtimeUnits,
    ): array {
        $scopeKeysByUnit = [];

        foreach ($this->runtimeContextResolver->contexts($process) as $index => $workspace) {
            $unitName = $expectedUnits[$index]['name'] ?? null;

            if (! is_string($unitName) || ! $this->runtimeUnitIsStopped($runtimeUnits[$unitName] ?? null)) {
                continue;
            }

            $scopeKeysByUnit[$unitName] = $workspace instanceof Workspace
                ? "workspace-{$workspace->id}"
                : "app-instance-{$instance->id}";
        }

        return $scopeKeysByUnit;
    }

    /**
     * @param  array<string, mixed>|null  $runtimeUnit
     */
    private function runtimeUnitIsStopped(?array $runtimeUnit): bool
    {
        $state = $runtimeUnit['container_state'] ?? null;

        return ($runtimeUnit['config_exists'] ?? null) === true && is_string($state) && $state !== 'running';
    }
}
