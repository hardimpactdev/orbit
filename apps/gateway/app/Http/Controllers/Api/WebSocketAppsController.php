<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\WebSocket\CreateWebSocketAppAction;
use App\Actions\WebSocket\DestroyWebSocketAppAction;
use App\Data\WebSocket\WebSocketAppData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebSocket\StoreWebSocketAppRequest;
use App\Models\WebSocketApp;
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

    public function store(StoreWebSocketAppRequest $request, CreateWebSocketAppAction $action): JsonResponse
    {
        return response()->json([
            'data' => WebSocketAppData::fromModel($action->execute($request->name()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    public function show(Request $request, WebSocketApp $websocketApp): JsonResponse
    {
        return response()->json([
            'data' => WebSocketAppData::fromModel($websocketApp)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(Request $request, WebSocketApp $websocketApp, DestroyWebSocketAppAction $action): JsonResponse
    {
        $data = WebSocketAppData::fromModel($websocketApp)->toArray();
        $action->execute($websocketApp);

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
