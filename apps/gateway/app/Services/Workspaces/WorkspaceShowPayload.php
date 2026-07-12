<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Process;
use App\Models\Workspace;

class WorkspaceShowPayload
{
    public function __construct(
        private readonly WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return array{
     *     workspace: array<string, mixed>,
     *     node: array{name: string|null, host: string|null},
     *     inherited_processes: list<array{name: string}>,
     * }
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance', 'app.processes']);

        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        /** @var array<string, mixed> $workspacePayload */
        $workspacePayload = [
            'name' => $workspace->name,
            'app' => $app?->name,
            'app_instance' => $workspace->appInstance->name,
            'node' => $node?->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'php_version' => $workspace->effectivePhpVersion(),
            'php_inherited' => $workspace->php_version === null,
            'agent_ide' => [
                'adapter' => $workspace->agent_ide === 'none' ? null : $workspace->agent_ide,
                'workspace_id' => $workspace->agent_ide_workspace_id,
            ],
            'adopted' => false,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];

        $inheritedProcesses = array_values($app
            ?->processes
            ->map(fn (Process $process): array => [
                'name' => $process->name,
            ])
            ->values()
            ->all() ?? []);

        return [
            'workspace' => $workspacePayload,
            'node' => [
                'name' => $node?->name,
                'host' => $node?->host,
            ],
            'inherited_processes' => $inheritedProcesses,
        ];
    }
}
