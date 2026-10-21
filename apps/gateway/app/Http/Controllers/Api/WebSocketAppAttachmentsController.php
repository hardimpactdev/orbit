<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\WebSocket\AttachWebSocketAppAction;
use App\Actions\WebSocket\DetachWebSocketAppAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\WebSocketApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
final class WebSocketAppAttachmentsController extends Controller
{
    public function store(Request $request, Instance $instance, WebSocketApp $websocketApp, AttachWebSocketAppAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($instance, $websocketApp)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function destroy(Request $request, Instance $instance, WebSocketApp $websocketApp, DetachWebSocketAppAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($instance, $websocketApp)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
