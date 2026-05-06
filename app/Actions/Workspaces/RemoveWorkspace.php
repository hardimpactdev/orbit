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
use App\Services\Workspaces\WorkspaceFpmPoolRenderer;
use Illuminate\Support\Facades\DB;

final readonly class RemoveWorkspace
{
    public function __construct(
        private RemoteShell $remoteShell,
        private WorkspaceFpmPoolRenderer $fpmPoolRenderer,
        private SupervisorProgramRenderer $supervisorProgramRenderer,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     app: string,
     *     action: string,
     *     proxy_routes_removed: int,
     *     processes_removed: int,
     *     fpm_config_removed: bool,
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
        $fpmConfigRemoved = false;
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
                    'next_command' => 'doctor --family=process --fix',
                ];
            }

            $teardownResult = $this->remoteShell->run($node, $this->renderTeardownScript($teardownSteps));
            $teardownStepsRun = $teardownResult->successful() ? $teardownSteps->count() : 0;

            if (! $teardownResult->successful()) {
                $warnings[] = [
                    'code' => 'workspace.artifact_extra',
                    'family' => 'workspace',
                    'message' => 'Workspace teardown steps could not be completed during cleanup.',
                    'next_command' => 'doctor --family=workspace --fix',
                ];
            }

            $fpmResult = $this->remoteShell->run($node, $this->renderFpmRemovalScript($workspace));
            $fpmConfigRemoved = $fpmResult->successful();

            if (! $fpmConfigRemoved) {
                $warnings[] = [
                    'code' => 'workspace.artifact_extra',
                    'family' => 'workspace',
                    'message' => 'Workspace PHP-FPM configuration could not be removed during cleanup.',
                    'next_command' => 'doctor --family=workspace --fix',
                ];
            }

            if (! $keepFiles) {
                $worktreeResult = $this->remoteShell->run($node, 'rm -rf '.escapeshellarg($workspace->path));
                $worktreeRemoved = $worktreeResult->successful();

                if (! $worktreeRemoved) {
                    $warnings[] = [
                        'code' => 'workspace.artifact_extra',
                        'family' => 'workspace',
                        'message' => 'Workspace worktree could not be removed during cleanup.',
                        'next_command' => 'doctor --family=workspace --fix',
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
            'fpm_config_removed' => $fpmConfigRemoved,
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
     * @param  iterable<WorkspaceStep>  $steps
     */
    private function renderTeardownScript(iterable $steps): string
    {
        $commands = [];

        foreach ($steps as $step) {
            $commands[] = $step->command;
        }

        return $commands === [] ? 'true' : implode("\n", $commands);
    }

    private function renderFpmRemovalScript(Workspace $workspace): string
    {
        return sprintf(
            <<<'SH'
sudo rm -f %s
sudo systemctl reload %s || sudo systemctl reload php-fpm || true
SH,
            escapeshellarg($this->fpmPoolRenderer->path($workspace)),
            escapeshellarg($this->fpmPoolRenderer->service($workspace)),
        );
    }
}
