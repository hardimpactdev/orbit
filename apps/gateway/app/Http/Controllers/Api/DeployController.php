<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use App\Services\Deploy\DeployOperationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class DeployController
{
    public function __construct(
        private DeployManager $deploy,
        private DeployOperationRunner $deployOperations,
    ) {}

    #[RequiresPermission('deploy:step', servingNode: ServingNode::AppInstanceOwning)]
    public function storeStep(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');
        $command = $this->stringInput($request, 'command');

        if ($app === null || $command === null) {
            return $this->error(
                'validation_failed',
                'App and command are required.',
                ['field' => $app === null ? 'app' : 'command'],
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
                $app,
                $command,
                $this->stringInput($request, 'title'),
                $order,
                $timeout,
                $retention,
            );

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:read', servingNode: ServingNode::AppInstanceOwning)]
    public function listSteps(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        try {
            $result = $this->deploy->listSteps($app);

            return $this->success(['steps' => $result['steps']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:step', servingNode: ServingNode::AppInstanceOwning)]
    public function removeStep(string $step, Request $request): JsonResponse
    {
        if ($request->boolean('destructive_consent') !== true) {
            return $this->error(
                'destructive_consent_required',
                'Use --force to remove this deployment step.',
                ['field' => 'force'],
                400,
            );
        }

        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        try {
            $result = $this->deploy->removeStep($app, $step);

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:run', servingNode: ServingNode::AppInstanceOwning)]
    public function run(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $operation = $this->deployOperations->start($app, $caller);

            app()->terminating(function () use ($operation, $app): void {
                try {
                    $this->deployOperations->execute($operation['uuid'], $app);
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

    #[RequiresPermission('deploy:read', servingNode: ServingNode::AppInstanceOwning)]
    public function history(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
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
            $result = $this->deploy->history($app, $limit);

            return $this->success(['runs' => $result['runs']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    #[RequiresPermission('deploy:read', servingNode: ServingNode::AppInstanceOwning)]
    public function log(string $run, Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null || ! ctype_digit($run) || (int) $run < 1) {
            return $this->error(
                'validation_failed',
                'App and positive run id are required.',
                ['field' => $app === null ? 'app' : 'run'],
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
            $result = $this->deploy->log($app, (int) $run, $step, $lines);

            return $this->success([
                'run' => $result['run'],
                'steps' => $result['steps'],
            ], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
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
        $value = $request->query($key, $request->input($key));

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
            'app.not_found', 'deploy.step_not_found', 'deploy.run_not_found' => 404,
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
