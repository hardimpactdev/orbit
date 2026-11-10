<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;

/** Successful Route publication settles development readiness, without a deployment policy run. */
final readonly class DevelopmentRoutePublication
{
    public function settle(Route $route): void
    {
        if (! $route->sites_published) {
            return;
        }
        foreach ($route->targets()->with(['instance.node.roles', 'instance.routes'])->get() as $target) {
            $instance = $target->instance;
            if ($instance->placedOnAppDev() && $instance->authoritativeRoute()?->id === $route->id) {
                $instance->update(['development_projection_pending' => false]);
            }
        }
    }
}
