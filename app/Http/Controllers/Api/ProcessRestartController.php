<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\RestartProcesses;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProcessRestartController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(Request $request, RestartProcesses $restartProcesses): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], [], 403);
        }

        if (! in_array($caller->role, ['control', 'gateway', 'app'], true)) {
            return $this->error('caller_role_not_allowed', 'The local Orbit caller role could not be resolved.', ['caller_role' => $caller->role], [], 403);
        }

        $context = $this->resolveContext($request);

        if ($context instanceof JsonResponse) {
            return $context;
        }

        [$app, $workspace] = $context;

        if (! $this->callerCanOperateApp($caller, $app)) {
            return $this->error('authorization_failed', "This node is not authorized to operate process runtime state for app '{$app->name}'.", ['app' => $app->name], [], 403);
        }

        $name = $this->optionalString($request, 'name');

        try {
            $result = $restartProcesses->handle($app, $workspace, $name);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorData(), $this->statusFor($e));
        }

        $this->activitySubject = $app;

        if ($result['failed']) {
            return $this->error('process.runtime_action_failed', $result['message'], $result['meta'], $result['data'], 422);
        }

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => (object) [],
            ],
        ]);
    }

    /**
     * @return array{App, Workspace|null}|JsonResponse
     */
    private function resolveContext(Request $request): array|JsonResponse
    {
        $appName = $this->optionalString($request, 'app');
        $workspaceName = $this->optionalString($request, 'workspace');

        if ($workspaceName !== null) {
            $workspaces = Workspace::query()
                ->with('app.node')
                ->where('name', $workspaceName)
                ->when($appName !== null, fn ($query) => $query->whereHas('app', fn ($query) => $query->where('name', $appName)))
                ->get();

            if ($workspaces->isEmpty()) {
                return $this->error('validation_failed', "Workspace '{$workspaceName}' not found.", ['field' => 'workspace', 'value' => $workspaceName], [], 422);
            }

            if ($workspaces->count() > 1) {
                return $this->error('validation_failed', "Workspace name '{$workspaceName}' is ambiguous.", ['field' => 'workspace', 'value' => $workspaceName], [], 422);
            }

            $workspace = $workspaces->first();

            if (! $workspace->app instanceof App) {
                return $this->error('validation_failed', "Workspace '{$workspaceName}' is not attached to an app.", ['field' => 'workspace', 'value' => $workspaceName], [], 422);
            }

            return [$workspace->app, $workspace];
        }

        if ($appName === null) {
            return $this->error('validation_failed', 'An app context is required.', ['field' => 'app'], [], 422);
        }

        $app = App::query()->with('node')->where('name', $appName)->first();

        if (! $app instanceof App) {
            return $this->error('validation_failed', "App '{$appName}' not found.", ['field' => 'app', 'value' => $appName], [], 422);
        }

        return [$app, null];
    }

    private function callerCanOperateApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        if ($caller->id === $app->node_id) {
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

    private function statusFor(GatewayApiException $exception): int
    {
        return match ($exception->errorCode()) {
            'process.not_found' => 404,
            'authorization_failed', 'caller_role_not_allowed' => 403,
            default => 422,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function error(string $code, string $message, array $meta, array $data, int $status): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== []) {
            $error['data'] = $data;
        }

        $error['meta'] = empty($meta) ? (object) [] : $meta;

        return response()->json(['error' => $error], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function type(): string
    {
        return 'api:POST /processes/restart';
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
            'workspace' => $this->optionalString(request(), 'workspace'),
            'name' => $this->optionalString(request(), 'name'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
