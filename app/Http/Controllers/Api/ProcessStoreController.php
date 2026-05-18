<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\AddProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProcessStoreController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(Request $request, AddProcess $addProcess): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app' || ! in_array($caller->role, ['control', 'gateway'], true)) {
            return $this->error('caller_role_not_allowed', 'This command may only be run from an operator or gateway node.', ['caller_role' => $caller->role], 403);
        }

        $input = $this->validatedInput($request);

        if ($input instanceof JsonResponse) {
            return $input;
        }

        $app = App::query()->with(['node', 'workspaces'])->where('name', $input['app'])->first();

        if (! $app instanceof App) {
            return $this->error('validation_failed', "App '{$input['app']}' not found.", ['field' => 'app', 'value' => $input['app']], 422);
        }

        if (! $this->callerCanManageApp($caller, $app)) {
            return $this->error('authorization_failed', "This node is not authorized to manage app '{$app->name}'.", ['app' => $app->name], 403);
        }

        try {
            $result = $addProcess->handle(
                app: $app,
                name: $input['name'],
                command: $input['command'],
                restartPolicy: $input['restart_policy'],
                crashNotification: $input['crash_notification'],
                start: $input['start'],
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
     * @return array{app: string, name: string, command: string, restart_policy: ProcessRestartPolicy, crash_notification: ProcessCrashNotification, start: bool}|JsonResponse
     */
    private function validatedInput(Request $request): array|JsonResponse
    {
        $app = $this->optionalString($request, 'app');
        $name = $this->optionalString($request, 'name');
        $command = $this->optionalString($request, 'command');
        $restartPolicyInput = $this->optionalString($request, 'restart_policy') ?? ProcessRestartPolicy::Never->value;
        $crashNotificationInput = $this->optionalString($request, 'crash_notification') ?? ProcessCrashNotification::None->value;

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

        return [
            'app' => $app,
            'name' => $name,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'crash_notification' => $crashNotification,
            'start' => $request->boolean('start'),
        ];
    }

    private function callerCanManageApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $app->node_id)
            ->exists();
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
