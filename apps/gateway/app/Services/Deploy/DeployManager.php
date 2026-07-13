<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Deploy\AddDeployStep;
use App\Actions\Deploy\RemoveDeployStep;
use App\Contracts\AppRuntimeUserResolver;
use App\Contracts\ProgressReporter;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Apps\AppCommandRouter;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\AppSelectorResolver;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class DeployManager
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
        private AppSelectorResolver $appSelectorResolver,
        private AppRuntimeContainerRenderer $appRuntimeContainerRenderer,
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
        private AppCommandRouter $appCommandRouter = new AppCommandRouter,
        private AddDeployStep $addDeployStep = new AddDeployStep,
        private RemoveDeployStep $removeDeployStep = new RemoveDeployStep,
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
            appInstanceId: $instance->id,
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
                'app_instance' => $instance->name,
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
                message: "Deployment step '{$selector}' was not found for app instance '{$this->targetName(
                    $instance,
                )}'.",
                errorCode: 'deploy.step_not_found',
                errorMeta: [
                    'app' => $instance->app->name,
                    'app_instance' => $instance->name,
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
    public function run(string $app, bool $detach = false, ?ProgressReporter $progress = null): array
    {
        $instance = $this->productionInstance($app);
        $model = $this->runtimeApp($instance);
        $steps = DeployStep::query()
            ->where('app_instance_id', $instance->id)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            throw new GatewayApiException(
                message: "Deployment pipeline is empty for app instance '{$this->targetName($instance)}'.",
                errorCode: 'deploy.pipeline_empty',
                errorMeta: ['app' => $model->name, 'app_instance' => $instance->name],
            );
        }

        $progress?->tree('Running Deployment', $this->progressSteps($steps, $detach));
        $progress?->stepStart('resolve-app');
        $progress?->stepDone('resolve-app', $this->targetName($instance));

        $startedAt = Carbon::now();
        $progress?->stepStart('create-run');
        $run = DeploymentRun::query()->create([
            'app_instance_id' => $instance->id,
            'status' => 'running',
            'exit_code' => null,
            'started_at' => $startedAt,
        ]);
        $context = $this->runContext($model, $instance, $run, $startedAt);
        $run->forceFill(['context' => $context])->save();
        $progress?->stepDone('create-run', "#{$run->id}");

        $instance->forceFill([
            'latest_deployment_status' => 'running',
            'latest_deployment_run_id' => $run->id,
        ])->save();

        if ($detach) {
            return [
                'run' => $this->runEntity($run),
                'meta' => [
                    'action' => 'started',
                    'detached' => true,
                ],
            ];
        }

        $stdout = '';
        $stderr = '';
        $status = 'completed';
        $exitCode = 0;

        foreach ($steps as $step) {
            $stepStartedAt = now();
            $command = $this->renderCommand($step->command, $context);
            $routedCommand = $this->appCommandRouter->route($model, $command, $this->environment($context));
            $progress?->stepStart($this->progressKey($step));
            $result = $this->runStep(
                $model->node ?? throw new GatewayApiException(
                    message: "App '{$model->name}' has no owning node.",
                    errorCode: 'deploy.execution_failed',
                    errorMeta: ['app' => $model->name],
                ),
                command: $routedCommand,
                cwd: $model->path,
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
            try {
                $warmupResult = $this->runWarmupSteps($model, $instance, $context, $progress);
                if ($warmupResult !== null) {
                    $stdout .= $warmupResult['stdout'];
                    $stderr .= $warmupResult['stderr'];
                }
            } catch (GatewayApiException $warmupException) {
                $status = 'failed';
                $exitCode = 1;
                $finishedAt = now();
                $run->forceFill([
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'finished_at' => $finishedAt,
                    'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
                ])->save();
                $instance->forceFill(['latest_deployment_status' => $status])->save();
                throw $warmupException;
            }
        }

        $finishedAt = now();
        $progress?->stepStart('record-result');
        $run->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ])->save();

        $instance->forceFill(['latest_deployment_status' => $status])->save();
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
                message: "Deployment step '{$failedStep?->title}' failed for app instance '{$this->targetName(
                    $instance,
                )}'.",
                errorCode: 'deploy.step_failed',
                errorMeta: [
                    'app' => $model->name,
                    'app_instance' => $instance->name,
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

    public function runTarget(string $app): App
    {
        return $this->runtimeApp($this->productionInstance($app));
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
        AppInstance $instance,
        array $context,
        ?ProgressReporter $progress = null,
    ): ?array {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return null;
        }

        $node = $app->node;

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
            $routedCommand = $this->appCommandRouter->route($app, $warmupCommand, $this->environment($context));

            $result = $this->runStep(
                node: $node,
                command: $routedCommand,
                cwd: $app->path,
                timeout: 300,
                environment: $this->environment($context),
            );

            $stdout .= $result->stdout;
            $stderr .= $result->stderr;

            if (! $result->successful()) {
                throw new GatewayApiException(
                    message: "Deployment warmup step '{$warmupCommand}' failed for app '{$app->name}'.",
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
    private function runHttpWarmup(App $app, AppInstance $instance, array $context): void
    {
        $warmupPaths = $instance->deploy_warmup_paths ?? [];

        if ($warmupPaths === []) {
            return;
        }

        $node = $app->node;

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
                cwd: $app->path,
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
                    'error' => $exception->getMessage(),
                ],
            );
        }

        $data = RemoteShellSuccessData::fromJsonEnvelope($result);

        if (
            ! is_int($data['exit_code'] ?? null)
            || ! is_string($data['stdout'] ?? null)
            || ! is_string($data['stderr'] ?? null)
            || ! is_int($data['duration_ms'] ?? null)
        ) {
            return new RemoteShellResult(
                exitCode: $result->exitCode === 0 ? 1 : $result->exitCode,
                stdout: '',
                stderr: $result->stderr !== '' ? $result->stderr : 'Deploy run step response is invalid.',
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
     * @param  Collection<int, DeployStep>  $steps
     * @return list<array{key: string, label: string, doneLabel?: string}>
     */
    private function progressSteps(Collection $steps, bool $detach): array
    {
        $progressSteps = [
            [
                'key' => 'resolve-app',
                'label' => 'Resolve production app instance',
                'doneLabel' => 'Resolved production app instance',
            ],
            [
                'key' => 'create-run',
                'label' => 'Create deployment run',
                'doneLabel' => 'Created deployment run',
            ],
        ];

        if ($detach) {
            return $progressSteps;
        }

        foreach ($steps as $step) {
            $progressSteps[] = [
                'key' => $this->progressKey($step),
                'label' => $step->title,
                'doneLabel' => $step->title,
            ];
        }

        $progressSteps[] = [
            'key' => 'record-result',
            'label' => 'Record deployment result',
            'doneLabel' => 'Recorded deployment result',
        ];

        return $progressSteps;
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
                'app_instance' => $instance->name,
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
            ->where('app_instance_id', $instance->id)
            ->whereKey($runId)
            ->first();

        if (! $run instanceof DeploymentRun) {
            throw new GatewayApiException(
                message: "Deployment run {$runId} was not found for app instance '{$this->targetName($instance)}'.",
                errorCode: 'deploy.run_not_found',
                errorMeta: [
                    'app' => $instance->app->name,
                    'app_instance' => $instance->name,
                    'run' => $runId,
                ],
            );
        }

        $steps = $run->steps;

        if ($stepId !== null) {
            $steps = $steps->where('id', $stepId)->values();

            if ($steps->isEmpty()) {
                throw new GatewayApiException(
                    message: "Deployment step {$stepId} was not found in run {$runId} for app instance '{$this->targetName(
     $instance,
 )}'.",
                    errorCode: 'deploy.step_not_found',
                    errorMeta: [
                        'app' => $instance->app->name,
                        'app_instance' => $instance->name,
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

    public function productionInstance(string $selector): AppInstance
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
                message: "App '{$selector}' was not found.",
                errorCode: 'app.not_found',
                errorMeta: ['app' => $selector],
            );
        }

        if ($selection->app->environment !== 'production') {
            throw new GatewayApiException(
                message: "App '{$selection->app->name}' is not a production app.",
                errorCode: 'deploy.production_app_required',
                errorMeta: [
                    'app' => $selection->app->name,
                    'environment' => $selection->app->environment,
                ],
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

        if (! $instance instanceof AppInstance) {
            throw new GatewayApiException(
                message: "App '{$selection->app->name}' requires a concrete app instance selector.",
                errorCode: 'validation_failed',
                errorMeta: [
                    'field' => 'app',
                    'reason' => 'app_instance_required',
                    'app' => $selection->app->name,
                ],
            );
        }

        $instance->setRelation('app', $selection->app);

        return $instance;
    }

    /** @return array<string, mixed> */
    public function stepEntity(DeployStep $step): array
    {
        $step->loadMissing('appInstance.app');

        return [
            'id' => $step->id,
            'app' => $step->appInstance->app->name,
            'app_instance' => $step->appInstance->name,
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
        $run->loadMissing('appInstance.app', 'steps');

        return [
            'id' => $run->id,
            'app' => $run->appInstance->app->name,
            'app_instance' => $run->appInstance->name,
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

    private function findStep(AppInstance $instance, string $selector): ?DeployStep
    {
        $query = DeployStep::query()->where('app_instance_id', $instance->id);

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
        AppInstance $instance,
        DeploymentRun $run,
        Carbon $startedAt,
    ): array {
        $app->loadMissing('node');

        $appPath = rtrim($app->path, '/');
        $release = $startedAt->copy()->utc()->format('Ymd_His').'_'.$run->id;
        $appUser = $this->appUser($app);

        return [
            'app_name' => $app->name,
            'app_instance' => $instance->name,
            'app_path' => $appPath,
            'app_user' => $appUser,
            'domain' => $app->domain,
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
                'domain' => $app->domain,
                'repository' => $app->repository,
            ],
            'node' => [
                'name' => $app->node?->name,
                'host' => $app->node?->host,
                'user' => $app->node?->user ?: 'orbit',
            ],
        ];
    }

    private function appUser(App $app): string
    {
        return $this->appRuntimeUser->forApp($app);
    }

    private function runtimeApp(AppInstance $instance): App
    {
        $instance->loadMissing('app');
        $app = $instance->app;
        $config = $instance->driver_config;

        if (
            ! $config instanceof OrbitAppInstanceDriverConfigData
            || ! is_string($config->path)
            || trim($config->path) === ''
        ) {
            throw new GatewayApiException(
                message: "App instance '{$this->targetName($instance)}' does not support Orbit Agent deployment.",
                errorCode: 'deploy.instance_driver_unsupported',
                errorMeta: [
                    'app' => $app->name,
                    'app_instance' => $instance->name,
                    'driver' => $instance->driver->value,
                ],
            );
        }

        $runtimeApp = $this->appRuntimeContainerRenderer->runtimeAppForInstance($app, $instance);
        $runtimeApp->loadMissing('node');

        if (! $runtimeApp->node instanceof Node) {
            throw new GatewayApiException(
                message: "App instance '{$this->targetName($instance)}' has no owning node.",
                errorCode: 'deploy.execution_failed',
                errorMeta: [
                    'app' => $app->name,
                    'app_instance' => $instance->name,
                ],
            );
        }

        return $runtimeApp;
    }

    private function targetName(AppInstance $instance): string
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
