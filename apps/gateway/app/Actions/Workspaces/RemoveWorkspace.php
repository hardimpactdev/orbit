<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Enums\Apps\AppRuntimeKind;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\Workspaces\WorkspaceRuntimeArtifactRemovalOutcome;
use App\Models\App;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Apps\AppCommandRouter;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceStepPolicyService;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RemoveWorkspace
{
    public function __construct(
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private WorkspaceRuntimeContainerManager $workspaceRuntimeContainerManager,
        private ToolScriptDispatcher $scripts,
        private WorkspacePlacement $placement,
        private WorkspaceStepPolicyService $stepPolicy,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     app: string,
     *     app_instance: string,
     *     action: string,
     *     proxy_routes_removed: int,
     *     processes_removed: int,
     *     worktree_removed: bool,
     *     teardown_steps_run: int,
     *     kept_files: bool,
     *     warnings: list<array<string, string>>
     * }
     */
    public function handle(Workspace $workspace, bool $keepFiles = false): array
    {
        $workspace->loadMissing(['app.node', 'app.instances', 'appInstance', 'app.processes']);

        $app = $workspace->app;
        $name = $workspace->name;
        $appName = (string) $app?->name;
        $appInstanceName = $workspace->appInstance->name;
        $isPhpWorkspace = $app?->runtime === AppRuntimeKind::Php;
        $proxyRouteIds = ProxyRoute::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();
        $processCleanupScripts = $this->processCleanupScripts($workspace, $app);
        $workspace->loadMissing(['appInstance', 'app']);
        assert($workspace->app instanceof App, 'Workspace removal requires a parent app.');

        $teardownSteps = $this->stepPolicy->stepsFor(
            $workspace->app,
            WorkspaceLifecyclePhase::Teardown,
            $workspace->appInstance,
        );
        $node = $this->placement->nodeForWorkspace($workspace);

        DB::transaction(function () use ($workspace, $proxyRouteIds): void {
            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }

            $workspace->processes()->delete();
            $workspace->delete();
        });

        $warnings = [];
        $processesRemoved = 0;
        $worktreeRemoved = false;
        $teardownStepsRun = 0;

        if ($node !== null) {
            if ($isPhpWorkspace) {
                try {
                    $containerOutcome = $this->workspaceRuntimeContainerManager->remove($node, $appName, $name);
                } catch (Throwable) {
                    $containerOutcome = WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
                }

                try {
                    $configOutcome = $this->workspaceRuntimeContainerManager->removeRuntimeConfigFile(
                        $node,
                        $appName,
                        $name,
                    );
                } catch (Throwable) {
                    $configOutcome = WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining;
                }

                if ($containerOutcome === WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining) {
                    $warnings[] = [
                        'code' => 'process.runtime_unit_extra',
                        'family' => 'process',
                        'message' => "Workspace runtime unit for '{$name}' could not be removed during cleanup.",
                        'next_command' => 'doctor --family=process --restore',
                    ];
                }

                if ($configOutcome === WorkspaceRuntimeArtifactRemovalOutcome::FailedRemaining) {
                    $warnings[] = [
                        'code' => 'workspace.runtime_config_extra',
                        'family' => 'workspace',
                        'message' => "Managed workspace runtime configuration for '{$name}' could not be removed during cleanup.",
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                }
            }

            $processResult = $this->scripts->run(
                $node,
                'orbit',
                'remove',
                $this->renderProcessRemovalScript($processCleanupScripts),
            );
            $processesRemoved = $processResult->successful() ? count($processCleanupScripts) : 0;

            if (! $processResult->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => 'Workspace inherited runtime units could not be removed during cleanup.',
                    'next_command' => 'doctor --family=process --restore',
                ];
            }

            foreach ($teardownSteps as $teardownStep) {
                $teardownStepsRun++;
                $command = $app instanceof App
                    ? app(AppCommandRouter::class)->routeLifecycleForNodePath(
                        $app,
                        $node,
                        $teardownStep->command,
                        $workspace->path,
                        $this->teardownEnvironment($workspace),
                    )
                    : $teardownStep->command;
                $teardownScript = 'cd '.escapeshellarg($workspace->path)."\n".$command;
                $teardownResult = $this->scripts->run($node, 'orbit', 'remove', $teardownScript);

                if (! $teardownResult->successful()) {
                    $warnings[] = [
                        'code' => 'workspace.teardown_step_failed',
                        'family' => 'workspace',
                        'message' => "Workspace teardown step {$teardownStep->id} failed during cleanup.",
                        'next_command' => 'doctor --family=workspace --restore',
                        'step_id' => (string) $teardownStep->id,
                        'exit_code' => (string) $teardownResult->exitCode,
                    ];
                }
            }

            if (! $keepFiles) {
                $worktreeResult = $this->scripts->run(
                    $node,
                    'orbit',
                    'remove',
                    'sudo rm -rf -- '.escapeshellarg($workspace->path),
                );
                $worktreeRemoved = $worktreeResult->successful();

                if (! $worktreeRemoved) {
                    $warnings[] = [
                        'code' => 'workspace.artifact_extra',
                        'family' => 'workspace',
                        'message' => 'Workspace worktree could not be removed during cleanup.',
                        'next_command' => 'doctor --family=workspace --restore',
                    ];
                }
            }
        }

        return [
            'name' => $name,
            'app' => $appName,
            'app_instance' => $appInstanceName,
            'action' => 'removed',
            'proxy_routes_removed' => count($proxyRouteIds),
            'processes_removed' => $processesRemoved,
            'worktree_removed' => $worktreeRemoved,
            'teardown_steps_run' => $teardownStepsRun,
            'kept_files' => $keepFiles,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function processCleanupScripts(Workspace $workspace, ?App $app): array
    {
        if (! $app instanceof App) {
            return [];
        }

        $app->loadMissing('processes');
        $cleanupScripts = [];

        foreach ($app->processes as $process) {
            if ($process->app_instance_id !== $workspace->app_instance_id) {
                continue;
            }

            $driver = $this->runtimeDrivers->forProcess($process);
            $runtimeUnit = $driver->runtimeUnitName($app, $process, $workspace);
            $cleanupScripts[] = $driver->cleanupScript($runtimeUnit);
        }

        return $cleanupScripts;
    }

    /**
     * @param  list<string>  $processCleanupScripts
     */
    private function renderProcessRemovalScript(array $processCleanupScripts): string
    {
        if ($processCleanupScripts === []) {
            return 'true';
        }

        return implode("\n", $processCleanupScripts);
    }

    /**
     * @return array<string, string>
     */
    private function teardownEnvironment(Workspace $workspace): array
    {
        return [
            'ORBIT_APP' => (string) $workspace->app?->name,
            'ORBIT_APP_PATH' => (string) ($this->placement->appPathForWorkspace($workspace) ?? $workspace->app?->path),
            'ORBIT_WORKSPACE_NAME' => $workspace->name,
            'ORBIT_WORKSPACE_PATH' => $workspace->path,
            'ORBIT_URL' => $workspace->url(),
            'ORBIT_PHP_VERSION' => (string) $workspace->effectivePhpVersion(),
            'VITE_APP_URL' => $workspace->url(),
            'VITE_VALET_HOST' => (string) parse_url($workspace->url(), PHP_URL_HOST),
        ];
    }
}
