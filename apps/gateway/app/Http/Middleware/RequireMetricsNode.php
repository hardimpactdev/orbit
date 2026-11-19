<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits only the Node with the active `metrics` role, whose Prometheus scrapes over WireGuard. Runs after
 * `RequireActiveWireGuardPeer`, which resolved the caller from its WireGuard address.
 */
final class RequireMetricsNode
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $node = $request->user();
        if (! $node instanceof Node || ! $node->roles()->where('role', RoleName::Metrics->value)->where('status', LifecycleStatus::Active->value)->exists()) {
            $request->attributes->set('orbit.error_code', 'metrics.scraper_required');

            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'metrics.scraper_required',
                'message' => 'Only the Metrics Node may scrape this target.',
                'details' => [],
            ],
        ], 403);
    }
}
