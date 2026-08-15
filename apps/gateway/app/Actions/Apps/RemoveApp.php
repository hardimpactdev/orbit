<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\InstancePayloads;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinates destructive cleanup across every concrete instance of one app.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
final readonly class RemoveApp
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private ProcessRuntimeUnitPayload $runtimeUnits,
        private AppRuntimeContainerManager $appRuntimeContainerManager,
        private ToolScriptDispatcher $scripts,
        private WorkspacePlacement $placement,
    ) {}

    /**
     * @return array{
     *     app: array<string, mixed>,
     *     instances: list<array<string, mixed>>,
     *     result: array{action: string},
     *     cleanup: array{
     *         aggregate: array{
     *             instances_removed: int,
     *             proxy_routes_removed: int,
     *             workspaces_removed: int,
     *             schedules_removed: int,
     *             processes_removed: int,
     *             runtime_containers_removed: int,
     *             runtime_configs_removed: int,
     *             paths_removed: int,
     *         },
     *         instances: list<array<string, int|string|bool|null>>,
     *     },
     *     warnings: list<array{code: string, family: string, message: string, next_command: string}>
     * }
     */
    public function handle(App $app): array
    {
        $app->loadMissing([
            'node',
            'dependencyAuditSummaries',
            'instances.runtimeMounts',
            'instances.latestDeploymentRun',
            'processes.instance',
            'schedules',
            'workspaces.processes.instance',
        ]);

        $projectPayload = app(AppResponsePayload::class)->forApp($app);
        $instancePayloads = $app
            ->instances
            ->map(static fn (Instance $instance): array => app(InstancePayloads::class)->instance($instance))
            ->values()
            ->all();
        /** @var list<array<string, mixed>> $instancePayloads */
        $isPhpApp = $app->runtimeKind() === AppRuntimeKind::Php;
        $cleanupTargets = $this->cleanupTargets($app);
        $cleanupWarnings = $this->unresolvedOrbitCleanupWarnings($app);
        $occupiedAppPlacements = $this->occupiedAppPlacements($app);
        $proxyRoutes = ProxyRoute::query()
            ->where('app_id', $app->id)
            ->get();
        [$instanceCleanupRows, $cleanupRowIndexByInstanceId] = $this->instanceCleanupInventory($app, $proxyRoutes);
        $proxyRouteIds = $proxyRoutes->pluck('id')->all();
        $workspacesRemoved = $app->workspaces->count();
        $schedulesRemoved = $app->schedules->count();
        $processesRemoved =
            $app->processes->count()
            + $app->workspaces->sum(static fn (Workspace $workspace): int => $workspace->processes->count());
        DB::transaction(function () use ($app, $proxyRouteIds): void {
            $workspaceIds = Workspace::query()
                ->where('app_id', $app->id)
                ->pluck('id')
                ->all();

            $app->processes()->delete();

            if ($workspaceIds !== []) {
                Process::query()
                    ->where('owner_type', Workspace::class)
                    ->whereIn('owner_id', $workspaceIds)
                    ->delete();
            }

            $app->delete();

            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }
        });

        $containerOutcomes = [];
        $configOutcomes = [];
        $pathsRemoved = 0;
        $warnings = $cleanupWarnings;

        foreach ($cleanupTargets as $target) {
            $node = $target['node'];
            $identity = $target['identity'];
            $containerOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
            $configOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
            $removePath = $this->shouldRemoveAppPath(
                $target['adopted'],
                $target['path'],
                $node,
                $occupiedAppPlacements,
            );

            if ($isPhpApp) {
                try {
                    $containerOutcome = $this->appRuntimeContainerManager->remove($node, $target['runtime_slug']);
                } catch (Throwable) {
                    $containerOutcome = AppRuntimeArtifactRemovalOutcome::FailedRemaining;
                }
                $containerOutcomes[] = $containerOutcome;

                try {
                    $configOutcome = $this->appRuntimeContainerManager->removeRuntimeConfigFile(
                        $node,
                        $target['runtime_slug'],
                    );
                } catch (Throwable) {
                    $configOutcome = AppRuntimeArtifactRemovalOutcome::FailedRemaining;
                }
                $configOutcomes[] = $configOutcome;
            }

            if ($containerOutcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => "Instance runtime unit for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }

            if ($configOutcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
                $warnings[] = [
                    'code' => 'instance.runtime_config_extra',
                    'family' => 'instance',
                    'message' => "Managed instance runtime configuration for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=instance --restore',
                ];
            }

            $cleanup = $this->scripts->run(
                $node,
                'orbit',
                'remove',
                $this->renderNonRuntimeCleanupScript(
                    $target['host'],
                    $target['path'],
                    $target['process_cleanup_scripts'],
                    $removePath,
                ),
            );

            if ($cleanup->successful() && $removePath) {
                $pathsRemoved++;
            }

            $instanceId = $target['instance_id'];
            $cleanupRowIndex = is_int($instanceId) ? $cleanupRowIndexByInstanceId[$instanceId] ?? null : null;

            if (is_int($cleanupRowIndex)) {
                $instanceCleanupRows[$cleanupRowIndex]['runtime_container_removed'] =
                    $containerOutcome === AppRuntimeArtifactRemovalOutcome::Removed;
                $instanceCleanupRows[$cleanupRowIndex]['runtime_config_removed'] =
                    $configOutcome === AppRuntimeArtifactRemovalOutcome::Removed;
                $instanceCleanupRows[$cleanupRowIndex]['path_removed'] = $cleanup->successful() && $removePath;
            }

            if (! $cleanup->successful()) {
                $warnings[] = [
                    'code' => 'instance.cleanup_failed',
                    'family' => 'instance',
                    'message' => "Instance non-runtime artifacts for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=instance --restore',
                ];
            }
        }

        return [
            'app' => $projectPayload,
            'instances' => $instancePayloads,
            'result' => ['action' => 'removed'],
            'cleanup' => [
                'aggregate' => [
                    'instances_removed' => count($instancePayloads),
                    'proxy_routes_removed' => count($proxyRouteIds),
                    'workspaces_removed' => $workspacesRemoved,
                    'schedules_removed' => $schedulesRemoved,
                    'processes_removed' => $processesRemoved,
                    'runtime_containers_removed' => collect($containerOutcomes)->filter(
                        static fn (AppRuntimeArtifactRemovalOutcome $outcome): bool => (
                            $outcome === AppRuntimeArtifactRemovalOutcome::Removed
                        ),
                    )->count(),
                    'runtime_configs_removed' => collect($configOutcomes)->filter(
                        static fn (AppRuntimeArtifactRemovalOutcome $outcome): bool => (
                            $outcome === AppRuntimeArtifactRemovalOutcome::Removed
                        ),
                    )->count(),
                    'paths_removed' => $pathsRemoved,
                ],
                'instances' => $instanceCleanupRows,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<array{app: App, instance_id: int|null, adopted: bool, identity: string, node: Node, path: string, host: string, process_cleanup_scripts: list<string>, runtime_slug: string}>
     */
    private function cleanupTargets(App $app): array
    {
        $targets = [];
        $instances = $app->instances;
        $appProcesses = $app->processes;
        $appWorkspaces = $app->workspaces;

        foreach ($instances as $instance) {
            if ($instance->driver !== InstanceDriver::Orbit) {
                continue;
            }

            $node = $this->placement->nodeForInstance($instance);

            if (! $node instanceof Node) {
                continue;
            }

            // Placement is resolved from the instance; no synthetic runtime app
            // is fabricated. Process-unit lookups self-resolve from the process.
            $workspaces = [];

            foreach ($appWorkspaces as $workspace) {
                if ($workspace->instance_id === $instance->id) {
                    $workspaces[] = $workspace;
                }
            }

            $scripts = [];

            foreach ($appProcesses as $process) {
                if ($process->instance_id !== $instance->id) {
                    continue;
                }

                foreach ($this->runtimeUnits->forProcess($app, $process) as $runtimeUnit) {
                    $scripts[] = $this->runtimeDrivers
                        ->forProcess($process)
                        ->cleanupScript($runtimeUnit['name']);
                }
            }

            foreach ($workspaces as $workspace) {
                $workspaceProcesses = $workspace->processes;

                foreach ($workspaceProcesses as $process) {
                    if ($process->instance_id !== $instance->id) {
                        continue;
                    }

                    $driver = $this->runtimeDrivers->forProcess($process);
                    $scripts[] = $driver->cleanupScript(
                        $driver->runtimeUnitName($app, $process, $workspace),
                    );
                }
            }

            $targets[] = [
                'app' => $app,
                'instance_id' => $instance->id,
                'adopted' => $instance->adopted,
                'identity' => "{$app->name}.{$instance->name}",
                'node' => $node,
                'path' => $this->placement->runtimePath($app, $instance),
                'host' => $this->cleanupHost($this->placement->runtimeUrl($app, $instance), $app->name),
                'process_cleanup_scripts' => array_values(array_unique($scripts)),
                'runtime_slug' => "{$app->name}-{$instance->name}",
            ];
        }

        // An app with no concrete instance has no placement to clean up; the
        // DB record removal in handle() is sufficient. App owns no placement.
        return $targets;
    }

    /**
     * @return list<array{code: string, family: string, message: string, next_command: string}>
     */
    private function unresolvedOrbitCleanupWarnings(App $app): array
    {
        $unresolvedIdentities = [];

        foreach ($app->instances as $instance) {
            if ($instance->driver !== InstanceDriver::Orbit) {
                continue;
            }

            if ($this->placement->nodeForInstance($instance) instanceof Node) {
                continue;
            }

            $unresolvedIdentities[] = "{$app->name}.{$instance->name}";
        }

        if ($unresolvedIdentities === []) {
            return [];
        }

        sort($unresolvedIdentities);
        $identities = implode(', ', $unresolvedIdentities);

        return [[
            'code' => 'instance.cleanup_failed',
            'family' => 'instance',
            'message' => "Local cleanup was skipped for Orbit instances with unresolved node placement: {$identities}.",
            'next_command' => 'doctor --family=instance --restore',
        ]];
    }

    /**
     * @return array<string, true>
     */
    private function occupiedAppPlacements(App $removedApp): array
    {
        $occupiedPlacements = [];
        $otherApps = App::query()
            ->whereKeyNot($removedApp->id)
            ->with('instances')
            ->get();

        foreach ($otherApps as $app) {
            foreach ($app->instances as $instance) {
                $node = $this->placement->nodeForInstance($instance);

                if (! $node instanceof Node) {
                    continue;
                }

                $occupiedPlacements[$this->appPathKey(
                    $node,
                    $this->placement->runtimePath($app, $instance),
                )] = true;
            }
        }

        return $occupiedPlacements;
    }

    /**
     * @param  array<string, true>  $occupiedAppPlacements
     */
    private function shouldRemoveAppPath(
        bool $adopted,
        string $path,
        Node $node,
        array $occupiedAppPlacements,
    ): bool {
        if ($adopted) {
            return false;
        }

        return ! array_key_exists($this->appPathKey($node, $path), $occupiedAppPlacements);
    }

    /**
     * @param  iterable<ProxyRoute>  $proxyRoutes
     * @return array{list<array<string, int|string|bool|null>>, array<int, int>}
     */
    private function instanceCleanupInventory(App $app, iterable $proxyRoutes): array
    {
        $rows = [];
        $rowIndexByInstanceId = [];

        foreach ($app->instances as $instance) {
            $node = $this->placement->nodeForInstance($instance);
            $placement = app(InstancePayloads::class)->placement($instance);
            $url = is_string($placement['url'] ?? null) ? $placement['url'] : '';
            $host = parse_url($url, PHP_URL_HOST);
            $proxyRoutesRemoved = 0;
            $workspacesRemoved = 0;
            $processesRemoved = 0;

            foreach ($proxyRoutes as $route) {
                if (
                    ! $node instanceof Node
                    || $route->node_id !== $node->id
                    || ! is_string($host)
                    || $route->domain !== $host
                ) {
                    continue;
                }

                $proxyRoutesRemoved++;
            }

            foreach ($app->workspaces as $workspace) {
                if ($workspace->instance_id !== $instance->id) {
                    continue;
                }

                $workspacesRemoved++;
                $processesRemoved += $workspace->processes->count();
            }

            foreach ($app->processes as $process) {
                if ($process->instance_id !== $instance->id) {
                    continue;
                }

                $processesRemoved++;
            }

            $rowIndexByInstanceId[$instance->id] = count($rows);
            $rows[] = [
                'instance' => "{$app->name}.{$instance->name}",
                'serving_node' => $node?->name,
                'proxy_routes_removed' => $proxyRoutesRemoved,
                'schedules_removed' => $app->schedules->where('instance_id', $instance->id)->count(),
                'workspaces_removed' => $workspacesRemoved,
                'processes_removed' => $processesRemoved,
                'runtime_container_removed' => false,
                'runtime_config_removed' => false,
                'path_removed' => false,
            ];
        }

        return [$rows, $rowIndexByInstanceId];
    }

    private function appPathKey(Node $node, string $path): string
    {
        $normalizedPath = rtrim($path, characters: '/');

        return "{$node->id}:".($normalizedPath === '' ? '/' : $normalizedPath);
    }

    /**
     * @param  list<string>  $processCleanupScripts
     */
    private function renderNonRuntimeCleanupScript(
        string $host,
        string $appPath,
        array $processCleanupScripts,
        bool $removeAppPath,
    ): string {
        $commands = [
            'sudo rm -f '.escapeshellarg("/etc/caddy/sites/{$host}.caddy"),
        ];

        array_push($commands, ...$processCleanupScripts);

        $commands[] = CaddyTool::reloadCommand().' || true';

        if ($removeAppPath) {
            $commands[] = 'sudo rm -rf '.escapeshellarg($appPath);
        }

        return implode("\n", $commands);
    }

    private function cleanupHost(string $url, string $fallback): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $fallback;
    }
}
