<?php

declare(strict_types=1);

namespace HardImpact\Orbit\App\Http\Middleware;

use Closure;
use HardImpact\Orbit\Core\Services\SetupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect to setup page if first-run setup is needed.
 * Only applies to web mode (when running as desktop app).
 */
class RedirectIfNeedsSetup
{
    public function __construct(
        protected SetupService $setup,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if already on setup routes
        if ($request->is('setup', 'setup/*')) {
            return $next($request);
        }

        // Skip for API requests
        if ($request->is('api/*')) {
            return $next($request);
        }

        // Skip for asset requests
        if ($request->is('_*', 'build/*', 'vendor/*')) {
            return $next($request);
        }

        // Check if setup is needed
        if ($this->setup->needsSetup()) {
            return redirect()->route('setup.index');
        }

        return $next($request);
    }
}
