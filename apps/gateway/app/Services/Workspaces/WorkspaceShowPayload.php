<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;

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
        $workspace->loadMissing(['app.instances', 'instance', 'app.processes']);

        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        /** @var array<string, mixed> $workspacePayload */
        $workspacePayload = [
            'name' => $workspace->name,
            'app' => $app?->name,
            'instance' => $workspace->instance->name,
            'node' => $node?->name,
            'path' => $workspace->path,
            'url' => $workspace->url(),
            'php_version' => $workspace->effectivePhpVersion(),
            'php_inherited' => $workspace->php_version === null,
            'adopted' => false,
            'lifecycle_status' => $workspace->lifecycle_status->value,
        ];

        $context = null;

        if ($app !== null && $node !== null) {
            $context = new ProcessOwnerContext(
                node: $node,
                app: $app,
                workspace: $workspace,
                owner: $workspace,
                instance: $workspace->instance,
            );
        }

        $inheritedProcesses = array_values($context
            ?->effectiveWorkspaceProcesses()
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
