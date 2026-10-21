<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\WebSocketRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireWebSocketRuntimeSecret
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $node = $request->user();
        $secret = $request->bearerToken();
        if (! $node instanceof Node || ! is_string($secret) || $secret === '') {
            throw new ResourceOperationException('websocket.runtime_unauthorized', 'A runtime credential is required.', 403);
        }
        $runtime = $this->authenticate($node, $secret);
        if (! $runtime instanceof WebSocketRuntime || ($request->route('runtime') !== null && $request->route('runtime') !== $runtime->id)) {
            throw new ResourceOperationException('websocket.runtime_unauthorized', 'The runtime credential is not valid for this process.', 403);
        }
        $request->attributes->set('websocket.runtime', $runtime);

        return $next($request);
    }

    private function authenticate(Node $node, string $secret): ?WebSocketRuntime
    {
        return WebSocketRuntime::query()->where('node_id', $node->id)->where('required', true)->get()
            ->first(static fn (WebSocketRuntime $candidate): bool => hash_equals($candidate->registry_token, $secret));
    }
}
