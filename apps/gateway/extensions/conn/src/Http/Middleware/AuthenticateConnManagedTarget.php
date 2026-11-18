<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedTargets;
use Symfony\Component\HttpFoundation\Response;

/** Admits a managed target route only for the authenticated target of the current generation, before any input check. */
final readonly class AuthenticateConnManagedTarget
{
    public function __construct(private ConnManagedTargets $targets) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('conn.managed_target', $this->targets->authenticate($request));

        return $next($request);
    }
}
