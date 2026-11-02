<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Route;

/** Whether an Instance is an active production placement, and the public HTTPS origins of its Project Routes. */
final readonly class InstanceProductionOwnership
{
    /** @param list<string> $baseUrls */
    private function __construct(public bool $production, public array $baseUrls) {}

    public static function of(Instance $instance): self
    {
        $instance->refresh()->load('node.roles', 'routes');
        $roles = $instance->node->roles->where('status', LifecycleStatus::Active)->pluck('role');
        $production = $instance->status === InstanceState::Active && $instance->node->status === LifecycleStatus::Active
            && $instance->placedOnAppProd() && $roles->contains(RoleName::AppProd) && ! $roles->contains(RoleName::AppDev);
        $urls = $instance->routes->filter(static fn (Route $route): bool => $route->isApp()
                && $route->status === RouteStatus::Active
                && $route->publication === RoutePublication::Public
                && $route->project_id === $instance->project_id)
            ->map(static fn (Route $route): string => 'https://'.$route->domain)->unique()->sort()->values()->all();

        return new self($production, array_values($urls));
    }
}
