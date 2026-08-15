<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ProcessRuntimeContextResolver
{
    public function __construct(
        private WorkspacePlacement $placement,
        private NodeRoleAssignments $nodeRoleAssignments,
    ) {}

    public function executionNode(Process $process): ?Node
    {
        $process->loadMissing(['owner', 'node', 'instance']);

        if ($process->owner instanceof Node) {
            return $process->owner;
        }

        if ($process->owner instanceof Workspace) {
            $placed = $this->placement->nodeForWorkspace($process->owner);

            if ($placed instanceof Node) {
                return $placed;
            }
        }

        if ($process->instance instanceof Instance) {
            $placed = $this->placement->nodeForInstance($process->instance);

            if ($placed instanceof Node) {
                return $placed;
            }
        }

        return $process->node;
    }

    public function runtime(Process $process): ProcessRuntime
    {
        $raw = $process->getRawOriginal('runtime');

        if (is_string($raw)) {
            return ProcessRuntime::tryFrom($raw) ?? ProcessRuntime::Systemd;
        }

        return $process->runtime ?? ProcessRuntime::Systemd;
    }

    /**
     * @return list<Workspace|null>
     */
    public function contexts(Process $process): array
    {
        $process->loadMissing('owner');
        $owner = $process->owner;

        if ($owner instanceof Workspace) {
            return $this->excludesWorkspaces($process) ? [] : [$owner];
        }

        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $containerName = $config['container_name'] ?? null;

        if (is_string($containerName) && trim($containerName) !== '') {
            return [null];
        }

        $app = $process->app;

        if (! $app instanceof App) {
            return [];
        }

        if ($this->excludesWorkspaces($process)) {
            return [null];
        }

        $app->loadMissing('workspaces');

        $workspaces = $process->instance_id === null
            ? $app->workspaces
            : $app->workspaces->where('instance_id', $process->instance_id);

        /** @var list<Workspace> $workspaceModels */
        $workspaceModels = array_values($workspaces->all());

        return [null, ...$workspaceModels];
    }

    public function loadRuntimeApp(Process $process, bool $withWorkspaces = false): void
    {
        $process->loadMissing(['owner', 'node', 'instance']);
        $logicalApp = $process->ownerApp();
        $instance = $process->instance;
        $node = $process->node;

        if (
            ! $logicalApp instanceof App
            || ! $instance instanceof Instance
            || ! $node instanceof Node
            || $instance->app_id !== $logicalApp->id
        ) {
            return;
        }

        // The logical app is used as-is; process-unit renderers resolve
        // placement from each process's own instance.
        $logicalApp->setRelation('node', $node);

        if ($withWorkspaces) {
            $logicalApp->setRelation(
                'workspaces',
                $this->excludesWorkspaces($process)
                    ? $logicalApp->newCollection()
                    : $logicalApp
                        ->workspaces()
                        ->where('instance_id', $instance->id)
                        ->orderBy('id')
                        ->get(),
            );
        }

        if ($process->owner instanceof App) {
            $process->setRelation('owner', $logicalApp);

            return;
        }

        if ($process->owner instanceof Workspace) {
            $process->owner->setRelation('app', $logicalApp);
        }
    }

    public function excludesWorkspaces(Process $process): bool
    {
        $node = $this->executionNode($process);

        return $node instanceof Node && $this->productionNodeExcludesWorkspaces($node);
    }

    public function productionNodeExcludesWorkspaces(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::AppProduction->value);
    }
}
