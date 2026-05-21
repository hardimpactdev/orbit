<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\EditProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('process:edit', servingNode: ServingNode::AppOwning)]
final class ProcessUpdateController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $name, Request $request, EditProcess $editProcess): JsonResponse
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
            $result = $editProcess->handle(
                app: $app,
                name: $name,
                changes: $input['changes'],
                restart: $input['restart'],
            );
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorCode() === 'process.not_found' ? 404 : 422);
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
     * @return array{app: string, changes: array{command?: string, restart_policy?: ProcessRestartPolicy, crash_notification?: ProcessCrashNotification}, restart: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $app = $this->optionalString($request, 'app');
        $command = $this->optionalString($request, 'command');
        $restartPolicyInput = $this->optionalString($request, 'restart_policy');
        $crashNotificationInput = $this->optionalString($request, 'crash_notification');

        if ($app === null) {
            return $this->error('validation_failed', 'An app context is required.', ['field' => 'app'], 422);
        }

        if ($command === null && $restartPolicyInput === null && $crashNotificationInput === null) {
            return $this->error('validation_failed', 'At least one editable field is required.', ['field' => 'editable_fields'], 422);
        }

        $changes = [];

        if ($command !== null) {
            $changes['command'] = $command;
        }

        if ($restartPolicyInput !== null) {
            $restartPolicy = ProcessRestartPolicy::tryFrom($restartPolicyInput);

            if (! $restartPolicy instanceof ProcessRestartPolicy) {
                return $this->error('validation_failed', 'Invalid restart policy.', [
                    'field' => 'restart_policy',
                    'value' => $restartPolicyInput,
                    'allowed' => array_column(ProcessRestartPolicy::cases(), 'value'),
                ], 422);
            }

            $changes['restart_policy'] = $restartPolicy;
        }

        if ($crashNotificationInput !== null) {
            $crashNotification = ProcessCrashNotification::tryFrom($crashNotificationInput);

            if (! $crashNotification instanceof ProcessCrashNotification) {
                return $this->error('validation_failed', 'Invalid crash notification policy.', [
                    'field' => 'crash_notification',
                    'value' => $crashNotificationInput,
                    'allowed' => array_column(ProcessCrashNotification::cases(), 'value'),
                ], 422);
            }

            $changes['crash_notification'] = $crashNotification;
        }

        return [
            'app' => $app,
            'changes' => $changes,
            'restart' => $request->boolean('restart'),
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
        return 'api:PATCH /processes/{name}';
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
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
