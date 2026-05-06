<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Services\Apps\AppAgentIdeDefaults;

class WorkspaceShowPayload
{
    public function __construct(
        private readonly AppAgentIdeDefaults $appAgentIdeDefaults,
        private readonly WorkspaceFpmPoolRenderer $workspaceFpmPoolRenderer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $workspace->loadMissing(['app.node', 'app.processes', 'proxyRoutes', 'runs']);

        $app = $workspace->app;
        $node = $app?->node;
        $route = $workspace->proxyRoutes->first();
        $latestSetupRun = $workspace->runs->first();
        $hostname = parse_url($workspace->url(), PHP_URL_HOST);

        return [
            'workspace' => [
                'name' => $workspace->name,
                'app' => $app?->name,
                'branch' => null,
                'path' => $workspace->path,
                'url' => $workspace->url(),
                'node' => [
                    'name' => $node?->name,
                    'host' => $node?->host,
                ],
                'agent_ide' => $this->agentIde($workspace),
                'runtime_expectations' => [
                    'php_version' => $workspace->effectivePhpVersion(),
                    'php_version_inherited_from' => $workspace->php_version === null ? 'app' : 'workspace',
                    'fpm_pool' => $this->fpmPool($workspace),
                    'hostname' => is_string($hostname) ? $hostname : $workspace->url(),
                ],
                'inherited_processes' => $app?->processes->map(fn (Process $process): array => [
                    'name' => $process->name,
                    'command' => $process->command,
                    'restart_policy' => $process->restart_policy->value,
                    'crash_notification' => $process->crash_notification->value,
                ])->values()->all() ?? [],
                'route' => $route instanceof ProxyRoute ? [
                    'host' => $route->domain,
                    'kind' => $route->kind,
                    'owner' => $route->owner_type,
                ] : null,
                'latest_setup_run' => $latestSetupRun instanceof WorkspaceRun ? [
                    'run_id' => $latestSetupRun->id,
                    'status' => $latestSetupRun->status,
                    'completed_at' => $latestSetupRun->completed_at?->toDateTimeString(),
                ] : null,
            ],
        ];
    }

    /**
     * @return array{adapter: string|null, inherited_from: string, workspace_discovery: string|null}
     */
    private function agentIde(Workspace $workspace): array
    {
        $workspace->loadMissing('app.node');

        if ($workspace->agent_ide !== null && $workspace->agent_ide !== 'none') {
            return [
                'adapter' => $workspace->agent_ide,
                'inherited_from' => 'workspace',
                'workspace_discovery' => $this->workspaceDiscovery($workspace->agent_ide),
            ];
        }

        if ($workspace->agent_ide === 'none') {
            return [
                'adapter' => null,
                'inherited_from' => 'workspace',
                'workspace_discovery' => null,
            ];
        }

        $app = $workspace->app;

        if ($app === null) {
            return [
                'adapter' => null,
                'inherited_from' => 'default',
                'workspace_discovery' => null,
            ];
        }

        $agentIde = $this->appAgentIdeDefaults->payloadFor($app);
        $effectiveAdapter = $agentIde['effective_adapter'];

        return [
            'adapter' => $effectiveAdapter,
            'inherited_from' => $agentIde['source'],
            'workspace_discovery' => $effectiveAdapter === null ? null : $this->workspaceDiscovery($effectiveAdapter),
        ];
    }

    private function workspaceDiscovery(string $adapter): string
    {
        return $adapter === 'opencode' ? 'available' : 'unsupported';
    }

    private function fpmPool(Workspace $workspace): string
    {
        return $this->workspaceFpmPoolRenderer->poolName($workspace);
    }
}
