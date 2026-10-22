<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetStep;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstancePathProxy;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;

final readonly class PathProxy
{
    public const string PrefixPattern = '/\A\/[a-z][a-z0-9-]{0,62}\z/D';

    public function __construct(private PublicRouteEligibility $eligibility, private LaravelRouteInspector $inspector) {}

    public function select(Instance $instance, string $prefix): Route
    {
        if (preg_match(self::PrefixPattern, $prefix) !== 1 || in_array($prefix, ['/app', '/apps', '/__orbit'], true)) {
            throw new ResourceOperationException('path_proxy.prefix_invalid', 'Choose an unreserved lowercase top-level path prefix.', 422);
        }
        $route = $instance->routes()->whereNull('web_root')->orderBy('routes.id')->first();
        if ($instance->status !== InstanceState::Active || ! $instance->placedOnAppProd() || $instance->source_is_laravel !== true
            || ! $route instanceof Route || ! $route->isApp() || $route->status !== RouteStatus::Active
            || $route->failed_step !== null || $route->target_set_intent !== null
            || ($route->target_set_step !== null && $route->target_set_step !== RouteTargetSetStep::Completed->value)
            || $route->transition_node_id !== null || $route->transition_cluster_id !== null
            || ! $this->eligibility->publicEdgeIsLive($route)) {
            throw new ResourceOperationException('path_proxy.public_route_invalid', 'A path proxy requires an active public app Route on a production Laravel Instance.', 409);
        }
        if (InstancePathProxy::query()->where('route_id', $route->id)->where('instance_id', '!=', $instance->id)->exists()) {
            throw new ResourceOperationException('path_proxy.route_conflict', 'This Route already publishes another Instance path proxy.', 409);
        }
        foreach ($route->targets()->with('instance')->get() as $target) {
            if ($target->instance->source_is_laravel !== true || ! $target->instance->placedOnAppProd()) {
                throw new ResourceOperationException('path_proxy.public_route_invalid', 'Every Route target must be a production Laravel Instance.', 409);
            }
            if ($this->inspector->hasCollisions($target->instance, [$prefix])) {
                throw new ResourceOperationException('path_proxy.route_collision', 'Laravel routes overlap the reserved path prefix. Move those routes before publishing.', 409);
            }
        }

        return $route;
    }

    public static function upstream(Process $process, int $port): string
    {
        $node = $process->owner;
        if ($process->owner_type === Node::class && $node instanceof Node && $node->status === LifecycleStatus::Active
            && $node->platform === 'linux' && is_string($node->wireguard_ip) && filter_var($node->wireguard_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $process->runtime === ProcessRuntime::Docker && $process->status === LifecycleStatus::Active) {
            $ports = $process->runtime_config['ports'] ?? [];
            if (is_array($ports)) {
                foreach ($ports as $spec) {
                    if (! is_string($spec) || (str_contains($spec, '/') && ! str_ends_with($spec, '/tcp'))) {
                        continue;
                    }
                    $published = DockerPublishedPort::parse($spec);
                    if ($published !== null && $published->bindAddress === $node->wireguard_ip && $published->publishedPort === $port) {
                        return $node->wireguard_ip.':'.$port;
                    }
                }
            }
        }
        throw new ResourceOperationException('path_proxy.upstream_invalid', 'Select an active Node-owned Docker Process with the exact TCP published port bound to its WireGuard address.', 409);
    }

    public static function stored(Instance $instance): InstancePathProxy
    {
        return InstancePathProxy::query()->where('instance_id', $instance->id)->first()
            ?? throw new ResourceOperationException('path_proxy.missing', 'This Instance has no path proxy.', 404);
    }

    public static function assertRouteMutable(Route $route): void
    {
        if (InstancePathProxy::query()->where('route_id', $route->id)->exists()) {
            throw new ResourceOperationException('path_proxy.route_in_use', 'Remove the Instance path proxy before changing this Route.', 409);
        }
    }

    public static function assertProcessMutable(Process $process): void
    {
        if (InstancePathProxy::query()->where('process_id', $process->id)->exists()) {
            throw new ResourceOperationException('path_proxy.process_in_use', 'Remove the Instance path proxy before updating or deleting its Process.', 409);
        }
    }
}
