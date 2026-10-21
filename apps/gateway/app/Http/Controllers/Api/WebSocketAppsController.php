<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\WebSocket\ChangeWebSocketRegistryAction;
use App\Actions\WebSocket\ReadWebSocketApplyStatusAction;
use App\Data\WebSocket\WebSocketAppData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebSocket\StoreWebSocketAppRequest;
use App\Http\Requests\WebSocket\WebSocketChangeRequest;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRegistryOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function destroy(WebSocketChangeRequest $request, string $websocketApp, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'destroy', $websocketApp);
    }

    public function rotate(WebSocketChangeRequest $request, string $websocketApp, ChangeWebSocketRegistryAction $action, ReadWebSocketApplyStatusAction $status): JsonResponse
    {
        return $this->change($request, $action, $status, 'rotate', $websocketApp);
    }

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
        $actor = $this->actor($request);
        $operation = $action->execute($kind, $name, $actor->id, $request->operationKey());
        $applied = $status->wait($operation);

        return response()->json([
            'data' => $operation->result, 'operation' => $applied,
            'meta' => $this->meta($request),
        ], $applied['state'] === 'pending' ? 202 : ($applied['state'] === 'applied' && $kind === 'create' ? 201 : 200));
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
