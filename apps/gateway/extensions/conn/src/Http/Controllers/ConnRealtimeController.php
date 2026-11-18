<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\ConnProfileEvents;
use Orbit\Extensions\Conn\Http\Requests\ConnRealtimeAuthRequest;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

/**
 * Discovery and subscription for Conn profile events ([Profile events](/reference/conn#profile-events)). They reuse the
 * Gateway's Reverb but grant no Gateway realtime access: the auth route signs only the caller's current profile channel.
 */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnRealtimeController extends Controller
{
    public function show(Request $request, RealtimeConnection $realtime): JsonResponse
    {
        $connection = $realtime->resolve();

        return response()->json([
            'data' => ['url' => $connection?->url(), 'key' => $connection?->key],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function authenticate(ConnRealtimeAuthRequest $request, RealtimeConnection $realtime): JsonResponse
    {
        $connection = $realtime->resolve();

        if ($connection === null) {
            throw new ResourceOperationException('realtime.not_configured', 'Realtime is not configured.', 404);
        }

        $binding = ConnProfileBinding::query()->where('node_id', $this->caller($request)->id)->first();
        $channel = $request->channelName();

        if ($binding === null || ! ConnProfileEvents::current($binding) || ! hash_equals((string) ConnProfileEvents::channel($binding), $channel)) {
            throw new ResourceOperationException('broadcast.channel_forbidden', 'Channel is not authorized.', 403);
        }

        return response()->json([
            'auth' => $connection->key.':'.hash_hmac('sha256', "{$request->socketId()}:{$channel}", $connection->secret),
        ]);
    }

    private function caller(Request $request): Node
    {
        $node = $request->user();
        assert($node instanceof Node, description: 'Authenticated peer must be a Node.');

        return $node;
    }
}
