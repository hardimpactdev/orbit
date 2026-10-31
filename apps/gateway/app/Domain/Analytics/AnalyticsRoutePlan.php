<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Actions\Analytics\ShowInstanceAnalyticsAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * Public tracking-host intent reusable by Analytics and Route parent admission.
 *
 * @phpstan-import-type Leaf from AnalyticsTrackingIntent
 *
 * @phpstan-type Plan array{route_id:int,certificates:list<Leaf>,cleanup:list<Leaf>,caddy_node_ids:list<int>,dns_node_ids:list<int>,router_node_ids:list<int>,ingress_node_ids:list<int>,public_verifications:list<array<string,mixed>>}
 */
final readonly class AnalyticsRoutePlan
{
    public function __construct(private AgentDelivery $agents, private LeafCertificateSigner $signer, private IngressSiteRepository $sites, private ShowInstanceAnalyticsAction $show) {}

    /** @return Plan */
    public function capture(Route $route, bool $removing = false): array
    {
        if (! $route->isAnalyticsTracking()) {
            throw new ResourceOperationException('route.kind_unsupported', 'A tracking host requires an analytics Route.', 409);
        }
        $host = $this->sites->routerNode($route);
        $current = $this->leaf($route);
        $certificates = [];
        if (! $removing) {
            $root = $this->signer->rootCertificate();
            $certificates[] = [...$current, 'version' => substr(hash('sha256', $root.':'.$route->domain.':'.$current['scope']), 0, 16),
                'root_hash' => hash('sha256', $root)];
        }
        $cleanup = [];
        $nodes = [];
        $ingress = [];
        foreach (self::placements($route) as $index => $placement) {
            $leaf = $index === 0 ? $current : $this->leaf($placement);
            $nodes[] = $leaf['node_id'];
            if ($removing || $leaf['node_id'] !== $host->id) {
                $cleanup[$leaf['node_id'].':'.$leaf['scope']] = [...$leaf, 'in_use' => false];
            }
            if ($placement->cluster_id !== null && $placement->publication === RoutePublication::Public) {
                $node = $this->sites->ingressNode($placement);
                $nodes[] = $node->id;
                $ingress[] = $node->id;
            }
        }
        $verifications = [];
        if (! $removing && $route->publication === RoutePublication::Public) {
            $node = $this->sites->ingressNode($route);
            $verifications[] = ['node_id' => $node->id, 'verification' => ['protocol' => 'tls',
                'agent_sha256' => $this->agents->pin($node),
                'probe' => ['address' => $this->sites->privateOverride($route)->routerAddress, 'port' => 443, 'domain' => $route->domain]]];
        }
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        return ['route_id' => $route->id, 'certificates' => $certificates, 'cleanup' => array_values($cleanup),
            'caddy_node_ids' => $nodes, 'dns_node_ids' => ValidatedData::integerList(PrivateDnsPublicationNodes::query()->pluck('id')->all()),
            'router_node_ids' => [$host->id], 'ingress_node_ids' => array_values(array_unique($ingress)),
            'public_verifications' => $verifications];
    }

    /**
     * Only the Instance's authoritative app Route owns its published tracking hosts.
     *
     * @return list<Route>
     */
    public function associated(Route $appRoute): array
    {
        if (! $appRoute->isApp()) {
            return [];
        }
        $routes = [];
        foreach ($appRoute->targets()->with('instance.routes')->get() as $target) {
            $instance = $target->instance;
            if ($instance->authoritativeRoute()?->id !== $appRoute->id) {
                continue;
            }
            foreach ($this->show->trackingRoutes($instance) as $tracking) {
                if ($tracking->sites_published && $tracking->status !== RouteStatus::Retiring) {
                    $routes[$tracking->id] = $tracking;
                }
            }
        }
        ksort($routes);

        return array_values($routes);
    }

    /**
     * Tracking hostnames stay literal. A domain-only or target-only app change has no host effect.
     *
     * @return list<array{route_id:int,before:array<string,mixed>,after:array<string,mixed>,publish:Plan,rollback:Plan,node_ids:list<int>}>
     */
    public function captureUpdate(Route $appRoute, Route $candidate): array
    {
        $changes = [];
        foreach ($this->associated($appRoute) as $tracking) {
            if ($tracking->node_id === $candidate->node_id && $tracking->cluster_id === $candidate->cluster_id
                && $tracking->publication === $candidate->publication) {
                continue;
            }
            $next = clone $tracking;
            $next->unsetRelations();
            $moved = $tracking->node_id !== $candidate->node_id || $tracking->cluster_id !== $candidate->cluster_id;
            $next->fill(['node_id' => $candidate->node_id, 'cluster_id' => $candidate->cluster_id,
                'publication' => $candidate->publication, 'status' => RouteStatus::Activating,
                'replacement_step' => null, 'sites_published' => true, 'failed_step' => null, 'error_code' => null,
                ...($moved ? ['transition_node_id' => $tracking->node_id, 'transition_cluster_id' => $tracking->cluster_id,
                    'transition_dns_moved_at' => null] : [])]);
            $publish = $this->capture($next);
            $rollback = $this->capture($tracking);
            $active = array_column($rollback['certificates'], 'scope', 'node_id');
            foreach ($publish['certificates'] as $leaf) {
                if (($active[$leaf['node_id']] ?? null) !== $leaf['scope']) {
                    $rollback['cleanup'][] = [...$leaf, 'in_use' => false];
                }
            }
            $changes[] = ['route_id' => $tracking->id, 'before' => $this->state($tracking), 'after' => $this->state($next),
                'publish' => $publish, 'rollback' => $rollback, 'node_ids' => array_values(array_unique([
                    ...$publish['caddy_node_ids'], ...$rollback['caddy_node_ids'], ...$publish['dns_node_ids'], ...$rollback['dns_node_ids']]))];
        }

        return $changes;
    }

    /** Fresh caller-scoped cleanup after the parent restores and publishes stored state.
     *
     * @param  Plan  $plan
     * @return list<Leaf>
     */
    public function cleanupForNode(array $plan, int $nodeId): array
    {
        $node = Node::query()->findOrFail($nodeId);
        $sites = app(DevelopmentSiteRepository::class)->forNode($node);

        return array_values(array_filter($plan['cleanup'], static fn (array $leaf): bool => $leaf['node_id'] === $nodeId
            && ! $sites->contains(static fn ($site): bool => $site->loadsCertificate($leaf['scope']))));
    }

    /** Apply only the parent's immutable captured state, never an arbitrary result payload.
     *
     * @param  list<array<string,mixed>>  $changes
     */
    public function checkpointUpdate(array $changes, string $checkpoint): void
    {
        if (! in_array($checkpoint, ['certificates_ready', 'public_verified', 'placement_cleanup', 'complete', 'rollback'], true)) {
            throw new ResourceOperationException('analytics.checkpoint_invalid', 'The tracking publication checkpoint is invalid.', 422);
        }
        foreach ($changes as $change) {
            $route = Route::query()->findOrFail(ValidatedData::integer($change['route_id']));
            $after = ValidatedData::object($change['after']);
            if ($checkpoint === 'certificates_ready') {
                $route->update($after);
            } elseif ($checkpoint === 'public_verified' && $after['publication'] === RoutePublication::Public->value) {
                $route->update(['replacement_step' => RouteReplacementStep::PublicActivated]);
            } elseif ($checkpoint === 'placement_cleanup' && $route->hasPlacementTransition()) {
                $route->update(['replacement_step' => RouteReplacementStep::Cleanup]);
            } elseif ($checkpoint === 'complete') {
                $route->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null,
                    'transition_node_id' => null, 'transition_cluster_id' => null, 'transition_dns_moved_at' => null,
                    'replacement_step' => $after['publication'] === RoutePublication::Public->value ? RouteReplacementStep::PublicActivated : null]);
            } elseif ($checkpoint === 'rollback') {
                $route->update(ValidatedData::object($change['before']));
            }
        }
    }

    /** @return array<string,mixed> */
    private function state(Route $route): array
    {
        return ['node_id' => $route->node_id, 'cluster_id' => $route->cluster_id, 'publication' => $route->publication->value,
            'status' => $route->status->value, 'sites_published' => $route->sites_published,
            'replacement_step' => $route->replacement_step?->value,
            'transition_node_id' => $route->transition_node_id, 'transition_cluster_id' => $route->transition_cluster_id,
            'transition_dns_moved_at' => $route->transition_dns_moved_at?->toISOString(),
            'failed_step' => $route->failed_step, 'error_code' => $route->error_code];
    }

    /** @return list<Route> */
    public static function placements(Route $route): array
    {
        $placements = [$route];
        if ($route->hasPlacementTransition()) {
            $prior = clone $route;
            $prior->node_id = $route->transition_node_id;
            $prior->cluster_id = $route->transition_cluster_id;
            $prior->unsetRelations();
            $placements[] = $prior;
        }

        return $placements;
    }

    /** @return Leaf */
    private function leaf(Route $route): array
    {
        $node = $this->sites->routerNode($route);

        return ['node_id' => $node->id, 'user' => $node->user,
            'agent_sha256' => $this->agents->pin($node), 'route_id' => $route->id,
            'domain' => $route->domain, 'scope' => "route-{$route->id}-router"];
    }
}
