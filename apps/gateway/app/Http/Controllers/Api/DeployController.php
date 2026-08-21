<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use App\Services\Deploy\DeployOperationRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final class DeployController implements Loggable
{
    private string $activityAction = 'listSteps';

    private ?Model $activitySubject = null;

    /** @var array<string, mixed> */
    private array $activityProperties = [];

    public function __construct(
        private readonly DeployManager $deploy,
        private readonly DeployOperationRunner $deployOperations,
    ) {}

    #[RequiresPermission('deploy:step', servingNode: ServingNode::InstanceOwning)]
    public function storeStep(Request $request): JsonResponse
    {
        $this->beginActivity('storeStep', ['instance' => $this->stringInput($request, 'instance')]);
        $instanceSelector = $this->stringInput($request, 'instance');
        $command = $this->stringInput($request, 'command');

        if ($instanceSelector === null || $command === null) {
            return $this->error(
                'validation_failed',
                'Instance and command are required.',
                ['field' => $instanceSelector === null ? 'instance' : 'command'],
                400,
            );
        }

        $timeout = $this->positiveIntInput($request, 'timeout', DeployStep::DEFAULT_TIMEOUT_SECONDS);
        $order = $this->optionalPositiveIntInput($request, 'order');
        $retention = $this->optionalPositiveIntInput($request, 'retention');

        foreach (['timeout' => $timeout, 'order' => $order, 'retention' => $retention] as $field => $value) {
            if ($value === false) {
                return $this->error(
                    'validation_failed',
                    "Invalid value for {$field}: must be a positive integer.",
                    ['field' => $field],
                    400,
                );
            }
        }

        try {
            $result = $this->deploy->addStep(
                $instanceSelector,
                $command,
                $this->stringInput($request, 'title'),
                $order,
                $timeout,
                $retention,
            );

            $step = $result['step'];
            $subject = DeployStep::query()->find($step['id']);
            $this->activitySubject = $subject instanceof DeployStep ? $subject : null;
            $this->activityProperties = $this->safeProperties($step, ['app', 'instance', 'id', 'title', 'order']);
            $this->activityProperties['step_id'] = $this->activityProperties['id'] ?? null;
            unset($this->activityProperties['id']);

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:read', servingNode: ServingNode::InstanceOwning)]
    public function listSteps(Request $request): JsonResponse
    {
        $this->beginActivity('listSteps', ['instance' => $this->stringInput($request, 'instance')]);
        $instanceSelector = $this->stringInput($request, 'instance');

        if ($instanceSelector === null) {
            return $this->error('validation_failed', 'Instance is required.', ['field' => 'instance'], 400);
        }

        try {
            $result = $this->deploy->listSteps($instanceSelector);

            $this->activityProperties = [
                ...$this->safeProperties($result['meta'], ['app', 'instance', 'count']),
            ];

            return $this->success(['steps' => $result['steps']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:step', servingNode: ServingNode::InstanceOwning)]
    public function removeStep(string $step, Request $request): JsonResponse
    {
        $this->beginActivity('removeStep', [
            'instance' => $this->stringInput($request, 'instance'),
            'step_id' => ctype_digit($step) ? (int) $step : null,
        ]);
        $this->resolveStepSubject($this->stringInput($request, 'instance'), $step);

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error(
                'validation_failed',
                'Use --force to remove this deployment step.',
                ['field' => 'force', 'reason' => 'destructive_consent_required'],
                400,
            );
        }

        $instanceSelector = $this->stringInput($request, 'instance');

        if ($instanceSelector === null) {
            return $this->error('validation_failed', 'Instance is required.', ['field' => 'instance'], 400);
        }

        try {
            $result = $this->deploy->removeStep($instanceSelector, $step);

            $this->activityProperties = $this->safeProperties(
                $result['step'],
                ['app', 'instance', 'id', 'title'],
            );
            $this->activityProperties['step_id'] = $this->activityProperties['id'] ?? null;
            unset($this->activityProperties['id']);

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:run', servingNode: ServingNode::InstanceOwning)]
    public function run(Request $request): JsonResponse
    {
        $this->beginActivity('run', ['instance' => $this->stringInput($request, 'instance')]);
        $instanceSelector = $this->stringInput($request, 'instance');

        if ($instanceSelector === null) {
            return $this->error('validation_failed', 'Instance is required.', ['field' => 'instance'], 400);
        }

        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $instance = $this->deploy->productionInstance($instanceSelector);
            $this->activityProperties = [
                'app' => $instance->app->name,
                'instance' => $instance->name,
                'status' => 'queued',
            ];
            $operation = $this->deployOperations->start($instanceSelector, $caller);

            app()->terminating(function () use ($operation, $instanceSelector): void {
                try {
                    $this->deployOperations->execute($operation['uuid'], $instanceSelector);
                } catch (Throwable $throwable) {
                    report($throwable);
                }
            });

            return $this->success(
                ['operation' => $operation],
                ['detached' => $request->boolean('detach')],
                202,
            );
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:read', servingNode: ServingNode::InstanceOwning)]
    public function history(Request $request): JsonResponse
    {
        $this->beginActivity('history', [
            'instance' => $this->stringInput($request, 'instance'),
            'limit' => $request->query('limit', $request->input('limit', 50)),
        ]);
        $instanceSelector = $this->stringInput($request, 'instance');

        if ($instanceSelector === null) {
            return $this->error('validation_failed', 'Instance is required.', ['field' => 'instance'], 400);
        }

        $limit = $this->positiveIntInput($request, 'limit', 50);

        if ($limit === false) {
            return $this->error(
                'validation_failed',
                'Invalid value for --limit: must be a positive integer.',
                ['field' => 'limit'],
                400,
            );
        }

        try {
            $result = $this->deploy->history($instanceSelector, $limit);

            $this->activityProperties = [
                ...$this->safeProperties($result['meta'], ['app', 'instance']),
                'count' => count($result['runs']),
                'limit' => $result['meta']['pagination']['limit'] ?? $limit,
            ];

            return $this->success(['runs' => $result['runs']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:read', servingNode: ServingNode::InstanceOwning)]
    public function log(string $run, Request $request): JsonResponse
    {
        $this->beginActivity('log', [
            'instance' => $this->stringInput($request, 'instance'),
            'run_id' => ctype_digit($run) ? (int) $run : null,
            'step_id' => $this->optionalPositiveIntInput($request, 'step'),
        ]);
        $instanceSelector = $this->stringInput($request, 'instance');

        if ($instanceSelector === null || ! ctype_digit($run) || (int) $run < 1) {
            return $this->error(
                'validation_failed',
                'Instance and positive run id are required.',
                ['field' => $instanceSelector === null ? 'instance' : 'run'],
                400,
            );
        }

        $step = $this->optionalPositiveIntInput($request, 'step');
        $lines = $this->positiveIntInput($request, 'lines', 500);

        foreach (['step' => $step, 'lines' => $lines] as $field => $value) {
            if ($value === false) {
                return $this->error(
                    'validation_failed',
                    "Invalid value for {$field}: must be a positive integer.",
                    ['field' => $field],
                    400,
                );
            }
        }

        try {
            $result = $this->deploy->log($instanceSelector, (int) $run, $step, $lines);

            $runEntity = $result['run'];
            $subject = DeploymentRun::query()->find($runEntity['id']);
            $this->activitySubject = $subject instanceof DeploymentRun ? $subject : null;
            $this->activityProperties = [
                ...$this->safeProperties($runEntity, ['app', 'instance']),
                'run_id' => $runEntity['id'],
                ...($step !== null ? ['step_id' => $step] : []),
            ];

            return $this->success([
                'run' => $result['run'],
                'steps' => $result['steps'],
            ], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    /** @param array<string, mixed> $properties */
    private function beginActivity(string $action, array $properties): void
    {
        $this->activityAction = $action;
        $this->activitySubject = null;
        $this->activityProperties = array_filter(
            $properties,
            static fn (mixed $value): bool => $value !== null && $value !== false,
        );
    }

    private function resolveStepSubject(?string $instanceSelector, string $stepSelector): void
    {
        if ($instanceSelector === null) {
            return;
        }

        try {
            $instance = $this->deploy->productionInstance($instanceSelector);
            $query = DeployStep::query()->where('instance_id', $instance->id);
            $subject = ctype_digit($stepSelector)
                ? $query->whereKey((int) $stepSelector)->first()
                : $query->where('title', $stepSelector)->first();

            if ($subject instanceof DeployStep) {
                $this->activitySubject = $subject;
                $this->activityProperties = [
                    'app' => $instance->app->name,
                    'instance' => $instance->name,
                    'step_id' => $subject->id,
                    'title' => $subject->title,
                ];
            }
        } catch (GatewayApiException) {
            return;
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function safeProperties(array $source, array $keys): array
    {
        return array_filter(
            array_intersect_key($source, array_flip($keys)),
            static fn (mixed $value): bool => $value !== null,
        );
    }

    public function effect(): ActivityLogType
    {
        return match ($this->activityAction) {
            'listSteps', 'history', 'log' => ActivityLogType::Read,
            'removeStep' => ActivityLogType::Destructive,
            default => ActivityLogType::Write,
        };
    }

    public function type(): string
    {
        return match ($this->activityAction) {
            'storeStep' => 'api:POST /deploy/steps',
            'removeStep' => 'api:DELETE /deploy/steps/{step}',
            'run' => 'api:POST /deploy/run',
            'history' => 'api:GET /deploy/history',
            'log' => 'api:GET /deploy/log/{run}',
            default => 'api:GET /deploy/steps',
        };
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function properties(): array
    {
        return $this->activityProperties;
    }

    public function description(): ?string
    {
        return null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->query($key, $request->input($key));

        return is_scalar($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function positiveIntInput(Request $request, string $key, int $default): int|false
    {
        $value = $request->query($key, $request->input($key, $default));

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }

    private function optionalPositiveIntInput(Request $request, string $key): int|false|null
    {
        $value = $request->query($key);

        if ($value === null) {
            $value = $request->input($key);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }

        return false;
    }

    private function exception(GatewayApiException $exception): JsonResponse
    {
        $status = match ($exception->errorCode()) {
            'instance.not_found', 'deploy.step_not_found', 'deploy.run_not_found' => 404,
            'authorization_failed' => 403,
            default => 400,
        };

        return $this->error(
            $exception->errorCode() ?? 'validation_failed',
            $exception->getMessage(),
            $exception->errorMeta(),
            $status,
            $exception->errorData(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function success(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, int $status, array $data = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'meta' => empty($meta) ? (object) [] : $meta,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        return response()->json(['error' => $error], $status);
    }
}
