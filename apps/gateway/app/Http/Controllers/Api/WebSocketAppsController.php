<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Actions\WebSocket\ReadWebSocketApplyStatusAction;
use App\Actions\WebSocket\StartWebSocketOperationAction;
use App\Data\Operations\OperationData;
use App\Data\WebSocket\WebSocketAppData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\WebSocket\StoreWebSocketAppRequest;
use App\Http\Requests\WebSocket\WebSocketChangeRequest;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRegistryOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class WebSocketAppsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => WebSocketApp::query()
                ->orderBy('name')
                ->get()
                ->map(static fn (WebSocketApp $app): array => WebSocketAppData::fromModel($app)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('websocket.app_create')]
    public function store(StoreWebSocketAppRequest $request, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'create', $request->name());
    }

    public function show(Request $request, WebSocketApp $websocketApp): JsonResponse
    {
        return response()->json([
            'data' => WebSocketAppData::fromModel($websocketApp)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('websocket.app_destroy')]
    public function destroy(WebSocketChangeRequest $request, string $websocketApp, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'destroy', $websocketApp);
    }

    #[StartsOperation('websocket.app_rotate')]
    public function rotate(WebSocketChangeRequest $request, string $websocketApp, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'rotate', $websocketApp);
    }

    #[StartsOperation('websocket.sync')]
    public function sync(WebSocketChangeRequest $request, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'sync', null);
    }

    public function operation(Request $request, WebSocketRegistryOperation $operation, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return response()->json(['data' => $operation->result, 'operation' => $status->execute($operation), 'meta' => $this->meta($request)]);
    }

    private function change(WebSocketChangeRequest $request, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status, string $kind, ?string $name): JsonResponse
    {
        $operation = DB::transaction(function () use ($request, $action, $kind, $name) {
            $registry = $action->execute($kind, $name, $this->actor($request)->id, $request->operationKey());

            return app(StartWebSocketOperationAction::class)->execute($registry);
        });

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    private function actor(Request $request): Node
    {
        $actor = $request->user();
        abort_unless($actor instanceof Node, 403);

        return $actor;
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
