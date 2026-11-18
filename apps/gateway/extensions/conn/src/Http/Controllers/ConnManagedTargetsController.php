<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\Managed\IssueConnManagedTokenAction;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedAuthority;
use Orbit\Extensions\Conn\Http\Requests\ConnManagedTargetRequest;
use Orbit\Extensions\Conn\Http\Requests\IssueConnManagedTokenRequest;

/** The target routes and the caller token route of [Managed grant revocation](/reference/conn#managed-grant-revocation). */
final class ConnManagedTargetsController extends Controller
{
    public function realtime(ConnManagedTargetRequest $request, RealtimeConnection $realtime): JsonResponse
    {
        $connection = $realtime->resolve();

        return $this->respond($request, ['url' => $connection?->url(), 'key' => $connection?->key, 'channel' => $request->target()->channel()]);
    }

    /** @return array{auth: string} */
    public function broadcastingAuth(ConnManagedTargetRequest $request, RealtimeConnection $realtime): array
    {
        $channel = $request->string('channel_name')->toString();
        if ($channel !== $request->target()->channel()) {
            throw new ResourceOperationException('conn.managed_channel_forbidden', 'The channel is not this target\'s current channel.', 403);
        }
        $connection = $realtime->resolve();
        if ($connection === null) {
            throw new ResourceOperationException('realtime.not_configured', 'Realtime is not configured.', 404);
        }

        return ['auth' => $connection->key.':'.hash_hmac('sha256', $request->string('socket_id')->toString().':'.$channel, $connection->secret)];
    }

    public function authority(ConnManagedTargetRequest $request, ConnManagedAuthority $authority): JsonResponse
    {
        $after = $request->filled('after_revision') ? $request->integer('after_revision') : null;

        return $this->respond($request, $authority->state($request->target(), $request->string('nonce')->toString(), $after));
    }

    public function introspect(ConnManagedTargetRequest $request, ConnManagedAuthority $authority): JsonResponse
    {
        return $this->respond($request, $authority->introspect(
            $request->target(),
            $request->string('nonce')->toString(),
            $request->string('token')->toString(),
            $request->string('peer_address')->toString(),
        ));
    }

    public function ack(ConnManagedTargetRequest $request, ConnManagedAuthority $authority): JsonResponse
    {
        return $this->respond($request, $authority->acknowledge($request->target(), [
            'revision' => $request->integer('revision'),
            'kind' => $request->string('kind')->toString(),
            'notice_received_at' => $request->nullableString('notice_received_at'),
            'fenced_at' => $request->nullableString('fenced_at'),
            'handlers_closed_at' => $request->nullableString('handlers_closed_at'),
            'recovered_at' => $request->nullableString('recovered_at'),
        ]));
    }

    public function issue(IssueConnManagedTokenRequest $request, string $environment, IssueConnManagedTokenAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $this->respond($request, $action->execute($caller, $environment), 201);
    }

    /** @param array<string, mixed> $data */
    private function respond(Request $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }
}
