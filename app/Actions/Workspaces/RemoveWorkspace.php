<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Enums\WorkspaceLifecyclePhase;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Models\WorkspaceStep;
use App\Services\Processes\SupervisorProgramRenderer;
use Illuminate\Support\Facades\DB;

final readonly class RemoveWorkspace
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $supervisorProgramRenderer,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     app: string,
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
        $workspace->loadMissing(['app.node', 'app.processes']);

        $name = $workspace->name;
        $appName = (string) $workspace->app?->name;
        $proxyRouteIds = ProxyRoute::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();
        $processProgramNames = $workspace->app?->processes
            ->map(fn (Process $process): string => $this->supervisorProgramRenderer->programName($workspace->app, $process, $workspace))
            ->values()
            ->all() ?? [];
        $teardownSteps = WorkspaceStep::query()
            ->where('app_id', $workspace->app_id)
            ->where('phase', WorkspaceLifecyclePhase::Teardown)
            ->orderBy('sort_order')
            ->get();
        $node = $workspace->app?->node;

        DB::transaction(function () use ($workspace, $proxyRouteIds): void {
            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }

            $workspace->delete();
        });

        $warnings = [];
        $processesRemoved = 0;
        $worktreeRemoved = false;
        $teardownStepsRun = 0;

        if ($node !== null) {
            $processResult = $this->remoteShell->run($node, $this->renderProcessRemovalScript($processProgramNames));
            $processesRemoved = $processResult->successful() ? count($processProgramNames) : 0;

            if (! $processResult->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_extra',
                    'family' => 'process',
                    'message' => 'Workspace inherited runtime units could not be removed during cleanup.',
                    'next_command' => 'doctor --fix --family=process --restore',
                ];
            }

            foreach ($teardownSteps as $teardownStep) {
                $teardownStepsRun++;
                $teardownResult = $this->remoteShell->run($node, $teardownStep->command, [
                    'cwd' => $workspace->path,
                    'timeout' => $teardownStep->timeoutSeconds(),
                    'metadata' => $this->teardownEnvironment($workspace),
                ]);

                if (! $teardownResult->successful()) {
                    $warnings[] = [
                        'code' => 'workspace.teardown_step_failed',
                        'family' => 'workspace',
                        'message' => "Workspace teardown step {$teardownStep->id} failed during cleanup.",
                        'next_command' => 'doctor --fix --family=workspace --restore',
                        'step_id' => (string) $teardownStep->id,
                        'exit_code' => (string) $teardownResult->exitCode,
                    ];
                }
            }

            if (! $keepFiles) {
                $worktreeResult = $this->remoteShell->run($node, 'sudo rm -rf '.escapeshellarg($workspace->path));
                $worktreeRemoved = $worktreeResult->successful();

                if (! $worktreeRemoved) {
                    $warnings[] = [
                        'code' => 'workspace.artifact_extra',
                        'family' => 'workspace',
                        'message' => 'Workspace worktree could not be removed during cleanup.',
                        'next_command' => 'doctor --fix --family=workspace --restore',
                    ];
                }
            }
        }

        return [
            'name' => $name,
            'app' => $appName,
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
     * @param  list<string>  $processProgramNames
     */
    private function renderProcessRemovalScript(array $processProgramNames): string
    {
        if ($processProgramNames === []) {
            return 'true';
        }

        $commands = [];

        foreach ($processProgramNames as $programName) {
            $commands[] = 'sudo supervisorctl stop '.escapeshellarg($programName).' || true';
            $commands[] = 'sudo rm -f '.escapeshellarg("/etc/supervisor/conf.d/{$programName}.conf");
        }

        $commands[] = 'sudo supervisorctl reread || true';
        $commands[] = 'sudo supervisorctl update || true';

        return implode("\n", $commands);
    }

    /**
     * @return array<string, string>
     */
    private function teardownEnvironment(Workspace $workspace): array
    {
        return [
            'ORBIT_APP' => (string) $workspace->app?->name,
            'ORBIT_APP_PATH' => (string) $workspace->app?->path,
            'ORBIT_WORKSPACE_NAME' => $workspace->name,
            'ORBIT_WORKSPACE_PATH' => $workspace->path,
            'ORBIT_URL' => $workspace->url(),
            'ORBIT_PHP_VERSION' => (string) $workspace->effectivePhpVersion(),
            'VITE_APP_URL' => $workspace->url(),
            'VITE_VALET_HOST' => (string) parse_url($workspace->url(), PHP_URL_HOST),
        ];
    }
}
