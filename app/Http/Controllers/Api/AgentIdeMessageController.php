<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Gateway\GatewayApiException;
use App\Http\Requests\Api\SendAgentIdeMessageApiRequest;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\AgentIde\AgentIdeMessageDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final readonly class AgentIdeMessageController
{
    public function __construct(
        private AgentIdeMessageDelivery $delivery,
    ) {}

    public function __invoke(SendAgentIdeMessageApiRequest $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->error(
                code: 'authorization_failed',
                message: 'Peer identity unknown.',
                meta: [],
                status: 403,
            );
        }

        $workspaceSelector = $request->workspaceSelector();

        if ($workspaceSelector !== null) {
            return $this->sendWorkspaceMessage($request, $caller, $workspaceSelector);
        }

        $app = $this->resolveApp($request->appSelector());

        if (! $app instanceof App) {
            return $this->error(
                code: 'target_not_found',
                message: "App '{$request->appSelector()}' not found or not visible.",
                meta: ['app' => $request->appSelector()],
                status: 404,
            );
        }

        $app->loadMissing('node');

        if (! $app->node instanceof Node || ! $this->callerCanMessageApp($caller, $app)) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message app '{$app->name}'.",
                meta: [
                    'app' => $app->name,
                    'caller_role' => $caller->role,
                ],
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToApp($app->name, $request->messageBody());
        } catch (GatewayApiException $e) {
            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function sendWorkspaceMessage(SendAgentIdeMessageApiRequest $request, Node $caller, string $workspaceSelector): JsonResponse
    {
        $workspace = $this->resolveWorkspace($workspaceSelector);

        if (! $workspace instanceof Workspace || ! $workspace->app instanceof App) {
            return $this->error(
                code: 'target_not_found',
                message: "Workspace '{$workspaceSelector}' not found or not visible.",
                meta: ['workspace' => $workspaceSelector],
                status: 404,
            );
        }

        $workspace->app->loadMissing('node');

        if (! $workspace->app->node instanceof Node || ! $this->callerCanMessageApp($caller, $workspace->app)) {
            return $this->error(
                code: 'authorization_failed',
                message: "This node is not authorized to message workspace '{$workspace->name}'.",
                meta: [
                    'app' => $workspace->app->name,
                    'workspace' => $workspace->name,
                    'caller_role' => $caller->role,
                ],
                status: 403,
            );
        }

        try {
            $data = $this->delivery->deliverToWorkspace($workspace->name, $request->messageBody());
        } catch (GatewayApiException $e) {
            return $this->error(
                code: $e->errorCode() ?? 'adapter_delivery_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $this->statusFor($e->errorCode()),
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
            ],
        ]);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->first(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}");
    }

    private function resolveWorkspace(string $selector): ?Workspace
    {
        $matches = Workspace::query()
            ->with('app.node')
            ->where('name', $selector)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function callerCanMessageApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            return false;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();
    }

    private function statusFor(?string $code): int
    {
        return match ($code) {
            'target_not_found' => 404,
            'no_effective_adapter', 'no_active_session' => 422,
            default => 500,
        };
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
                'meta' => $meta,
            ],
        ], $status);
    }
}
