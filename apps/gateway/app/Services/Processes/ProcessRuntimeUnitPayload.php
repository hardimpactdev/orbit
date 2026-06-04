<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;

class ProcessRuntimeUnitPayload
{
    public function __construct(
        private readonly ProcessRuntimeDriverRegistry $runtimeDrivers,
    ) {}

    /**
     * @return list<array{name: string, context: string}>
     */
    public function forProcess(App $app, Process $process): array
    {
        $app->loadMissing('workspaces');
        $process->loadMissing('owner');

        return collect($this->contexts($app, $process))
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->runtimeDrivers->forProcess($process)->runtimeUnitName($app, $process, $workspace),
                'context' => $workspace instanceof Workspace ? $workspace->name : 'main',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<Workspace|null>
     */
    private function contexts(App $app, Process $process): array
    {
        if ($process->owner instanceof Workspace) {
            return [$process->owner];
        }

        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $containerName = $config['container_name'] ?? null;

        if (is_string($containerName) && trim($containerName) !== '') {
            return [null];
        }

        return [null, ...$app->workspaces->all()];
    }
}
