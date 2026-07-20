<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppInstancePayloads;
use App\Services\Apps\AppResponsePayload;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Processes\ProcessRuntimeApp;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use App\Tools\CaddyTool;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinates destructive cleanup across every concrete instance of one project.
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
        private AppResponsePayload $projectPayloads,
        private AppInstancePayloads $instancePayloads,
    ) {}

    /**
     * @return array{
     *     project: array<string, mixed>,
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
    public function handle(Project $app): array
    {
        $app->loadMissing([
            'node',
            'dependencyAuditSummaries',
            'instances.runtimeMounts',
            'processes.appInstance',
            'schedules',
            'workspaces.processes.appInstance',
        ]);

        $projectPayload = $this->projectPayloads->forApp($app);
        $instancePayloads = $app
            ->instances
            ->map(fn (AppInstance $instance): array => $this->instancePayloads->instance($instance))
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
            + $app->workspaces->sum(fn (Workspace $workspace): int => $workspace->processes->count());
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
                $target['app'],
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
                    $target['app'],
                    $target['process_cleanup_scripts'],
                    $removePath,
                ),
            );

            if ($cleanup->successful() && $removePath) {
                $pathsRemoved++;
            }

            $instanceId = $target['app_instance_id'];
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
            'project' => $projectPayload,
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
     * @return list<array{app: Project, app_instance_id: int|null, adopted: bool, identity: string, node: Node, process_cleanup_scripts: list<string>, runtime_slug: string}>
     */
    private function cleanupTargets(Project $app): array
    {
        $targets = [];
        $instances = $app->instances;
        $hasInstances = $instances->isNotEmpty();
        $appProcesses = $app->processes;
        $appWorkspaces = $app->workspaces;

        foreach ($instances as $instance) {
            if ($instance->driver !== AppInstanceDriver::Orbit) {
                continue;
            }

            $node = $this->placement->nodeForInstance($instance);

            if (! $node instanceof Node) {
                continue;
            }

            $runtimeApp = ProcessRuntimeApp::make($app, $node, $instance);
            $runtimeApp->setRelation('node', $node);
            $workspaces = [];

            foreach ($appWorkspaces as $workspace) {
                if ($workspace->app_instance_id === $instance->id) {
                    $workspaces[] = $workspace;
                }
            }

            $runtimeApp->setRelation('workspaces', new Collection($workspaces));
            $scripts = [];

            foreach ($appProcesses as $process) {
                if ($process->app_instance_id !== $instance->id) {
                    continue;
                }

                foreach ($this->runtimeUnits->forProcess($runtimeApp, $process) as $runtimeUnit) {
                    $scripts[] = $this->runtimeDrivers
                        ->forProcess($process)
                        ->cleanupScript($runtimeUnit['name']);
                }
            }

            foreach ($workspaces as $workspace) {
                $workspaceProcesses = $workspace->processes;

                foreach ($workspaceProcesses as $process) {
                    if ($process->app_instance_id !== $instance->id) {
                        continue;
                    }

                    $driver = $this->runtimeDrivers->forProcess($process);
                    $scripts[] = $driver->cleanupScript(
                        $driver->runtimeUnitName($runtimeApp, $process, $workspace),
                    );
                }
            }

            $targets[] = [
                'app' => $runtimeApp,
                'app_instance_id' => $instance->id,
                'adopted' => $instance->adopted,
                'identity' => "{$app->name}.{$instance->name}",
                'node' => $node,
                'process_cleanup_scripts' => array_values(array_unique($scripts)),
                'runtime_slug' => "{$app->name}-{$instance->name}",
            ];
        }

        if ($targets !== [] || $hasInstances || ! $app->node instanceof Node) {
            return $targets;
        }

        return [[
            'app' => $app,
            'app_instance_id' => null,
            'adopted' => $app->adopted,
            'identity' => $app->name,
            'node' => $app->node,
            'process_cleanup_scripts' => [],
            'runtime_slug' => $app->name,
        ]];
    }

    /**
     * @return list<array{code: string, family: string, message: string, next_command: string}>
     */
    private function unresolvedOrbitCleanupWarnings(Project $app): array
    {
        $unresolvedIdentities = [];

        foreach ($app->instances as $instance) {
            if ($instance->driver !== AppInstanceDriver::Orbit) {
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
    private function occupiedAppPlacements(Project $removedApp): array
    {
        $occupiedPlacements = [];
        $otherApps = Project::query()
            ->whereKeyNot($removedApp->id)
            ->with(['node', 'instances'])
            ->get();

        foreach ($otherApps as $app) {
            if ($app->instances->isEmpty()) {
                if ($app->node instanceof Node) {
                    $occupiedPlacements[$this->appPathKey($app->node, $app->path)] = true;
                }

                continue;
            }

            foreach ($app->instances as $instance) {
                $node = $this->placement->nodeForInstance($instance);

                if (! $node instanceof Node) {
                    continue;
                }

                $runtimeApp = ProcessRuntimeApp::make($app, $node, $instance);
                $occupiedPlacements[$this->appPathKey($node, $runtimeApp->path)] = true;
            }
        }

        return $occupiedPlacements;
    }

    /**
     * @param  array<string, true>  $occupiedAppPlacements
     */
    private function shouldRemoveAppPath(
        bool $adopted,
        Project $runtimeApp,
        Node $node,
        array $occupiedAppPlacements,
    ): bool {
        if ($adopted) {
            return false;
        }

        return ! array_key_exists($this->appPathKey($node, $runtimeApp->path), $occupiedAppPlacements);
    }

    /**
     * @param  iterable<ProxyRoute>  $proxyRoutes
     * @return array{list<array<string, int|string|bool|null>>, array<int, int>}
     */
    private function instanceCleanupInventory(Project $app, iterable $proxyRoutes): array
    {
        $rows = [];
        $rowIndexByInstanceId = [];

        foreach ($app->instances as $instance) {
            $node = $this->placement->nodeForInstance($instance);
            $placement = $this->instancePayloads->placement($instance);
            $url = is_string($placement['url'] ?? null) ? $placement['url'] : '';
            $host = parse_url($url, PHP_URL_HOST);
            $proxyRoutesRemoved = 0;
            $workspacesRemoved = 0;
            $processesRemoved = 0;

            foreach ($proxyRoutes as $route) {
                if (
                    $node instanceof Node
                    && $route->node_id === $node->id
                    && is_string($host)
                    && $route->domain === $host
                ) {
                    $proxyRoutesRemoved++;
                }
            }

            foreach ($app->workspaces as $workspace) {
                if ($workspace->app_instance_id !== $instance->id) {
                    continue;
                }

                $workspacesRemoved++;
                $processesRemoved += $workspace->processes->count();
            }

            foreach ($app->processes as $process) {
                if ($process->app_instance_id === $instance->id) {
                    $processesRemoved++;
                }
            }

            $rowIndexByInstanceId[$instance->id] = count($rows);
            $rows[] = [
                'instance' => "{$app->name}.{$instance->name}",
                'serving_node' => $node?->name,
                'proxy_routes_removed' => $proxyRoutesRemoved,
                'schedules_removed' => $app->schedules->where('app_instance_id', $instance->id)->count(),
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
        Project $app,
        array $processCleanupScripts,
        bool $removeAppPath,
    ): string {
        $domain = parse_url($app->url(), PHP_URL_HOST) ?: $app->name;
        $commands = [
            'sudo rm -f '.escapeshellarg("/etc/caddy/sites/{$domain}.caddy"),
        ];

        array_push($commands, ...$processCleanupScripts);

        $commands[] = CaddyTool::reloadCommand().' || true';

        if ($removeAppPath) {
            $commands[] = 'sudo rm -rf '.escapeshellarg($app->path);
        }

        return implode("\n", $commands);
    }
}
