<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;
use Symfony\Component\HttpFoundation\Response;

/** Admits a Conn push route only while `orbit.conn.push` is enabled. */
final readonly class RequireConnPush
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        ConnPush::requireEnabled();

        return $next($request);
    }
}
