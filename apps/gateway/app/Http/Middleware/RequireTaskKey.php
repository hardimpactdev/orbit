<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tasks\TaskKey;
use App\Http\Responses\ApiErrorResponse;
use App\Models\Instance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A call from a task copy through its Node's forwarder (docs/reference/development-containers.md#the-copy-key): the bearer is the
 * copy's own key, and the request comes from the WireGuard address of that copy's Node. The Node alone is not enough, and these routes
 * never accept it: every container on the Node calls from that address. The request carries the copy's Instance.
 */
final class RequireTaskKey
{
    public const string Attribute = 'orbit.task_copy';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $runtime = TaskKey::runtime($request->bearerToken());
        $instance = $runtime === null ? null : Instance::query()->find($runtime->instance_id);
        if ($runtime === null || $instance === null || $instance->task_workspace_owner_id === null) {
            return $this->refuse($request, 'copy.key_invalid', 'A task copy key is required.', 401);
        }
        if (RequireActiveWireGuardPeer::activePeer($request)?->id !== $runtime->node_id) {
            return $this->refuse($request, 'copy.peer_mismatch', 'The key belongs to a task copy on another Node.', 403);
        }
        $request->attributes->set(self::Attribute, $instance);

        return $next($request);
    }

    private function refuse(Request $request, string $code, string $message, int $status): Response
    {
        $request->attributes->set('orbit.error_code', $code);

        return ApiErrorResponse::make($request, $code, $message, [], $status);
    }
}
