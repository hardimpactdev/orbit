<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Middleware;

use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use Closure;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTargets;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a managed route: the switch first, so every caller gets `conn.managed_disabled` while it is off, then the active
 * WireGuard peer and the Conn extension. It runs the two checks itself because the middleware priority list would move a
 * route-level peer check ahead of the switch.
 */
final readonly class RequireConnManagedTargets
{
    public function __construct(private RequireActiveWireGuardPeer $peer, private RequireEnabledExtension $extension) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        ConnManagedTargets::requireEnabled();

        return $this->peer->handle($request, fn (Request $request): Response => $this->extension->handle($request, $next, 'conn'));
    }
}
