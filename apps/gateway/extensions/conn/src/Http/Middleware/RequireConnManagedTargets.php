<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTargets;
use Symfony\Component\HttpFoundation\Response;

/** Refuses every managed route with `conn.managed_disabled` while the switch is off, before any other check. */
final readonly class RequireConnManagedTargets
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        ConnManagedTargets::requireEnabled();

        return $next($request);
    }
}
