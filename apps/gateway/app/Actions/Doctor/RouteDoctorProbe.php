<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\CustomProxyRouteObservation;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\RouteDoctorIssueCode;
use App\Domain\Instances\LaravelRouteInspector;
use App\Domain\Instances\PathProxy;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\WebSocket\WebSocketRouteInspector;
use App\Models\InstancePathProxy;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use App\Models\WebSocketAppTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class RouteDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private CustomProxyRouteInspector $inspector,
        private ReservedPrivateHostname $reservedHostnames,
        private WebSocketRouteInspector $websocketRoutes,
        private LaravelRouteInspector $pathRoutes,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::Route;
    }

    /** @return list<DoctorResources> */
    public function resources(Node $node): array
    {
        return DoctorResources::groups(array_values(array_unique(StoredInteger::listFrom([
            ...$this->servedRoutes($node)->pluck('id')->all(),
            ...$this->proxies($node)->pluck('route_id')->all(),
        ]))));
    }

    /** @return Collection<int, RouteCustomProxy> */
    private function proxies(Node $node): Collection
    {
        return RouteCustomProxy::query()->with('route')->where('node_id', $node->id)->orderBy('route_id')->get();
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $routes = $context->select($this->servedRoutes($context->node));
        $issues = [...$this->lifecycleIssues($routes), ...$this->reservedIssues($routes)];
        $attachments = WebSocketAppTarget::query()->with(['route.targets.instance'])->whereIn('route_id', $routes->modelKeys())->get()->unique('route_id');
        $observedChecks = [];
        foreach ($routes as $route) {
            $observedChecks[$route->id] = [RouteDoctorIssueCode::LifecycleNotActive->value, RouteDoctorIssueCode::DomainReserved->value];
            if (! $attachments->contains('route_id', $route->id)) {
                $observedChecks[$route->id][] = RouteDoctorIssueCode::WebSocketCollision->value;
                $observedChecks[$route->id][] = RouteDoctorIssueCode::WebSocketInspectionFailed->value;
            }
        }
        foreach ($attachments as $attachment) {
            $route = $attachment->route;
            if (! $route instanceof Route) {
                continue;
            }
            try {
                if (! $context->inspection->reachable) {
                    throw new ResourceOperationException('websocket.route_inspection_failed', 'Node unreachable.', 409);
                }
                $collision = false;
                foreach ($route->targets as $target) {
                    $collision = $this->websocketRoutes->hasCollisions($target->instance) || $collision;
                }
                $observedChecks[$route->id][] = RouteDoctorIssueCode::WebSocketCollision->value;
                $observedChecks[$route->id][] = RouteDoctorIssueCode::WebSocketInspectionFailed->value;
                if ($collision) {
                    $issues[] = new DoctorIssueData(RouteDoctorIssueCode::WebSocketCollision, DoctorIssueKind::Drift,
                        'route', $route->id, $route->domain, 'Laravel routes overlap the reserved public Reverb prefixes /app or /apps.', 'no collision', 'collision');
                }
            } catch (ResourceOperationException) {
                $issues[] = new DoctorIssueData(RouteDoctorIssueCode::WebSocketInspectionFailed, DoctorIssueKind::Unverifiable,
                    'route', $route->id, $route->domain, 'The current Laravel release could not be checked for public Reverb route collisions.', 'verifiable', 'unverifiable');
            }
        }
        $paths = InstancePathProxy::query()->with(['route.targets.instance', 'process.owner'])->whereIn('route_id', $routes->modelKeys())->where('enabled', true)->get();
        foreach ($routes as $route) {
            if (! $paths->contains('route_id', $route->id)) {
                $observedChecks[$route->id] = [...$observedChecks[$route->id], RouteDoctorIssueCode::PathProxyCollision->value,
                    RouteDoctorIssueCode::PathProxyInspectionFailed->value, RouteDoctorIssueCode::PathProxyUpstreamInvalid->value];
            }
        }
        foreach ($paths as $path) {
            $route = $path->route;
            try {
                PathProxy::upstream($path->process, $path->port);
                $observedChecks[$route->id][] = RouteDoctorIssueCode::PathProxyUpstreamInvalid->value;
            } catch (ResourceOperationException) {
                $issues[] = new DoctorIssueData(RouteDoctorIssueCode::PathProxyUpstreamInvalid, DoctorIssueKind::Drift,
                    'route', $route->id, $route->domain, 'The path proxy no longer selects a valid WireGuard TCP published port.', 'valid', 'invalid');
            }
            try {
                if (! $context->inspection->reachable) {
                    throw new ResourceOperationException('path_proxy.route_inspection_failed', 'Node unreachable.', 409);
                }
                $collision = false;
                foreach ($route->targets as $target) {
                    $collision = $this->pathRoutes->hasCollisions($target->instance, [$path->prefix]) || $collision;
                }
                $observedChecks[$route->id][] = RouteDoctorIssueCode::PathProxyCollision->value;
                $observedChecks[$route->id][] = RouteDoctorIssueCode::PathProxyInspectionFailed->value;
                if ($collision) {
                    $issues[] = new DoctorIssueData(RouteDoctorIssueCode::PathProxyCollision, DoctorIssueKind::Drift,
                        'route', $route->id, $route->domain, 'Laravel routes overlap the reserved path proxy prefix.', 'no collision', 'collision');
                }
            } catch (ResourceOperationException) {
                $issues[] = new DoctorIssueData(RouteDoctorIssueCode::PathProxyInspectionFailed, DoctorIssueKind::Unverifiable,
                    'route', $route->id, $route->domain, 'The current Laravel release could not be checked for path proxy collisions.', 'verifiable', 'unverifiable');
            }
        }
        $proxies = $context->select($this->proxies($context->node), key: 'route_id');

        if ($context->inspection->reachable) {
            $observedChecks[DoctorResources::nodeCheckId($context->node->id)] = [RouteDoctorIssueCode::NodeUnreachable->value];
        }
        if ($proxies->isEmpty()) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), $issues, observedChecks: $observedChecks);
        }

        if (! $context->inspection->reachable) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), [...$issues, new DoctorIssueData(
                RouteDoctorIssueCode::NodeUnreachable,
                DoctorIssueKind::Unverifiable,
                'route',
                DoctorResources::nodeCheckId($context->node->id),
                null,
                'Custom proxy Route state cannot be inspected because the node is unreachable.',
                'reachable',
                'unreachable',
            )], observedChecks: $observedChecks);
        }

        $observedResources = [];
        foreach ($proxies as $proxy) {
            $route = $proxy->route;

            if (! $route instanceof Route) {
                $issues[] = $this->failure($proxy, null);

                continue;
            }

            try {
                $observation = $this->inspector->inspect($proxy);
                $observedResources[] = $route->id;
            } catch (DoctorInspectionException) {
                $issues[] = $this->failure($proxy, $route->domain);

                continue;
            }

            $issues = [...$issues, ...$this->issuesFor($proxy, $route, $observation)];
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::Route, $routes->count(), $issues, $observedResources, $observedChecks);
    }

    /**
     * The Routes this Node serves: Node-scoped Routes on the Node, and Cluster-scoped Routes of every Cluster
     * whose Router role the Node holds.
     *
     * @return Collection<int, Route>
     */
    private function servedRoutes(Node $node): Collection
    {
        $clusters = NodeRole::query()
            ->where('node_id', $node->id)
            ->where('role', RoleName::Router)
            ->where('status', LifecycleStatus::Active)
            ->whereNotNull('cluster_id')
            ->pluck('cluster_id');

        return Route::query()
            ->where(static fn (Builder $query): Builder => $query
                ->where('node_id', $node->id)
                ->orWhereIn('cluster_id', $clusters))
            ->orderBy('id')
            ->get();
    }

    /**
     * Every Route is expected to be active. This reads only stored state, so it also runs when the Node is
     * unreachable.
     *
     * @param  Collection<int, Route>  $routes
     * @return list<DoctorIssueData>
     */
    private function lifecycleIssues(Collection $routes): array
    {
        return array_values($routes
            ->reject(static fn (Route $route): bool => $route->status === RouteStatus::Active)
            ->map(static fn (Route $route): DoctorIssueData => new DoctorIssueData(
                RouteDoctorIssueCode::LifecycleNotActive,
                DoctorIssueKind::Drift,
                'route',
                $route->id,
                $route->domain,
                "Route [{$route->domain}] lifecycle is not active.",
                RouteStatus::Active->value,
                $route->status->value,
            ))
            ->all());
    }

    /**
     * No Route may hold a reserved name. A Route that took a name before core or an extension reserved it keeps it
     * until an operator moves it. This reads only stored state, so it also runs when the Node is unreachable.
     *
     * @param  Collection<int, Route>  $routes
     * @return list<DoctorIssueData>
     */
    private function reservedIssues(Collection $routes): array
    {
        $reserved = $this->reservedHostnames->names();

        return array_values($routes
            ->filter(static fn (Route $route): bool => in_array($route->domain, $reserved, true))
            ->map(static fn (Route $route): DoctorIssueData => new DoctorIssueData(
                RouteDoctorIssueCode::DomainReserved,
                DoctorIssueKind::Drift,
                'route',
                $route->id,
                $route->domain,
                "Route [{$route->domain}] holds a reserved name.",
                'unreserved',
                'reserved',
            ))
            ->all());
    }

    /** @return list<DoctorIssueData> */
    private function issuesFor(
        RouteCustomProxy $proxy,
        Route $route,
        CustomProxyRouteObservation $observation,
    ): array {
        $issues = [];

        foreach ([
            [$observation->dnsMatches, RouteDoctorIssueCode::DnsMismatch, 'DNS', 'match', 'mismatch'],
            [$observation->certificateMatches, RouteDoctorIssueCode::CertificateMismatch, 'certificate', 'present', 'absent'],
            [$observation->caddyMatches, RouteDoctorIssueCode::CaddyMismatch, 'Caddy site', 'present', 'absent'],
            [$observation->upstreamReachable, RouteDoctorIssueCode::UpstreamUnreachable, 'upstream', 'reachable', 'unreachable'],
        ] as [$matches, $code, $label, $expected, $observed]) {
            if ($matches === true) {
                continue;
            }

            if ($matches === null) {
                $issues[] = $this->failure($proxy, $route->domain);

                continue;
            }

            $issues[] = new DoctorIssueData(
                $code,
                DoctorIssueKind::Drift,
                'route',
                $proxy->route_id,
                $route->domain,
                "Custom proxy Route [{$route->domain}] {$label} does not match managed intent.",
                $expected,
                $observed,
            );
        }

        return $issues;
    }

    private function failure(RouteCustomProxy $proxy, ?string $domain): DoctorIssueData
    {
        return new DoctorIssueData(
            RouteDoctorIssueCode::InspectionFailed,
            DoctorIssueKind::Unverifiable,
            'route',
            $proxy->route_id,
            $domain,
            $domain === null
                ? 'Custom proxy Route inspection could not be verified.'
                : "Custom proxy Route [{$domain}] could not be inspected.",
            'verifiable',
            'unverifiable',
        );
    }
}
