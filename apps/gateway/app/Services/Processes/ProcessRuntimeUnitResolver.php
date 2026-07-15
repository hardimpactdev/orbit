<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class ProcessRuntimeUnitResolver
{
    public function __construct(
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{app: App, app_instance: AppInstance, workspace: Workspace|null, process: Process}|null
     */
    public function resolve(Node $node, string $unitName): ?array
    {
        if (! str_starts_with($unitName, 'orbit_')) {
            return null;
        }

        $parts = explode('_', $unitName);

        if (! in_array(count($parts), [4, 5], true) || $parts[0] !== 'orbit') {
            return null;
        }

        $hasInstanceIdentity = count($parts) === 5;
        $appName = $parts[1];
        $instanceName = $hasInstanceIdentity ? $parts[2] : null;
        $scope = $parts[$hasInstanceIdentity ? 3 : 2];
        $processName = $parts[$hasInstanceIdentity ? 4 : 3];

        $instances = AppInstance::query()
            ->with('app')
            ->whereHas('app', fn ($query) => $query->where('name', $appName))
            ->when($instanceName !== null, fn ($query) => $query->where('name', $instanceName))
            ->get()
            ->filter(
                fn (AppInstance $instance): bool => $this->placement->nodeForInstance($instance)?->is($node) === true,
            )
            ->values();
        $appInstance = $instances->count() === 1 ? $instances->first() : null;
        $app = $appInstance?->app;

        if (! $appInstance instanceof AppInstance || ! $app instanceof App) {
            return null;
        }

        $workspace = null;

        if ($scope !== 'main') {
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('app_instance_id', $appInstance->id)
                ->where('name', $scope)
                ->first();

            if (! $workspace instanceof Workspace) {
                return null;
            }
        }

        $process = $workspace instanceof Workspace
            ? $workspace
                ->processes()
                ->where('app_instance_id', $appInstance->id)
                ->where('name', $processName)
                ->first()
            : null;

        if (! $process instanceof Process) {
            $process = $app
                ->processes()
                ->where('app_instance_id', $appInstance->id)
                ->where('name', $processName)
                ->first();
        }

        if (! $process instanceof Process) {
            return null;
        }

        return [
            'app' => $app,
            'app_instance' => $appInstance,
            'workspace' => $workspace,
            'process' => $process,
        ];
    }
}
