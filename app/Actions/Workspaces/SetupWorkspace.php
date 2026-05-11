<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Contracts\RemoteShell;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceStep;
use App\Services\Php\PhpFpmServiceReloader;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Workspaces\EnsureWorkspaceProxyRoute;
use App\Services\Workspaces\WorkspaceFpmPoolRenderer;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use App\Services\Workspaces\WorkspaceSetupStepRunner;
use RuntimeException;

final readonly class SetupWorkspace
{
    public function __construct(
        private RemoteShell $remoteShell,
        private EnsureWorkspaceProxyRoute $proxyRoute,
        private WorkspaceFpmPoolRenderer $fpmRenderer,
        private PhpFpmServiceReloader $fpmServiceReloader,
        private WorkspaceSetupStepRunner $stepRunner,
        private WorkspaceReadinessProbe $readinessProbe,
        private RuntimeBackendProbe $runtimeBackendProbe,
        private SupervisorProgramRenderer $supervisorRenderer,
    ) {}

    /**
     * @return array{
     *     app: string,
     *     workspace: string,
     *     node: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>},
     *     http_probe: array{reachable: bool, status: string},
     * }
     */
    public function handle(App $app, Workspace $workspace, Node $node, bool $isAdoption = false): array
    {
        $workspace->loadMissing('app');
        $app->loadMissing('node');

        $wasAlreadyActive = $workspace->lifecycle_status === WorkspaceLifecycleStatus::Active;

        $this->prepareWorkspaceState($workspace);

        $warnings = [];

        // Phase 2: Proxy Routing
        $routeWarnings = $this->registerProxyRoutes($workspace);
        $warnings = array_merge($warnings, $routeWarnings);

        // Phase 3: Artifact Enactment (FPM pool)
        $fpmWarning = $this->enactFpmPool($workspace, $node);
        if ($fpmWarning !== null) {
            $warnings[] = $fpmWarning;
        }

        // Phase 4: Setup Steps
        $setupResult = $this->runSetupSteps($workspace, $app, $node);

        if ($setupResult['status'] === 'failed') {
            throw new RuntimeException($setupResult['message']);
        }

        // Phase 5: Processes
        $processResult = $this->startProcesses($app, $workspace, $node);
        if (! $processResult['success']) {
            throw new RuntimeException($processResult['message']);
        }

        // Phase 6: HTTP Probe
        $probe = $this->probeReadiness($workspace);

        if (! $probe['reachable']) {
            $warnings[] = [
                'code' => 'workspace.http_probe_unhealthy',
                'family' => 'workspace',
                'message' => "Workspace did not become reachable: {$probe['status']}",
                'next_command' => 'doctor --fix --family=workspace --restore',
            ];
        }

        $this->markActive($workspace);

        // Determine result action
        if ($isAdoption) {
            $action = 'adopted';
        } elseif ($wasAlreadyActive) {
            $action = 'converged';
        } else {
            $action = 'set_up';
        }

        return [
            'app' => $app->name,
            'workspace' => $workspace->name,
            'node' => $node->name,
            'url' => $workspace->url(),
            'action' => $action,
            'warnings' => $warnings,
            'setup_steps' => [
                'status' => $setupResult['status'],
                'count' => $setupResult['count'],
                'message' => $setupResult['message'],
            ],
            'processes' => [
                'status' => 'started',
                'count' => $processResult['count'],
                'names' => $processResult['names'],
            ],
            'http_probe' => $probe,
        ];
    }

    public function prepareWorkspaceState(Workspace $workspace): void
    {
        $workspace->update([
            'lifecycle_status' => WorkspaceLifecycleStatus::SettingUp,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    public function registerProxyRoutes(Workspace $workspace): array
    {
        return $this->proxyRoute->handle($workspace);
    }

    /**
     * @return array{code: string, family: string, message: string, next_command: string}|null
     */
    public function enactFpmPool(Workspace $workspace, Node $node): ?array
    {
        $content = $this->fpmRenderer->content($workspace);
        $path = $this->fpmRenderer->path($workspace);
        $service = $this->fpmRenderer->service($workspace);

        $script = sprintf(
            <<<'SH'
set -e
sudo install -d -m 0755 %s %s %s
printf %%s %s | base64 -d | sudo tee %s >/dev/null
%s
SH,
            escapeshellarg(dirname($path)),
            escapeshellarg(dirname($this->fpmRenderer->socketPath($workspace))),
            escapeshellarg(dirname($this->fpmRenderer->logPath($workspace))),
            escapeshellarg(base64_encode($content)),
            escapeshellarg($path),
            $this->fpmServiceReloader->reloadOrRestartScript($service),
        );

        $result = $this->remoteShell->run($node, $script);

        if ($result->successful()) {
            return null;
        }

        return [
            'code' => 'workspace.fpm_enactment_failed',
            'family' => 'workspace',
            'message' => 'PHP-FPM pool configuration was not enacted. Run doctor to converge workspace artifacts.',
            'next_command' => 'doctor --fix --family=workspace --restore',
        ];
    }

    /**
     * @return array{status: string, message: string, count: int}
     */
    public function runSetupSteps(Workspace $workspace, App $app, Node $node): array
    {
        $steps = WorkspaceStep::query()
            ->where('app_id', $app->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            return [
                'status' => 'skipped',
                'message' => 'No setup steps configured',
                'count' => 0,
            ];
        }

        $stepSetHash = $this->computeStepSetHash($steps->all());

        $latestSuccessfulRun = WorkspaceRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('phase', WorkspaceLifecyclePhase::Setup)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($latestSuccessfulRun instanceof WorkspaceRun && $latestSuccessfulRun->step_set_hash === $stepSetHash) {
            return [
                'status' => 'skipped',
                'message' => 'Already up to date',
                'count' => 0,
            ];
        }

        $run = WorkspaceRun::create([
            'workspace_id' => $workspace->id,
            'phase' => WorkspaceLifecyclePhase::Setup,
            'status' => 'pending',
            'step_set_hash' => $stepSetHash,
            'started_at' => now(),
        ]);

        $env = $this->workspaceEnv($app, $workspace, $node);
        $renderedSteps = $this->renderSteps($steps->all(), $workspace->name);

        $success = $this->stepRunner->run($run, $renderedSteps, $workspace->path, $env, $node);

        if (! $success) {
            $failedStep = $run->runSteps()
                ->orderByDesc('id')
                ->first();

            $message = 'Workspace setup failed.';
            if ($failedStep !== null) {
                $message = "Setup step failed: {$failedStep->command}";
                if ($failedStep->output !== null && $failedStep->output !== '') {
                    $message .= "\n{$failedStep->output}";
                }
            }

            return [
                'status' => 'failed',
                'message' => $message,
                'count' => 0,
            ];
        }

        $count = count($renderedSteps);

        return [
            'status' => 'completed',
            'message' => $count === 1 ? '1 step' : "{$count} steps",
            'count' => $count,
        ];
    }

    /**
     * @return array{success: bool, message: string, count: int, names: list<string>}
     */
    public function startProcesses(App $app, Workspace $workspace, Node $node): array
    {
        $appProcesses = Process::query()
            ->where('app_id', $app->id)
            ->orderBy('sort_order')
            ->get();

        if ($appProcesses->isEmpty()) {
            return ['success' => true, 'message' => 'No processes', 'count' => 0, 'names' => []];
        }

        $probe = $this->runtimeBackendProbe->check($node);

        if (! $probe->available) {
            return [
                'success' => false,
                'message' => "Supervisor is not available on '{$node->name}'. Run doctor to converge process runtime units.",
                'count' => 0,
                'names' => [],
            ];
        }

        $names = [];

        foreach ($appProcesses as $process) {
            $script = $this->supervisorRenderer->installScript($app, $process, $workspace);
            $result = $this->remoteShell->run($node, $script);

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'message' => "Failed to start process '{$process->name}'. Run doctor to converge process runtime units.",
                    'count' => 0,
                    'names' => [],
                ];
            }

            $names[] = $process->name;
        }

        return [
            'success' => true,
            'message' => implode(', ', $names),
            'count' => count($appProcesses),
            'names' => $names,
        ];
    }

    /**
     * @return array{reachable: bool, status: string}
     */
    public function probeReadiness(Workspace $workspace): array
    {
        return $this->readinessProbe->probe($workspace);
    }

    public function markActive(Workspace $workspace): void
    {
        $workspace->update(['lifecycle_status' => WorkspaceLifecycleStatus::Active]);
    }

    /**
     * @param  list<WorkspaceStep>  $steps
     * @return list<WorkspaceStep>
     */
    private function renderSteps(array $steps, string $workspaceName): array
    {
        return array_map(function (WorkspaceStep $step) use ($workspaceName): WorkspaceStep {
            $rendered = clone $step;
            $rendered->command = str_replace(
                ['__ORBIT_WORKSPACE_NAME__'],
                [$workspaceName],
                $step->command,
            );

            return $rendered;
        }, $steps);
    }

    /**
     * @param  list<WorkspaceStep>  $steps
     */
    private function computeStepSetHash(array $steps): string
    {
        $data = array_map(fn (WorkspaceStep $step): array => [
            'command' => $step->command,
            'timeout' => $step->timeoutSeconds(),
        ], $steps);

        return hash('sha256', json_encode($data));
    }

    /**
     * @return array<string, string>
     */
    private function workspaceEnv(App $app, Workspace $workspace, Node $node): array
    {
        $domain = parse_url($workspace->url(), PHP_URL_HOST) ?? "{$workspace->name}.{$app->name}";

        return [
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_PATH' => $app->path,
            'ORBIT_WORKSPACE_NAME' => $workspace->name,
            'ORBIT_WORKSPACE_PATH' => $workspace->path,
            'ORBIT_URL' => $workspace->url(),
            'ORBIT_PHP_VERSION' => $workspace->effectivePhpVersion() ?? $app->php_version,
            'VITE_APP_URL' => $workspace->url(),
            'VITE_VALET_HOST' => $domain,
        ];
    }
}
