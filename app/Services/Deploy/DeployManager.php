<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\ProgressReporter;
use App\Contracts\RemoteShell;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeploymentRunStep;
use App\Models\DeployStep;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DeployManager
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function addStep(string $app, string $command, ?string $title, ?int $order, int $timeout, ?int $retention): array
    {
        $model = $this->productionApp($app);

        $step = DeployStep::createOrdered(
            appId: $model->id,
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
        $model = $this->productionApp($app);
        $steps = DeployStep::query()
            ->where('app_id', $model->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (DeployStep $step): array => $this->stepEntity($step))
            ->values()
            ->all();

        return [
            'steps' => $steps,
            'meta' => [
                'app' => $model->name,
                'count' => count($steps),
            ],
        ];
    }

    /**
     * @return array{step: array<string, mixed>, meta: array<string, mixed>}
     */
    public function removeStep(string $app, string $selector): array
    {
        $model = $this->productionApp($app);
        $step = $this->findStep($model, $selector);

        if (! $step instanceof DeployStep) {
            throw new GatewayApiException(
                message: "Deployment step '{$selector}' was not found for app '{$model->name}'.",
                errorCode: 'deploy.step_not_found',
                errorMeta: ['app' => $model->name, 'step' => $selector],
            );
        }

        $entity = $this->stepEntity($step);
        $step->deleteAndCompact();

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
        $model = $this->productionApp($app)->loadMissing('node');
        $steps = DeployStep::query()
            ->where('app_id', $model->id)
            ->orderBy('sort_order')
            ->get();

        if ($steps->isEmpty()) {
            throw new GatewayApiException(
                message: "Deployment pipeline is empty for app '{$model->name}'.",
                errorCode: 'deploy.pipeline_empty',
                errorMeta: ['app' => $model->name],
            );
        }

        $progress?->tree('Running Deployment', $this->progressSteps($steps, $detach));
        $progress?->stepStart('resolve-app');
        $progress?->stepDone('resolve-app', $model->name);

        $startedAt = now();
        $progress?->stepStart('create-run');
        $run = DeploymentRun::query()->create([
            'app_id' => $model->id,
            'status' => 'running',
            'exit_code' => null,
            'started_at' => $startedAt,
        ]);
        $context = $this->runContext($model, $run, $startedAt);
        $run->forceFill(['context' => $context])->save();
        $progress?->stepDone('create-run', "#{$run->id}");

        $model->forceFill([
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
            $progress?->stepStart($this->progressKey($step));
            $result = $this->remoteShell->run($model->node ?? throw new GatewayApiException(
                message: "App '{$model->name}' has no owning node.",
                errorCode: 'deploy.execution_failed',
                errorMeta: ['app' => $model->name],
            ), $command, [
                'cwd' => $model->path,
                'timeout' => $step->timeout_seconds,
                'strict' => true,
                'env' => $this->environment($context),
            ]);
            $stepFinishedAt = now();
            $stepStatus = $result->successful() ? 'completed' : 'failed';
            $stdout .= $result->stdout;
            $stderr .= $result->stderr;

            DeploymentRunStep::query()->create([
                'deployment_run_id' => $run->id,
                'deploy_step_id' => $step->id,
                'title' => $step->title,
                'command' => $command,
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

        $finishedAt = now();
        $progress?->stepStart('record-result');
        $run->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $startedAt->diffInMilliseconds($finishedAt),
        ])->save();

        $model->forceFill(['latest_deployment_status' => $status])->save();
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
                message: "Deployment step '{$failedStep?->title}' failed for app '{$model->name}'.",
                errorCode: 'deploy.step_failed',
                errorMeta: [
                    'app' => $model->name,
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

    /**
     * @param  Collection<int, DeployStep>  $steps
     * @return list<array{key: string, label: string, doneLabel?: string}>
     */
    private function progressSteps(Collection $steps, bool $detach): array
    {
        $progressSteps = [
            [
                'key' => 'resolve-app',
                'label' => 'Resolve production app',
                'doneLabel' => 'Resolved production app',
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
        $model = $this->productionApp($app);
        $effectiveLimit = min($limit, 500);
        $total = DeploymentRun::query()->where('app_id', $model->id)->count();
        $runs = DeploymentRun::query()
            ->with('steps')
            ->where('app_id', $model->id)
            ->orderByDesc('started_at')
            ->limit($effectiveLimit)
            ->get()
            ->map(fn (DeploymentRun $run): array => $this->runEntity($run))
            ->values()
            ->all();

        return [
            'runs' => $runs,
            'meta' => [
                'pagination' => [
                    'total' => $total,
                    'limit' => $effectiveLimit,
                    'limit_capped' => $limit > 500,
                ],
            ],
        ];
    }

    /**
     * @return array{run: array<string, mixed>, steps: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function log(string $app, int $runId, ?int $stepId, int $lines): array
    {
        $model = $this->productionApp($app);
        $run = DeploymentRun::query()
            ->with('steps')
            ->where('app_id', $model->id)
            ->whereKey($runId)
            ->first();

        if (! $run instanceof DeploymentRun) {
            throw new GatewayApiException(
                message: "Deployment run {$runId} was not found for app '{$model->name}'.",
                errorCode: 'deploy.run_not_found',
                errorMeta: ['app' => $model->name, 'run' => $runId],
            );
        }

        $steps = $run->steps;

        if ($stepId !== null) {
            $steps = $steps->where('id', $stepId)->values();

            if ($steps->isEmpty()) {
                throw new GatewayApiException(
                    message: "Deployment step {$stepId} was not found in run {$runId} for app '{$model->name}'.",
                    errorCode: 'deploy.step_not_found',
                    errorMeta: ['app' => $model->name, 'run' => $runId, 'step' => $stepId],
                );
            }
        }

        $truncated = false;
        $entities = $steps
            ->map(function (DeploymentRunStep $step) use ($lines, &$truncated): array {
                $stdout = $this->tailLines((string) $step->stdout, $lines);
                $stderr = $this->tailLines((string) $step->stderr, $lines);
                $truncated = $truncated
                    || $stdout !== (string) $step->stdout
                    || $stderr !== (string) $step->stderr;

                return $this->runStepLogEntity($step, $stdout, $stderr);
            })
            ->values()
            ->all();

        return [
            'run' => $this->runEntity($run),
            'steps' => $entities,
            'meta' => [
                'lines' => $lines,
                'truncated_by_filter' => $truncated,
            ],
        ];
    }

    public function productionApp(string $selector): App
    {
        $app = App::query()
            ->with('node')
            ->where('name', $selector)
            ->orWhere('domain', $selector)
            ->first();

        if (! $app instanceof App) {
            throw new GatewayApiException(
                message: "App '{$selector}' was not found.",
                errorCode: 'app.not_found',
                errorMeta: ['app' => $selector],
            );
        }

        if ($app->environment !== 'production') {
            throw new GatewayApiException(
                message: "App '{$app->name}' is not a production app.",
                errorCode: 'deploy.production_app_required',
                errorMeta: [
                    'app' => $app->name,
                    'environment' => $app->environment,
                ],
            );
        }

        return $app;
    }

    public function canAccess(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $app->node_id)
            ->exists();
    }

    public function stepEntity(DeployStep $step): array
    {
        $step->loadMissing('app');

        return [
            'id' => $step->id,
            'app' => $step->app?->name,
            'title' => $step->title,
            'command' => $step->command,
            'order' => $step->sort_order,
            'timeout_seconds' => $step->timeout_seconds,
            'retention' => $step->retention,
        ];
    }

    public function runEntity(DeploymentRun $run): array
    {
        $run->loadMissing('app', 'steps');

        return [
            'id' => $run->id,
            'app' => $run->app?->name,
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'context' => $run->context ?? [],
            'steps' => $run->steps
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

    private function findStep(App $app, string $selector): ?DeployStep
    {
        $query = DeployStep::query()->where('app_id', $app->id);

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
    private function runContext(App $app, DeploymentRun $run, Carbon $startedAt): array
    {
        $app->loadMissing('node');

        $appPath = rtrim($app->path, '/');
        $release = $startedAt->copy()->utc()->format('Ymd_His').'_'.$run->id;
        $appUser = $this->appUser($app);

        return [
            'app_name' => $app->name,
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
        if (preg_match('#^/home/([^/]+)/#', $app->path, $matches) === 1) {
            return $matches[1];
        }

        return $app->node?->user ?: 'orbit';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderCommand(string $command, array $context): string
    {
        return preg_replace_callback('/{{\s*([A-Za-z0-9_.-]+)\s*}}/', function (array $matches) use ($context): string {
            $value = Arr::get($context, $matches[1]);

            if (is_scalar($value) || $value === null) {
                return (string) $value;
            }

            return $matches[0];
        }, $command) ?? $command;
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

            $environment['ORBIT_DEPLOY_'.Str::upper((string) $key)] = (string) $value;
        }

        return $environment;
    }
}
