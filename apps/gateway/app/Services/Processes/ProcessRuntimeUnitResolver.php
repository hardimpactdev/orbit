<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;

final readonly class ProcessRuntimeUnitResolver
{
    public function __construct(
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * @return array{app: App, instance: Instance, workspace: Workspace|null, process: Process}|null
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

        $instances = Instance::query()
            ->with('app')
            ->whereHas('app', fn ($query) => $query->where('name', $appName))
            ->when($instanceName !== null, fn ($query) => $query->where('name', $instanceName))
            ->get()
            ->filter(
                fn (Instance $instance): bool => $this->placement->nodeForInstance($instance)?->is($node) === true,
            )
            ->values();
        $instance = $instances->count() === 1 ? $instances->first() : null;
        $app = $instance?->app;

        if (! $instance instanceof Instance || ! $app instanceof App) {
            return null;
        }

        $workspace = null;

        if ($scope !== 'main') {
            $workspace = Workspace::query()
                ->where('app_id', $app->id)
                ->where('instance_id', $instance->id)
                ->where('name', $scope)
                ->first();

            if (! $workspace instanceof Workspace) {
                return null;
            }

            if (! $this->workspaceRoleGuard->allowsWorkspaceTarget($workspace, $node)) {
                return null;
            }
        }

        $process = $workspace instanceof Workspace
            ? $workspace
                ->processes()
                ->where('instance_id', $instance->id)
                ->where('name', $processName)
                ->first()
            : null;

        if (! $process instanceof Process) {
            $process = $app
                ->processes()
                ->where('instance_id', $instance->id)
                ->where('name', $processName)
                ->first();
        }

        if (! $process instanceof Process) {
            return null;
        }

        return [
            'app' => $app,
            'instance' => $instance,
            'workspace' => $workspace,
            'process' => $process,
        ];
    }
}
