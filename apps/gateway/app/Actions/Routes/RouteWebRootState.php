<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Route;

/** Pure admission checks; Node preparation belongs to the Instance child. */
final readonly class RouteWebRootState
{
    public function assertChange(Route $route, ?string $webRoot): Instance
    {
        $route->loadMissing(['targets.instance.project', 'targets.instance.node']);
        $instance = $route->targets->first()?->instance;
        if (! $route->isApp() || $route->status !== RouteStatus::Active || $route->targets->count() !== 1
            || $route->replaces_route_id !== null || $route->replaced_by_route_id !== null
            || ! $instance instanceof Instance || $instance->status !== InstanceState::Active) {
            throw new ResourceOperationException('route.web_root_unsupported', 'A Route web root can change only on an active Route with one active Instance target.', 409);
        }
        if ($webRoot === $route->web_root) {
            return $instance;
        }
        if ($webRoot === null) {
            RouteTargetWebRoot::assertSupported($instance);
            $own = Route::query()->whereKeyNot($route->id)->whereNull('web_root')
                ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))->first();
            if ($own !== null) {
                throw new ResourceOperationException('route.target_conflict', "Instance [{$instance->id}] is already associated with Route [{$own->id}] without a web root.", 409);
            }
        } elseif ($route->web_root === null && ($instance->requiresRoute() || $instance->placedOnAppProd())) {
            throw new ResourceOperationException('route.web_root_conflict', "Instance [{$instance->id}] keeps Route [{$route->id}] for its effective root. Create another Route with a web root instead.", 409);
        }
        if ($instance->placedOnAppProd() && $webRoot !== null) {
            RouteWebRoot::assertProductionLayout($instance, $webRoot, $route);
        }

        return $instance;
    }
}
