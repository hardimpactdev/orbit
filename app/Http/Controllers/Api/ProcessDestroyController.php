<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\RemoveProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProcessDestroyController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $name, Request $request, RemoveProcess $removeProcess): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app' || ! in_array($caller->role, ['control', 'gateway'], true)) {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => $caller->role], 403);
        }

        $appName = $this->optionalString($request, 'app');

        if ($appName === null) {
            return $this->error('validation_failed', 'An app context is required.', ['field' => 'app'], 422);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('validation_failed', 'Use --force to remove this process.', ['field' => 'force'], 422);
        }

        $app = App::query()->with(['node', 'workspaces'])->where('name', $appName)->first();

        if (! $app instanceof App) {
            return $this->error('validation_failed', "App '{$appName}' not found.", ['field' => 'app', 'value' => $appName], 422);
        }

        if (! $this->callerCanManageApp($caller, $app)) {
            return $this->error('authorization_failed', "This node is not authorized to manage app '{$app->name}'.", ['app' => $app->name], 403);
        }

        try {
            $result = $removeProcess->handle($app, $name);
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
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /processes/{name}';
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
