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

        return collect([null, ...$app->workspaces->all()])
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->runtimeDrivers->forProcess($process)->runtimeUnitName($app, $process, $workspace),
                'context' => $workspace instanceof Workspace ? $workspace->name : 'main',
            ])
            ->values()
            ->all();
    }
}
