<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Ui\Http\Middleware;

use Closure;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ImplicitEnvironment
{
    public function __construct(protected EnvironmentManager $environments) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Only active in web mode (multi_environment=false)
        if (config('orbit.multi_environment')) {
            return $next($request);
        }

        $route = $request->route();
        if (! $route) {
            return $next($request);
        }

        // If environment is already in the route, we don't need to inject it
        if ($route->hasParameter('environment')) {
            return $next($request);
        }

        // Also skip if it's an explicit environment route that hasn't bound yet (unlikely here)
        if (str_starts_with($route->uri(), 'environments/{environment}')) {
            return $next($request);
        }

        $environment = $this->environments->current();

        if (! $environment) {
            abort(500, 'No environment found. Run: php artisan orbit:init');
        }

        // Warn if multiple is_local environments exist
        $count = \HardImpact\Orbit\Core\Models\Environment::where('is_local', true)->count();
        if ($count > 1) {
            Log::warning("Multiple is_local=true environments found ({$count}). Using first.");
        }

        // Inject into route so controllers receive it as parameter
        // We ensure it's the FIRST parameter to match controller method signatures
        $params = $route->parameters();
        foreach (array_keys($params) as $key) {
            $route->forgetParameter($key);
        }

        $route->setParameter('environment', $environment);

        foreach ($params as $key => $value) {
            $route->setParameter($key, $value);
        }

        return $next($request);
    }
}
