<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Actions\Processes\RecordProcessEvent;
use App\Contracts\SiteCertificateInstaller;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\ProcessEventType;
use App\Enums\WorkspaceLifecyclePhase;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\WorkspaceUnsupportedForProduction;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Models\WorkspaceRun;
use App\Models\WorkspaceRunStep;
use App\Models\WorkspaceStep;
use App\Services\Apps\LaravelViteDevServerEnvironment;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Workspaces\EnsureWorkspaceProxyRoute;
use App\Services\Workspaces\WorkspaceEnvInitializer;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceReadinessProbe;
use App\Services\Workspaces\WorkspaceRoleGuard;
use App\Services\Workspaces\WorkspaceRuntimeContainerApplyException;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use App\Services\Workspaces\WorkspaceRuntimeImageUnavailableException;
use App\Services\Workspaces\WorkspaceSetupStepRunner;
use App\Services\Workspaces\WorkspaceStepPolicyService;
use App\Support\Streaming\NullProgressReporter;
use RuntimeException;
use Throwable;

final readonly class SetupWorkspace
{
    public function __construct(
        private EnsureWorkspaceProxyRoute $proxyRoute,
        private WorkspaceRuntimeContainerRenderer $runtimeContainerRenderer,
        private WorkspaceRuntimeContainerManager $runtimeContainerManager,
        private WorkspaceSetupStepRunner $stepRunner,
        private WorkspaceReadinessProbe $readinessProbe,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private WorkspaceRoleGuard $roleGuard,
        private EnsureFrankenPhpRuntimeProcess $ensureFrankenPhpRuntimeProcess,
        private LaravelViteDevServerEnvironment $vite,
        private WorkspacePlacement $placement,
        private WorkspaceStepPolicyService $stepPolicy,
        private RecordProcessEvent $recordProcessEvent,
        private WorkspaceEnvInitializer $envInitializer,
    ) {}

    /**
     * @return array{
     *     app: string,
     *     instance: string,
     *     workspace: string,
     *     node: string,
     *     path: string,
     *     url: string,
     *     action: 'set_up'|'adopted'|'converged',
     *     warnings: list<array<string, string>|array{code: string, family: string, message: string, next_command: string}>,
     *     setup_steps: array{status: string, count: int, message: string},
     *     processes: array{status: string, count: int, names: list<string>, message: string},
     *     http_probe: array{reachable: bool, status: string},
     * }
     */
    public function handle(App $app, Workspace $workspace, Node $node, bool $isAdoption = false): array
    {
        $result = $this->plan($app, $workspace, $node, $isAdoption)->run(new NullProgressReporter);

        if (! $result->isSuccessful()) {
            $failure = $result->failure();

            throw new RuntimeException($failure['message'] ?? 'Workspace setup failed.');
        }

        return $result->data();
    }

    public function plan(App $app, Workspace $workspace, Node $node, bool $isAdoption = false): SetupWorkspacePlan
    {
        $workspace->loadMissing('app');

        try {
            $this->roleGuard->ensureNodeSupportsWorkspaces($app, $node);
        } catch (WorkspaceUnsupportedForProduction $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return new SetupWorkspacePlan($this, $workspace, $app, $node, $isAdoption);
    }

    public function prepareWorkspaceState(Workspace $workspace): void
    {
        $workspace->update([
            'lifecycle_status' => WorkspaceLifecycleStatus::SetupPending,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    public function registerProxyRoutes(Workspace $workspace): array
    {
        return $this->proxyRoute->handle($workspace);
    }

    public function initializeEnvironment(Workspace $workspace): void
    {
        $this->envInitializer->initialize($workspace);
    }

    public function hasSetupSteps(App $app, Workspace $workspace): bool
    {
        $workspace->loadMissing('instance');

        return $this->stepPolicy->hasStepsFor(
            $app,
            WorkspaceLifecyclePhase::Setup,
            $workspace->instance,
        );
    }

    /**
     * Converge the FrankenPHP runtime container for PHP workspaces. Static /
     * non-PHP workspaces inherit the parent app's runtime kind and do not get
     * a runtime container.
     *
     * @return array{code: string, family: string, message: string, next_command: string}|null
     */
    public function enactRuntimeContainer(Workspace $workspace, Node $node): ?array
    {
        $workspace->loadMissing('app');
        $app = $workspace->app;

        if (! $app instanceof App || $app->runtimeKind() !== AppRuntimeKind::Php) {
            return null;
        }

        try {
            $this->ensureFrankenPhpRuntimeProcess->forWorkspace($workspace);
            $container = $this->runtimeContainerRenderer->render($workspace);
            $this->runtimeContainerManager->apply($node, $container);
        } catch (WorkspaceRuntimeImageUnavailableException $exception) {
            return [
                'code' => 'workspace.php_version_unavailable',
                'family' => 'workspace',
                'message' => "PHP {$exception->phpVersion} runtime image '{$exception->image}' is not available on node '{$node->name}'. Make the image available, then run doctor.",
                'next_command' => 'doctor --family=workspace --restore',
            ];
        } catch (WorkspaceRuntimeContainerApplyException $exception) {
            $code = $exception->hadExistingContainer
                ? 'process.runtime_unit_mismatch'
                : 'process.runtime_unit_missing';
            $action = $exception->hadExistingContainer ? 'recreated' : 'installed';

            return [
                'code' => $code,
                'family' => 'process',
                'message' => "FrankenPHP runtime container for workspace '{$workspace->name}' could not be {$action} on '{$node->name}': {$exception->getMessage()}",
                'next_command' => 'doctor --family=process --restore',
            ];
        } catch (Throwable $exception) {
            return [
                'code' => 'process.runtime_unit_missing',
                'family' => 'process',
                'message' => "FrankenPHP runtime container for workspace '{$workspace->name}' could not be installed on '{$node->name}': {$exception->getMessage()}",
                'next_command' => 'doctor --family=process --restore',
            ];
        }

        return null;
    }

    /**
     * @param  (callable(string, WorkspaceStep, int, int): void)|null  $onStepProgress
     * @return array{status: 'skipped'|'completed', message: string, count: int}|array{status: 'failed', message: string, count: 0, step: string, exit_code: int}
     */
    public function runSetupSteps(
        Workspace $workspace,
        App $app,
        Node $node,
        ?callable $onStepProgress = null,
    ): array {
        $workspace->loadMissing('instance');

        $steps = $this->stepPolicy->stepsFor(
            $app,
            WorkspaceLifecyclePhase::Setup,
            $workspace->instance,
        );

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

        $success = $this->stepRunner->run(
            $run,
            $renderedSteps,
            $workspace->path,
            $env,
            $node,
            $app,
            $onStepProgress,
        );

        if (! $success) {
            /** @var WorkspaceRunStep|null $failedStep */
            $failedStep = $run
                ->runSteps()
                ->orderByDesc('id')
                ->first();
            $failedCommand = $failedStep->command ?? 'unknown';
            $failedExitCode = $failedStep->exit_code ?? 1;

            $message = 'Workspace setup failed.';
            if ($failedStep !== null) {
                $message = "Setup step failed: {$failedCommand}";
                if ($failedStep->output !== null && $failedStep->output !== '') {
                    $message .= "\n{$failedStep->output}";
                }
            }

            return [
                'status' => 'failed',
                'message' => $message,
                'count' => 0,
                'step' => $failedCommand,
                'exit_code' => $failedExitCode,
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
        $context = $this->processOwnerContext($app, $workspace, $node);

        $appProcesses = $context->effectiveWorkspaceProcessesWithoutRuntime();

        if ($appProcesses->isEmpty()) {
            return ['success' => true, 'message' => 'No processes', 'count' => 0, 'names' => []];
        }

        $host = $this->vite->host($app, $workspace);

        try {
            $this->siteCertificateInstaller->ensureFor($node, $host);
        } catch (Throwable) {
            return [
                'success' => false,
                'message' => "Failed to install process TLS certificate for '{$host}'. Run doctor to converge process runtime units.",
                'count' => 0,
                'names' => [],
            ];
        }

        $names = [];

        foreach ($appProcesses as $process) {
            if (! $process instanceof Process) {
                continue;
            }

            $driver = $this->runtimeDrivers->forProcess($process);
            $runtimeWorkspace = $context->runtimeWorkspaceFor($process);
            $runtimeUnit = $driver->runtimeUnitName($app, $process, $runtimeWorkspace);

            if (! $driver->apply($node, $app, $process, $runtimeWorkspace)) {
                return [
                    'success' => false,
                    'message' => "Failed to start process '{$process->name}'. Run doctor to converge process runtime units.",
                    'count' => 0,
                    'names' => [],
                ];
            }

            $this->recordProcessEvent->handle(
                ProcessEventType::Starting,
                $context->eventApp(),
                $runtimeWorkspace,
                $process,
                $node,
                $runtimeUnit,
            );

            try {
                $started = $driver->start($node, $runtimeUnit);
            } catch (\Throwable $exception) {
                $this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $runtimeWorkspace,
                    $process,
                    $node,
                    $runtimeUnit,
                );

                throw $exception;
            }

            if (! $started) {
                $this->recordProcessEvent->handle(
                    ProcessEventType::Failed,
                    $context->eventApp(),
                    $runtimeWorkspace,
                    $process,
                    $node,
                    $runtimeUnit,
                );

                return [
                    'success' => false,
                    'message' => "Failed to start process '{$process->name}'. Run doctor to converge process runtime units.",
                    'count' => 0,
                    'names' => [],
                ];
            }

            $this->recordProcessEvent->handle(
                ProcessEventType::Started,
                $context->eventApp(),
                $runtimeWorkspace,
                $process,
                $node,
                $runtimeUnit,
            );

            $names[] = $process->name;
        }

        return [
            'success' => true,
            'message' => implode(', ', $names),
            'count' => count($names),
            'names' => $names,
        ];
    }

    public function hasProcesses(App $app, Workspace $workspace, Node $node): bool
    {
        return $this
            ->processOwnerContext($app, $workspace, $node)
            ->effectiveWorkspaceProcessesWithoutRuntime()
            ->isNotEmpty();
    }

    private function processOwnerContext(App $app, Workspace $workspace, Node $node): ProcessOwnerContext
    {
        return new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: $workspace,
            owner: $workspace,
            instance: $workspace->instance,
        );
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
     * @param  array<int, WorkspaceStep>  $steps
     * @return list<WorkspaceStep>
     */
    private function renderSteps(array $steps, string $workspaceName): array
    {
        return array_values(array_map(function (WorkspaceStep $step) use ($workspaceName): WorkspaceStep {
            $rendered = clone $step;
            $rendered->command = str_replace(
                ['__ORBIT_WORKSPACE_NAME__'],
                [$workspaceName],
                $step->command,
            );

            return $rendered;
        }, $steps));
    }

    /**
     * @param  array<int, WorkspaceStep>  $steps
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
        return (
            [
                'ORBIT_APP' => $app->name,
                'ORBIT_APP_PATH' => $this->placement->appPathForWorkspace($workspace) ?? '',
                'ORBIT_WORKSPACE_NAME' => $workspace->name,
                'ORBIT_WORKSPACE_PATH' => $workspace->path,
                'ORBIT_URL' => $workspace->url(),
                'ORBIT_PHP_VERSION' => $workspace->effectivePhpVersion() ?? $app->php_version,
            ] + $this->vite->shellVariables($app, $node, $workspace)
        );
    }
}
