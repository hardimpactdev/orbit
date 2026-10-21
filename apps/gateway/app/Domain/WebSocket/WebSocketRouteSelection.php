<?php

declare(strict_types=1);

namespace App\Domain\WebSocket;

use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Route;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;

final readonly class WebSocketRouteSelection
{
    public function __construct(private PublicRouteEligibility $eligibility, private WebSocketRouteInspector $inspector) {}

    public function select(Instance $instance, WebSocketApp $app, ?bool $public): ?Route
    {
        $previous = WebSocketAppTarget::query()->where('instance_id', $instance->id)->first();
        $preserve = $public === null && $previous?->websocket_app_id === $app->id && $previous->route_id !== null;
        if ($public !== true && ! $preserve) {
            return null;
        }

        $route = Route::query()->whereNull('web_root')
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))->first();
        if (! $instance->placedOnAppProd() || $instance->source_is_laravel !== true
            || ! $route instanceof Route || ! $route->isApp() || $route->status !== RouteStatus::Active
            || $route->target_set_intent !== null
            || ($route->target_set_step !== null && $route->target_set_step !== RouteTargetSetStep::Completed->value)
            || $route->transition_node_id !== null || $route->transition_cluster_id !== null
            || ! $this->eligibility->publicEdgeIsLive($route)) {
            throw new ResourceOperationException('websocket.public_route_invalid', 'Public Reverb requires an active public app Route on a production Laravel Instance.', 409);
        }
        if (WebSocketAppTarget::query()->where('route_id', $route->id)->where('instance_id', '!=', $instance->id)->where('websocket_app_id', '!=', $app->id)->exists()) {
            throw new ResourceOperationException('websocket.route_app_conflict', 'This Route already publishes another Reverb app.', 409);
        }
        foreach ($route->targets()->with('instance')->get() as $target) {
            if ($this->inspector->hasCollisions($target->instance)) {
                throw new ResourceOperationException('websocket.route_collision', 'The app declares Laravel routes under /app or /apps. Move those routes before enabling public Reverb.', 409);
            }
        }

        return $route;
    }

    public static function assertMutable(Route $route): void
    {
        if (WebSocketAppTarget::query()->where('route_id', $route->id)->exists()) {
            throw new ResourceOperationException('websocket.route_in_use', 'Remove public Reverb attachments before changing this Route.', 409);
        }
    }
}
