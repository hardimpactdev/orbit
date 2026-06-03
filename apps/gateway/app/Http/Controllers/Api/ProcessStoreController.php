<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\AddProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('process:add', servingNode: ServingNode::AppOwning)]
final class ProcessStoreController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(Request $request, AddProcess $addProcess): JsonResponse
    {
        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $app = App::query()->with(['node', 'workspaces'])->where('name', $input['app'])->first();

        if (! $app instanceof App) {
            return $this->error('validation_failed', "App '{$input['app']}' not found.", ['field' => 'app', 'value' => $input['app']], 422);
        }

        try {
            $result = $addProcess->handle(
                app: $app,
                name: $input['name'],
                command: $input['command'],
                restartPolicy: $input['restart_policy'],
                crashNotification: $input['crash_notification'],
                start: $input['start'],
                runtime: $input['runtime'],
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorCode() === 'process.name_collision' ? 409 : 422);
        }

        $this->activitySubject = $app;

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => [
                    'warnings' => $result['warnings'],
                ],
            ],
        ]);
    }

    /**
     * @return array{app: string, name: string, command: string, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, runtime: ?ProcessRuntime, start: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $app = $this->optionalString($request, 'app');
        $name = $this->optionalString($request, 'name');
        $command = $this->optionalString($request, 'command');
        $restartPolicyInput = $this->optionalString($request, 'restart_policy') ?? ProcessRestartPolicy::Never->value;
        $crashNotificationInput = $this->optionalString($request, 'crash_notification') ?? ProcessCrashNotification::None->value;
        $runtimeInput = $this->optionalString($request, 'runtime');

        if ($app === null) {
            return $this->error('validation_failed', 'An app context is required.', ['field' => 'app'], 422);
        }

        if ($name === null) {
            return $this->error('validation_failed', 'The process name is required.', ['field' => 'name'], 422);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $name)) {
            return $this->error('validation_failed', 'The process name must contain only lowercase letters, digits, and hyphens, cannot start or end with a hyphen, and may not exceed 64 characters.', ['field' => 'name', 'value' => $name], 422);
        }

        if ($command === null) {
            return $this->error('validation_failed', 'The process command is required.', ['field' => 'command'], 422);
        }

        $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

        if (! $restartPolicy instanceof ProcessRestartPolicy) {
            return $this->error('validation_failed', 'Invalid restart policy.', [
                'field' => 'restart_policy',
                'value' => $restartPolicyInput,
                'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
            ], 422);
        }

        $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

        if (! $crashNotification instanceof ProcessCrashNotification) {
            return $this->error('validation_failed', 'Invalid crash notification policy.', [
                'field' => 'crash_notification',
                'value' => $crashNotificationInput,
                'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
            ], 422);
        }

        $runtime = null;

        if ($runtimeInput !== null) {
            $runtime = ProcessRuntime::tryFrom($runtimeInput);

            if (! $runtime instanceof ProcessRuntime) {
                return $this->error('validation_failed', 'Invalid process runtime.', [
                    'field' => 'runtime',
                    'value' => $runtimeInput,
                    'allowed' => array_column(ProcessRuntime::cases(), 'value'),
                ], 422);
            }

            if ($runtime === ProcessRuntime::Systemd) {
                return $this->error('validation_failed', 'The systemd runtime is only valid for node-owned processes.', [
                    'field' => 'runtime',
                    'value' => $runtimeInput,
                    'reason' => 'systemd_requires_node_owned_process',
                ], 422);
            }
        }

        return [
            'app' => $app,
            'name' => $name,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'runtime' => $runtime,
            'start' => $request->boolean('start'),
        ];
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /processes';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'app' => $this->optionalString(request(), 'app'),
            'name' => $this->optionalString(request(), 'name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
