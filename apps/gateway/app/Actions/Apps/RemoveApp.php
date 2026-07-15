<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Enums\Apps\AppRuntimeArtifactRemovalOutcome;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Processes\ProcessRuntimeApp;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeUnitPayload;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Coordinates destructive cleanup across every concrete instance of one logical app.
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
     *     result: array{action: string},
     *     cleanup: array{
     *         proxy_routes_removed: int,
     *         workspaces_removed: int,
     *         schedules_removed: int,
     *         processes_removed: int,
     *         runtime_container_removed: bool,
     *         runtime_config_removed: bool,
     *     },
     *     warnings: list<array<string, string>>
     * }
     */
    public function handle(App $app): array
    {
        $app->loadMissing([
            'node',
            'instances',
            'processes.appInstance',
            'workspaces.processes.appInstance',
        ]);

        $appPayload = $this->appPayload($app);
        $appName = $app->name;
        $isPhpApp = $app->runtimeKind() === AppRuntimeKind::Php;
        $cleanupTargets = $this->cleanupTargets($app);
        $proxyRouteIds = ProxyRoute::query()
            ->where('app_id', $app->id)
            ->pluck('id')
            ->all();
        $workspacesRemoved = Workspace::query()
            ->where('app_id', $app->id)
            ->count();
        $schedulesRemoved = Schedule::query()
            ->where('app_id', $app->id)
            ->count();
        $processesRemoved = $app->processes()->count();
        $removeAppPath = ! $app->adopted && App::query()
            ->where('id', '!=', $app->id)
            ->where('node_id', $app->node_id)
            ->where('path', $app->path)
            ->doesntExist();

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
        $warnings = [];

        foreach ($cleanupTargets as $target) {
            $node = $target['node'];
            $identity = $target['identity'];
            $containerOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;
            $configOutcome = AppRuntimeArtifactRemovalOutcome::AlreadyAbsent;

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
                    'message' => "App runtime unit for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=process --restore',
                ];
            }

            if ($configOutcome === AppRuntimeArtifactRemovalOutcome::FailedRemaining) {
                $warnings[] = [
                    'code' => 'app.runtime_config_extra',
                    'family' => 'app',
                    'message' => "Managed app runtime configuration for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=app --restore',
                ];
            }

            $cleanup = $this->scripts->run(
                $node,
                'orbit',
                'remove',
                $this->renderNonRuntimeCleanupScript(
                    $target['app'],
                    $target['process_cleanup_scripts'],
                    $removeAppPath,
                ),
            );

            if (! $cleanup->successful()) {
                $warnings[] = [
                    'code' => 'app.cleanup_failed',
                    'family' => 'app',
                    'message' => "App non-runtime artifacts for '{$identity}' could not be removed during cleanup.",
                    'next_command' => 'doctor --family=app --restore',
                ];
            }
        }

        return [
            'app' => $appPayload,
            'result' => ['action' => 'removed'],
            'cleanup' => [
                'proxy_routes_removed' => count($proxyRouteIds),
                'workspaces_removed' => $workspacesRemoved,
                'schedules_removed' => $schedulesRemoved,
                'processes_removed' => $processesRemoved,
                'runtime_container_removed' => $containerOutcomes !== []
                    && collect($containerOutcomes)->every(
                        static fn (AppRuntimeArtifactRemovalOutcome $outcome): bool => (
                            $outcome === AppRuntimeArtifactRemovalOutcome::Removed
                        ),
                    ),
                'runtime_config_removed' => $configOutcomes !== []
                    && collect($configOutcomes)->every(
                        static fn (AppRuntimeArtifactRemovalOutcome $outcome): bool => (
                            $outcome === AppRuntimeArtifactRemovalOutcome::Removed
                        ),
                    ),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'runtime' => $app->runtimeKind()->value,
            'runtime_config' => $app->runtimeConfig()->toArray(),
            'php_version' => $app->php_version,
            'worker_enabled' => $app->worker_enabled,
            'worker_config' => is_array($app->worker_config) ? $app->worker_config : null,
            'adopted' => $app->adopted,
        ];
    }

    /**
     * @return list<array{app: App, identity: string, node: Node, process_cleanup_scripts: list<string>, runtime_slug: string}>
     */
    private function cleanupTargets(App $app): array
    {
        $targets = $app
            ->instances
            ->map(function (AppInstance $instance) use ($app): ?array {
                $node = $this->placement->nodeForInstance($instance);

                if (! $node instanceof Node) {
                    return null;
                }

                $runtimeApp = ProcessRuntimeApp::make($app, $node, $instance);
                $runtimeApp->setRelation('node', $node);
                $workspaces = $app->workspaces->where('app_instance_id', $instance->id)->values();
                $runtimeApp->setRelation('workspaces', $workspaces);
                $scripts = [];

                foreach ($app->processes->where('app_instance_id', $instance->id) as $process) {
                    foreach ($this->runtimeUnits->forProcess($runtimeApp, $process) as $runtimeUnit) {
                        $scripts[] = $this->runtimeDrivers
                            ->forProcess($process)
                            ->cleanupScript($runtimeUnit['name']);
                    }
                }

                foreach ($workspaces as $workspace) {
                    foreach ($workspace->processes as $process) {
                        if (! $process instanceof Process || $process->app_instance_id !== $instance->id) {
                            continue;
                        }

                        $driver = $this->runtimeDrivers->forProcess($process);
                        $scripts[] = $driver->cleanupScript(
                            $driver->runtimeUnitName($runtimeApp, $process, $workspace),
                        );
                    }
                }

                return [
                    'app' => $runtimeApp,
                    'identity' => "{$app->name}.{$instance->name}",
                    'node' => $node,
                    'process_cleanup_scripts' => array_values(array_unique($scripts)),
                    'runtime_slug' => "{$app->name}-{$instance->name}",
                ];
            })
            ->filter(static fn (mixed $target): bool => is_array($target))
            ->values()
            ->all();

        if ($targets !== [] || ! $app->node instanceof Node) {
            return $targets;
        }

        return [[
            'app' => $app,
            'identity' => $app->name,
            'node' => $app->node,
            'process_cleanup_scripts' => [],
            'runtime_slug' => $app->name,
        ]];
    }

    /**
     * @param  list<string>  $processCleanupScripts
     */
    private function renderNonRuntimeCleanupScript(App $app, array $processCleanupScripts, bool $removeAppPath): string
    {
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
