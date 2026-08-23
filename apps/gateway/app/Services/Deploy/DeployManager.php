<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Deploy\AddDeployStep;
use App\Actions\Deploy\RemoveDeployStep;
use App\Contracts\AppRuntimeUserResolver;
use App\Contracts\ProgressReporter;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppCommandRouter;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\AppSelectorResolver;
use App\Services\RemoteShell\Exceptions\RemoteShellProtocolException;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class DeployManager
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private AppSelectorResolver $appSelectorResolver,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private ActiveReleaseRuntimeActivator $activeReleaseRuntimeActivator,
        private DeploymentRunLifecycle $deploymentRunLifecycle,
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
        private AppCommandRouter $appCommandRouter = new AppCommandRouter,
        private AddDeployStep $addDeployStep = new AddDeployStep,
        private RemoveDeployStep $removeDeployStep = new RemoveDeployStep,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addStep(
        string $app,
        string $command,
        ?string $title,
        ?int $order,
        int $timeout,
        ?int $retention,
    ): array {
        $instance = $this->productionInstance($app);

        $step = $this->addDeployStep->handle(
            instanceId: $instance->id,
            title: $title ?? $this->titleFromCommand($command),
            command: $command,
            timeoutSeconds: $timeout,
            order: $order,
            retention: $retention,
        );

        return [
            'step' => $this->stepEntity($step),
            'meta' => ['action' => 'created'],
        ];
    }

    /**
     * @return array{steps: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listSteps(string $app): array
    {
        $instance = $this->productionInstance($app);
        $stepModels = $instance
            ->deploySteps()
            ->orderBy('sort_order')
            ->get();
        $steps = $stepModels
            ->map(fn (DeployStep $step): array => $this->stepEntity($step))
            ->all();
        $steps = array_values($steps);

        return [
            'steps' => $steps,
            'meta' => [
                'app' => $instance->app->name,
                'instance' => $instance->name,
                'count' => count($steps),
            ],
        ];
    }

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeStep(string $app, string $selector): array
    {
        $instance = $this->productionInstance($app);
        $step = $this->findStep($instance, $selector);

        if (! $step instanceof DeployStep) {
            throw new GatewayApiException(
                message: "Deployment step '{$selector}' was not found for instance '{$this->targetName(
                    $instance,
                )}'.",
                errorCode: 'deploy.step_not_found',
                errorMeta: [
                    'app' => $instance->app->name,
                    'instance' => $instance->name,
                    'step' => $selector,
                ],
            );
        }

        $entity = $this->stepEntity($step);
        $this->removeDeployStep->handle($step);

        return [
            'step' => $entity,
            'meta' => [
                'action' => 'removed',
                'history_preserved' => true,
            ],
        ];
    }

    /**
     * @return array{run: array<string, mixed>, output?: array{stdout: string, stderr: string}, meta: array<string, mixed>}
     */
    public function run(string $app, ?ProgressReporter $progress = null): array
    {
        $instance = $this->productionInstance($app);
        $model = $this->deployApp($instance);
        $node = $this->placement->runtimeNode($model, $instance);
        $steps = $instance
            ->deploySteps()
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            throw new GatewayApiException(
                message: "Deployment pipeline is empty for instance '{$this->targetName($instance)}'.",
                errorCode: 'deploy.pipeline_empty',
                errorMeta: ['app' => $model->name, 'instance' => $instance->name],
            );
        }

        $progress?->tree('Running Deployment', $this->progressSteps($model, $steps));
        $progress?->stepStart('resolve-instance');
        $progress?->stepDone('resolve-instance', $this->targetName($instance));

        $startedAt = Carbon::now();
        $progress?->stepStart('create-run');
        $run = $this->deploymentRunLifecycle->start($instance, $startedAt);

        $stdout = '';
        $stderr = '';
        $status = 'completed';
        $exitCode = 0;

        try {
            $context = $this->runContext($model, $instance, $run, $startedAt);
            $run->forceFill(['context' => $context])->save();
            $progress?->stepDone('create-run', "#{$run->id}");

            foreach ($steps as $step) {
                $stepStartedAt = now();
                $command = $this->renderCommand($step->command, $context);
                $routedCommand = $this->appCommandRouter->route(
                    $model,
                    $command,
                    $this->environment($context),
                    $instance,
                );
                $progress?->stepStart($this->progressKey($step));
                $result = $this->runStep(
                    $node ?? throw new GatewayApiException(
                        message: "App '{$model->name}' has no owning node.",
                        errorCode: 'deploy.execution_failed',
                        errorMeta: ['app' => $model->name],
                    ),
                    command: $routedCommand,
                    cwd: $this->placement->runtimePath($model, $instance),
                    timeout: (int) $step->timeout_seconds,
                    environment: $this->environment($context),
                );
                $stepFinishedAt = now();
                $stepStatus = $result->successful() ? 'completed' : 'failed';
                $stdout .= $result->stdout;
                $stderr .= $result->stderr;

                DeploymentRunStep::query()->create([
                    'deployment_run_id' => $run->id,
                    'deploy_step_id' => $step->id,
                    'title' => $step->title,
                    'command' => $routedCommand,
                    'status' => $stepStatus,
                    'stdout' => $result->stdout,
                    'stderr' => $result->stderr,
                    'exit_code' => $result->exitCode,
                    'started_at' => $stepStartedAt,
                    'finished_at' => $stepFinishedAt,
                    'duration_ms' => $result->durationMs,
                ]);

                if (! $result->successful()) {
                    $progress?->stepFail($this->progressKey($step), "exit {$result->exitCode}");
                    $status = 'failed';
                    $exitCode = $result->exitCode;

                    break;
                }

                $progress?->stepDone($this->progressKey($step), $this->formatDurationMs($result->durationMs));
            }

            if ($status === 'completed') {
                $warmupPath = $this->placement->runtimePath($model, $instance);

                if ($this->usesLivePath($steps) && $model->runtimeKind() === AppRuntimeKind::Php) {
                    $progress?->stepStart('activate-runtime');

                    try {
                        $activation = $this->activeReleaseRuntimeActivator->activate($model, $instance, $context);
                        $warmupPath = $activation['live_path'];
                        $progress?->stepDone('activate-runtime', $activation['resolved_path']);
                    } catch (GatewayApiException $exception) {
                        $progress?->stepFail('activate-runtime', 'failed');

                        throw $exception;
                    }
                }

                $warmupResult = $this->runWarmupSteps($model, $instance, $context, $warmupPath, $progress);
                if ($warmupResult !== null) {
                    $stdout .= $warmupResult['stdout'];
                    $stderr .= $warmupResult['stderr'];
                }
            }
        } catch (Throwable $throwable) {
            $this->deploymentRunLifecycle->failed($run);

            throw $throwable;
        }

        $progress?->stepStart('record-result');
        $run = $status === 'completed'
            ? $this->deploymentRunLifecycle->completed($run)
            : $this->deploymentRunLifecycle->failed($run, $exitCode);
        $run->load('steps');
        $progress?->stepDone('record-result', $status);

        $payload = [
            'run' => $this->runEntity($run),
            'output' => [
                'stdout' => $stdout,
                'stderr' => $stderr,
            ],
            'meta' => [
                'action' => $status,
                'duration_ms' => $run->duration_ms,
            ],
        ];

        if ($status === 'failed') {
            $failedStep = $run->steps->firstWhere('status', 'failed');

            throw new GatewayApiException(
                message: "Deployment step '{$failedStep?->title}' failed for instance '{$this->targetName(
                    $instance,
                )}'.",
                errorCode: 'deploy.step_failed',
                errorMeta: [
                    'app' => $model->name,
                    'instance' => $instance->name,
                    'step' => $failedStep?->title,
                    'duration_ms' => $run->duration_ms,
                ],
                errorData: [
                    'run' => $payload['run'],
                    'output' => $payload['output'],
                ],
            );
        }

        return $payload;
    }

    public function runTargetNode(string $app): Node
    {
        $instance = $this->productionInstance($app);
        $this->deployApp($instance);
        $node = $this->placement->runtimeNode($instance->app, $instance);

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                message: "App '{$instance->app->name}' has no owning node.",
                errorCode: 'deploy.execution_failed',
                errorMeta: ['app' => $instance->app->name, 'instance' => $instance->name],
            );
        }

        return $node;
    }

    /**
     * Run built-in production warmup steps for PHP apps on the host using the
     * version-matched PHP toolchain. Returns captured output when warmups run,
     * or null when skipped.
     *
     * Warmup failures are caught and surfaced as deploy.warmup_failed so the
     * run status is updated to failed rather than left stuck at running.
     *
     * @param  array<string, mixed>  $context
     * @return array{stdout: string, stderr: string}|null
     */
    private function runWarmupSteps(
        App $app,
        Instance $instance,
        array $context,
        string $cwd,
        ?ProgressReporter $progress = null,
    ): ?array {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return null;
        }

        $node = $this->placement->runtimeNode($app, $instance);

        if ($node === null) {
            return null;
        }

        $warmupCommands = [
            'composer install --no-dev --optimize-autoloader --no-interaction',
            'php artisan optimize',
        ];

        $stdout = '';
        $stderr = '';

        foreach ($warmupCommands as $warmupCommand) {
            $routedCommand = $this->appCommandRouter->routeForPath(
                $app,
                $warmupCommand,
                $cwd,
                $this->environment($context),
                $instance,
            );

            $result = $this->runStep(
                node: $node,
                command: $routedCommand,
                cwd: $cwd,
                timeout: 300,
                environment: $this->environment($context),
            );

            $stdout .= $result->stdout;
            $stderr .= $result->stderr;

            if (! $result->successful()) {
                throw new GatewayApiException(
                    message: "Deployment warmup step '{$warmupCommand}' failed for project '{$app->name}'.",
                    errorCode: 'deploy.warmup_failed',
                    errorMeta: [
                        'app' => $app->name,
                        'warmup_command' => $warmupCommand,
                    ],
                    errorData: [
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                    ],
                );
            }
        }

        $this->runHttpWarmup($app, $instance, $context);

        return ['stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Send HTTP warmup requests to the app when warmup paths are configured.
     *
     * Requests are sent via docker exec into the FrankenPHP container that
     * still serves the app's HTTP traffic. The PHP toolchain migration does
     * not affect the serving container.
     *
     * @param  array<string, mixed>  $context
     */
    private function runHttpWarmup(App $app, Instance $instance, array $context): void
    {
        $warmupPaths = $instance->deploy_warmup_paths ?? [];

        if ($warmupPaths === []) {
            return;
        }

        $node = $this->placement->runtimeNode($app, $instance);

        if ($node === null) {
            return;
        }

        $containerName = $this->appRuntimeContainerRenderer->containerNameForInstance($app, $instance);

        foreach ($warmupPaths as $path) {
            $command = sprintf(
                'docker exec %s curl -sSf http://localhost%s',
                escapeshellarg($containerName),
                escapeshellarg($path),
            );

            $this->runStep(
                node: $node,
                command: $command,
                cwd: $this->placement->runtimePath($app, $instance),
                timeout: 30,
                environment: $this->environment($context),
            );
        }
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function runStep(
        Node $node,
        string $command,
        string $cwd,
        int $timeout,
        array $environment,
    ): RemoteShellResult {
        try {
            $result = $this->localExecutor->runInternal(
                node: $node,
                commandName: InternalCommand::DeployRunStep->value,
                transportOptions: [
                    'input' => json_encode([
                        'binary' => '/bin/sh',
                        'arguments' => ['-lc', $command],
                        'cwd' => $cwd,
                        'environment' => $environment,
                        'timeout' => $timeout,
                    ], JSON_THROW_ON_ERROR),
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'deploy.run-step',
                    ],
                    'strict' => false,
                    'timeout' => $timeout + 15,
                    'bind_input' => true,
                    'throw' => false,
                ],
            );
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            throw new GatewayApiException(
                message: "Orbit Agent is unreachable for deployment on node '{$node->name}'.",
                errorCode: 'node.agent_unreachable',
                errorMeta: [
                    'reason' => 'agent_push_unavailable',
                    'node' => $node->name,
                    'platform' => (string) $node->platform,
                    'error' => $exception->getMessage(),
                ],
            );
        }

        try {
            $data = RemoteShellSuccessData::fromJsonEnvelopeOrFail($result);
        } catch (RemoteShellProtocolException) {
            return new RemoteShellResult(
                exitCode: $result->exitCode === 0 ? 1 : $result->exitCode,
                stdout: '',
                stderr: $this->stepEnvelopeFailure($result),
                durationMs: $result->durationMs,
            );
        }

        if (
            ! is_int($data['exit_code'] ?? null)
            || ! is_string($data['stdout'] ?? null)
            || ! is_string($data['stderr'] ?? null)
            || ! is_int($data['duration_ms'] ?? null)
        ) {
            return new RemoteShellResult(
                exitCode: $result->exitCode === 0 ? 1 : $result->exitCode,
                stdout: '',
                stderr: $this->stepEnvelopeFailure($result),
                durationMs: $result->durationMs,
            );
        }

        return new RemoteShellResult(
            exitCode: $data['exit_code'],
            stdout: $data['stdout'],
            stderr: $data['stderr'],
            durationMs: $data['duration_ms'],
        );
    }

    /**
     * Describe why `internal:deploy:run-step` did not return a step result.
     *
     * The node answers a refused step with an error envelope on stdout and an
     * empty stderr, so the envelope message is the only account of the real
     * cause (a missing working directory, an invalid payload, a rejected
     * token). Keep the protocol message as the last resort only.
     */
    private function stepEnvelopeFailure(RemoteShellResult $result): string
    {
        if ($result->stderr !== '') {
            return $result->stderr;
        }

        $error = RemoteShellSuccessData::errorFromJsonEnvelope($result);
        $message = $error['message'] ?? null;
        $code = $error['code'] ?? null;

        if (! is_string($message) || trim($message) === '') {
            return 'Deploy run step response is invalid.';
        }

        return is_string($code) && trim($code) !== ''
            ? trim($message).' ('.trim($code).')'
            : trim($message);
    }

    /**
     * @param  iterable<array-key, object>  $steps
     * @return list<array{key: string, label: string, doneLabel?: string}>
     */
    private function progressSteps(App $app, iterable $steps): array
    {
        $progressSteps = [
            [
                'key' => 'resolve-instance',
                'label' => 'Resolve production instance',
                'doneLabel' => 'Resolved production instance',
            ],
            [
                'key' => 'create-run',
                'label' => 'Create deployment run',
                'doneLabel' => 'Created deployment run',
            ],
        ];

        foreach ($steps as $step) {
            if (! $step instanceof DeployStep) {
                continue;
            }

            $progressSteps[] = [
                'key' => $this->progressKey($step),
                'label' => $step->title,
                'doneLabel' => $step->title,
            ];
        }

        if ($app->runtimeKind() === AppRuntimeKind::Php && $this->usesLivePath($steps)) {
            $progressSteps[] = [
                'key' => 'activate-runtime',
                'label' => 'Activate release runtime',
                'doneLabel' => 'Activated release runtime',
            ];
        }

        $progressSteps[] = [
            'key' => 'record-result',
            'label' => 'Record deployment result',
            'doneLabel' => 'Recorded deployment result',
        ];

        return $progressSteps;
    }

    /**
     * @param  iterable<array-key, object>  $steps
     */
    private function usesLivePath(iterable $steps): bool
    {
        foreach ($steps as $step) {
            if ($step instanceof DeployStep && preg_match('/{{\s*live_path\s*}}/', $step->command) === 1) {
                return true;
            }
        }

        return false;
    }

    private function progressKey(DeployStep $step): string
    {
        return "deploy-step-{$step->id}";
    }

    private function formatDurationMs(int $durationMs): string
    {
        if ($durationMs < 1000) {
            return "{$durationMs}ms";
        }

        return number_format($durationMs / 1000, 1).'s';
    }

    /**
     * @return array{runs: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function history(string $app, int $limit): array
    {
        $instance = $this->productionInstance($app);
        $effectiveLimit = min($limit, 500);
        $total = $instance->deploymentRuns()->count();
        $runModels = $instance
            ->deploymentRuns()
            ->with('steps')
            ->orderByDesc('started_at')
            ->limit($effectiveLimit)
            ->get();
        $runs = $runModels
            ->map(fn (DeploymentRun $run): array => $this->runEntity($run))
            ->all();
        $runs = array_values($runs);

        return [
            'runs' => $runs,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'limit' => $effectiveLimit,
                    'limit_capped' => $limit > 500,
                ],
                'app' => $instance->app->name,
                'instance' => $instance->name,
            ],
        ];
    }

    /**
     * @return array{run: array<string, mixed>, steps: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function log(string $app, int $runId, ?int $stepId, int $lines): array
    {
        $instance = $this->productionInstance($app);
        $run = DeploymentRun::query()
            ->with('steps')
            ->where('instance_id', $instance->id)
            ->whereKey($runId)
            ->first();

        if (! $run instanceof DeploymentRun) {
            throw new GatewayApiException(
                message: "Deployment run {$runId} was not found for instance '{$this->targetName($instance)}'.",
                errorCode: 'deploy.run_not_found',
                errorMeta: [
                    'app' => $instance->app->name,
                    'instance' => $instance->name,
                    'run' => $runId,
                ],
            );
        }

        $steps = $run->steps;

        if ($stepId !== null) {
            $steps = $steps->where('id', $stepId)->values();

            if ($steps->isEmpty()) {
                throw new GatewayApiException(
                    message: "Deployment step {$stepId} was not found in run {$runId} for instance '{$this->targetName(
     $instance,
 )}'.",
                    errorCode: 'deploy.step_not_found',
                    errorMeta: [
                        'app' => $instance->app->name,
                        'instance' => $instance->name,
                        'run' => $runId,
                        'step' => $stepId,
                    ],
                );
            }
        }

        $truncated = false;
        $entities = [];

        foreach ($steps as $step) {
            if (! $step instanceof DeploymentRunStep) {
                continue;
            }

            $stdout = $this->tailLines((string) $step->stdout, $lines);
            $stderr = $this->tailLines((string) $step->stderr, $lines);
            $truncated = $truncated || $stdout !== (string) $step->stdout || $stderr !== (string) $step->stderr;
            $entities[] = $this->runStepLogEntity($step, $stdout, $stderr);
        }

        return [
            'run' => $this->runEntity($run),
            'steps' => $entities,
            'meta' => [
                'lines' => $lines,
                'truncated_by_filter' => $truncated,
            ],
        ];
    }

    public function productionInstance(string $selector): Instance
    {
        try {
            $selection = $this->appSelectorResolver->resolve($selector);
        } catch (AppSelectionResolutionFailed $exception) {
            throw new GatewayApiException(
                message: $exception->getMessage(),
                errorCode: $exception->errorCode,
                errorMeta: $exception->meta,
            );
        }

        if ($selection === null) {
            throw new GatewayApiException(
                message: "Instance '{$selector}' was not found.",
                errorCode: 'instance.not_found',
                errorMeta: ['instance' => $selector],
            );
        }

        try {
            $selection = $this->appSelectorResolver->requireInstance($selection);
        } catch (AppSelectionResolutionFailed $exception) {
            throw new GatewayApiException(
                message: $exception->getMessage(),
                errorCode: $exception->errorCode,
                errorMeta: $exception->meta,
            );
        }

        $instance = $selection->instance;

        if (! $instance instanceof Instance) {
            throw new GatewayApiException(
                message: "App '{$selection->app->name}' requires a concrete instance selector.",
                errorCode: 'validation_failed',
                errorMeta: [
                    'field' => 'instance',
                    'reason' => 'instance_required',
                    'app' => $selection->app->name,
                ],
            );
        }

        $instance->setRelation('app', $selection->app);

        // Production capability is instance-authoritative: the concrete instance's
        // own placement decides, never a stale App-level environment column.
        $environment = $this->placement->environmentForInstance($instance);

        if ($environment !== 'production') {
            throw new GatewayApiException(
                message: "App '{$selection->app->name}' is not production-capable.",
                errorCode: 'deploy.production_app_required',
                errorMeta: [
                    'app' => $selection->app->name,
                    'environment' => $environment,
                ],
            );
        }

        return $instance;
    }

    /** @return array<string, mixed> */
    public function stepEntity(DeployStep $step): array
    {
        $step->loadMissing('instance.app');

        return [
            'id' => $step->id,
            'app' => $step->instance->app->name,
            'instance' => $step->instance->name,
            'title' => $step->title,
            'command' => $step->command,
            'order' => $step->sort_order,
            'timeout_seconds' => $step->timeout_seconds,
            'retention' => $step->retention,
        ];
    }

    /** @return array<string, mixed> */
    public function runEntity(DeploymentRun $run): array
    {
        $run->loadMissing('instance.app', 'steps');

        return [
            'id' => $run->id,
            'app' => $run->instance->app->name,
            'instance' => $run->instance->name,
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'context' => $run->context ?? [],
            'steps' => $run
                ->steps
                ->map(fn (DeploymentRunStep $step): array => [
                    'id' => $step->id,
                    'title' => $step->title,
                    'status' => $step->status,
                    'exit_code' => $step->exit_code,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function runStepLogEntity(DeploymentRunStep $step, string $stdout, string $stderr): array
    {
        return [
            'id' => $step->id,
            'title' => $step->title,
            'status' => $step->status,
            'exit_code' => $step->exit_code,
            'started_at' => $step->started_at?->toJSON(),
            'finished_at' => $step->finished_at?->toJSON(),
            'output' => [
                'stdout' => $stdout,
                'stderr' => $stderr,
            ],
        ];
    }

    private function findStep(Instance $instance, string $selector): ?DeployStep
    {
        $query = DeployStep::query()->where('instance_id', $instance->id);

        if (ctype_digit($selector)) {
            return (clone $query)->whereKey((int) $selector)->first();
        }

        return $query->where('title', $selector)->first();
    }

    private function titleFromCommand(string $command): string
    {
        $title = trim($command);

        return strlen($title) > 60 ? substr($title, 0, 57).'...' : $title;
    }

    private function tailLines(string $value, int $lines): string
    {
        $parts = preg_split("/\r\n|\n|\r/", rtrim($value, "\r\n"));

        if (! is_array($parts) || $parts === ['']) {
            return $value;
        }

        if (count($parts) <= $lines) {
            return $value;
        }

        return implode("\n", array_slice($parts, -$lines))."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function runContext(
        App $app,
        Instance $instance,
        DeploymentRun $run,
        Carbon $startedAt,
    ): array {
        $node = $this->placement->runtimeNode($app, $instance);
        $appPath = rtrim($this->placement->runtimePath($app, $instance), '/');
        $domain = $this->placement->runtimeDomain($app, $instance);
        $release = $startedAt->copy()->utc()->format('Ymd_His').'_'.$run->id;
        $appUser = $this->appUser($app, $instance);

        return [
            'app_name' => $app->name,
            'instance' => $instance->name,
            'app_path' => $appPath,
            'app_user' => $appUser,
            'domain' => $domain,
            'repository' => $app->repository,
            'release' => $release,
            'release_name' => $release,
            'releases_path' => "{$appPath}/releases",
            'release_path' => "{$appPath}/releases/{$release}",
            'live_path' => "{$appPath}/live",
            'env_path' => "{$appPath}/.env",
            'storage_path' => "{$appPath}/storage",
            'database_path' => "{$appPath}/database/database.sqlite",
            'app' => [
                'name' => $app->name,
                'instance' => $instance->name,
                'path' => $appPath,
                'user' => $appUser,
                'domain' => $domain,
                'repository' => $app->repository,
            ],
            'node' => [
                'name' => $node?->name,
                'host' => $node?->host,
                'user' => $node?->user ?: 'orbit',
            ],
        ];
    }

    private function appUser(App $app, ?Instance $instance = null): string
    {
        return $this->appRuntimeUser->forApp($app, $instance);
    }

    /**
     * Resolve the logical app for a deploy target. Placement is read from the
     * concrete instance via WorkspacePlacement; no synthetic app clone is used.
     */
    private function deployApp(Instance $instance): App
    {
        $instance->loadMissing('app');
        $app = $instance->app;
        $config = $instance->driver_config;

        if (
            ! $config instanceof OrbitInstanceDriverConfigData
            || ! is_string($config->path)
            || trim($config->path) === ''
        ) {
            throw new GatewayApiException(
                message: "Instance '{$this->targetName($instance)}' does not support Orbit Agent deployment.",
                errorCode: 'deploy.instance_driver_unsupported',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'driver' => $instance->driver->value,
                ],
            );
        }

        if (! $this->placement->runtimeNode($app, $instance) instanceof Node) {
            throw new GatewayApiException(
                message: "Instance '{$this->targetName($instance)}' has no owning node.",
                errorCode: 'deploy.execution_failed',
                errorMeta: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                ],
            );
        }

        return $app;
    }

    private function targetName(Instance $instance): string
    {
        $instance->loadMissing('app');

        return "{$instance->app->name}.{$instance->name}";
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderCommand(string $command, array $context): string
    {
        return preg_replace_callback(
            '/{{\s*([A-Za-z0-9_.-]+)\s*}}/',
            function (array $matches) use ($context): string {
                $value = Arr::get($context, $matches[1]);

                if (is_scalar($value) || $value === null) {
                    return (string) $value;
                }

                return $matches[0];
            },
            $command,
        ) ?? $command;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    private function environment(array $context): array
    {
        $environment = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $environment['ORBIT_DEPLOY_'.Str::upper($key)] = (string) $value;
        }

        return $environment;
    }
}
