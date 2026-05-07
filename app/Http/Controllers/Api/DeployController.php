<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Gateway\GatewayApiException;
use App\Models\DeployStep;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class DeployController
{
    public function __construct(private DeployManager $deploy) {}

    public function storeStep(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => 'app'], 403);
        }

        $app = $this->stringInput($request, 'app');
        $command = $this->stringInput($request, 'command');

        if ($app === null || $command === null) {
            return $this->error('validation_failed', 'App and command are required.', ['field' => $app === null ? 'app' : 'command'], 400);
        }

        $timeout = $this->positiveIntInput($request, 'timeout', DeployStep::DEFAULT_TIMEOUT_SECONDS);
        $order = $this->optionalPositiveIntInput($request, 'order');
        $retention = $this->optionalPositiveIntInput($request, 'retention');

        foreach (['timeout' => $timeout, 'order' => $order, 'retention' => $retention] as $field => $value) {
            if ($value === false) {
                return $this->error('validation_failed', "Invalid value for {$field}: must be a positive integer.", ['field' => $field], 400);
            }
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

        try {
            $result = $this->deploy->addStep($app, $command, $this->stringInput($request, 'title'), $order, $timeout, $retention);

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    public function listSteps(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

        try {
            $result = $this->deploy->listSteps($app);

            return $this->success(['steps' => $result['steps']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    public function removeStep(string $step, Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => 'app'], 403);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('destructive_consent_required', 'Use --force to remove this deployment step.', ['field' => 'force'], 400);
        }

        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

        try {
            $result = $this->deploy->removeStep($app, $step);

            return $this->success(['step' => $result['step']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    public function run(Request $request): JsonResponse
    {
        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => 'app'], 403);
        }

        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

        try {
            $result = $this->deploy->run($app, $request->boolean('detach'));
            $data = ['run' => $result['run']];

            if (isset($result['output'])) {
                $data['output'] = $result['output'];
            }

            return $this->success($data, $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null) {
            return $this->error('validation_failed', 'App is required.', ['field' => 'app'], 400);
        }

        $limit = $this->positiveIntInput($request, 'limit', 50);

        if ($limit === false) {
            return $this->error('validation_failed', 'Invalid value for --limit: must be a positive integer.', ['field' => 'limit'], 400);
        }

        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
        }

        try {
            $result = $this->deploy->history($app, $limit);

            return $this->success(['runs' => $result['runs']], $result['meta']);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }
    }

    public function log(string $run, Request $request): JsonResponse
    {
        $app = $this->stringInput($request, 'app');

        if ($app === null || ! ctype_digit($run) || (int) $run < 1) {
            return $this->error('validation_failed', 'App and positive run id are required.', ['field' => $app === null ? 'app' : 'run'], 400);
        }

        $step = $this->optionalPositiveIntInput($request, 'step');
        $lines = $this->positiveIntInput($request, 'lines', 500);

        foreach (['step' => $step, 'lines' => $lines] as $field => $value) {
            if ($value === false) {
                return $this->error('validation_failed', "Invalid value for {$field}: must be a positive integer.", ['field' => $field], 400);
            }
        }

        $caller = $this->caller($request);

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        $authorized = $this->authorize($caller, $app);

        if ($authorized instanceof JsonResponse) {
            return $authorized;
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

    private function caller(Request $request): ?Node
    {
        /** @var mixed $caller */
        $caller = $request->user();

        return $caller instanceof Node ? $caller : null;
    }

    private function authorize(Node $caller, string $app): ?JsonResponse
    {
        try {
            $model = $this->deploy->productionApp($app);
        } catch (GatewayApiException $exception) {
            return $this->exception($exception);
        }

        if (! $this->deploy->canAccess($caller, $model)) {
            return $this->error('authorization_failed', "You are not authorized to access deployment data for '{$model->name}'.", ['app' => $model->name], 403);
        }

        return null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->query($key, $request->input($key));

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
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
            'authorization_failed', 'caller_role_not_allowed' => 403,
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
    private function success(array $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ]);
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
