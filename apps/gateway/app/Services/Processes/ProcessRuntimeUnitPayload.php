<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Workspaces\WorkspaceRoleGuard;

class ProcessRuntimeUnitPayload
{
    public function __construct(
        private readonly ProcessRuntimeDriverRegistry $runtimeDrivers,
        private readonly WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * @return list<array{name: string, context: string}>
     */
    public function forProcess(
        App $app,
        Process $process,
        ?Workspace $workspaceContext = null,
        ?Node $consumer = null,
    ): array {
        $app->loadMissing('workspaces');
        $process->loadMissing('owner');

        return collect($this->contexts($app, $process, $workspaceContext, $consumer))
            ->map(fn (?Workspace $workspace): array => [
                'name' => $this->runtimeDrivers->forProcess($process)->runtimeUnitName($app, $process, $workspace),
                'context' => $this->contextName($process, $workspace),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<Workspace|null>
     */
    private function contexts(
        App $app,
        Process $process,
        ?Workspace $workspaceContext,
        ?Node $consumer,
    ): array {
        if ($process->owner instanceof Node) {
            return [null];
        }

        if ($workspaceContext instanceof Workspace) {
            return (
                $this->workspaceRoleGuard->allowsWorkspaceTarget($workspaceContext, $consumer)
                    ? [$workspaceContext]
                    : []
            );
        }

        $workspaceOwner = $process->owner;

        if ($workspaceOwner instanceof Workspace) {
            return (
                $this->workspaceRoleGuard->allowsWorkspaceTarget($workspaceOwner, $consumer)
                    ? [$workspaceOwner]
                    : []
            );
        }

        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $containerName = $config['container_name'] ?? null;

        if (is_string($containerName) && trim($containerName) !== '') {
            return [null];
        }

        $workspaces = $process->app_instance_id === null
            ? $app->workspaces
            : $app->workspaces->where('app_instance_id', $process->app_instance_id);

        $workspaceModels = [];

        foreach ($workspaces as $workspace) {
            if (
                $workspace instanceof Workspace
                && $this->workspaceRoleGuard->allowsWorkspaceTarget($workspace, $consumer)
            ) {
                $workspaceModels[] = $workspace;
            }
        }

        return [null, ...$workspaceModels];
    }

    private function contextName(Process $process, ?Workspace $workspace): string
    {
        $process->loadMissing('owner');

        if ($process->owner instanceof Node) {
            return 'node';
        }

        return $workspace instanceof Workspace ? $workspace->name : 'main';
    }
}
