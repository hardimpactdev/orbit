<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Node;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WireGuardIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof Node) {
            $request->setUserResolver(static fn (): Node => $user);

            return $next($request);
        }

        $node = Node::query()
            ->where('wireguard_address', $request->ip())
            ->where('status', 'active')
            ->first();

        if (! $node instanceof Node) {
            return $this->forbidden();
        }

        $request->setUserResolver(static fn (): Node => $node);

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'Peer identity unknown.',
                'meta' => [],
            ],
        ], 403);
    }
}
