<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\ProxyRoute;
use App\Services\Proxy\NonInstanceProxyRouteOwnership;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Database\Eloquent\Collection;

class MetricsRouteUpstreamBackfill
{
    public function run(): void
    {
        $renderer = app(ProxyRouteRenderer::class);
        $ownership = app(NonInstanceProxyRouteOwnership::class);

        /** @mago-expect analyzer:docblock-type-mismatch */
        /** @var Collection<int, ProxyRoute> $routes */
        $routes = ProxyRoute::query()
            ->where('domain', MetricsServiceRoute::Domain)
            ->where('owner_type', 'router')
            ->where('kind', 'proxy')
            ->whereNull('app_id')
            ->whereNull('workspace_id')
            ->whereNull('instance_id')
            ->get();

        foreach ($routes as $route) {
            if (! $ownership->matchesStableMetricsFamily($route)) {
                continue;
            }

            $route->config = MetricsServiceRoute::config();
            $route->source_hash = $renderer->sourceHash($route);
            $route->save();
        }
    }
}
