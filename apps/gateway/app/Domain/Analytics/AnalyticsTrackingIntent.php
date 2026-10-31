<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Actions\Analytics\ShowInstanceAnalyticsAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * Stored Route intent and observed-result projection. No host execution.
 *
 * @phpstan-type Leaf array{node_id:int,user:string,agent_sha256:string,route_id:int,domain:string,scope:string,version?:string,root_hash?:string}
 * @phpstan-type Plan array{publish:list<int>,remove:list<int>,snapshot:list<array<string,mixed>>,certificates:list<Leaf>,cleanup:list<Leaf>,caddy_node_ids:list<int>,dns_node_ids:list<int>}
 */
final readonly class AnalyticsTrackingIntent
{
    public function __construct(private ShowInstanceAnalyticsAction $show, private IngressSiteRepository $sites,
        private AgentDelivery $agents, private SettingRepository $settings, private LeafCertificateSigner $signer) {}

    /** @return array<string,mixed> */
    public function identity(Route $route): array
    {
        $route->loadMissing(['cluster.routerAssignment', 'cluster.ingressAssignment', 'transitionCluster.routerAssignment', 'transitionCluster.ingressAssignment']);

        return ['id' => $route->id, 'domain' => $route->domain, 'node_id' => $route->node_id,
            'cluster_id' => $route->cluster_id, 'publication' => $route->publication->value,
            'router_assignment_id' => $route->cluster?->routerAssignment?->id, 'router_node_id' => $route->cluster?->routerAssignment?->node_id,
            'ingress_assignment_id' => $route->cluster?->ingressAssignment?->id, 'ingress_node_id' => $route->cluster?->ingressAssignment?->node_id,
            'transition_node_id' => $route->transition_node_id, 'transition_cluster_id' => $route->transition_cluster_id,
            'transition_router_id' => $route->transitionCluster?->routerAssignment?->node_id,
            'transition_router_assignment_id' => $route->transitionCluster?->routerAssignment?->id,
            'transition_ingress_assignment_id' => $route->transitionCluster?->ingressAssignment?->id,
            'transition_ingress_id' => $route->transitionCluster?->ingressAssignment?->node_id,
            'transition_dns_moved_at' => $route->transition_dns_moved_at?->toIso8601String()];
    }

    /** @return list<array<string,mixed>> */
    public function snapshot(Instance $instance): array
    {
        return array_values($this->show->trackingRoutes($instance)->map(fn (Route $route): array => $this->identity($route))->all());
    }

    /** @return Plan */
    public function materialize(Operation $operation): array
    {
        $instance = $this->instance($operation);
        if ($this->snapshot($instance) !== $operation->input['tracking_snapshot']) {
            $this->changed();
        }
        $owner = $instance->unsetRelation('routes')->authoritativeRoute();
        if ($operation->input['enabled'] && ($owner === null || $this->identity($owner) !== $operation->input['owner'])) {
            $this->changed();
        }
        $old = [];
        foreach ($this->show->trackingRoutes($instance) as $route) {
            $old = [...$old, ...$this->certificates($route)];
        }
        $desired = ValidatedData::stringList($operation->input['hosts']);
        $publish = [];
        $remove = [];
        $current = $this->show->trackingRoutes($instance)->keyBy('domain');
        foreach ($desired as $domain) {
            if (! $owner instanceof Route) {
                $this->changed();
            }
            $route = $current->get($domain);
            if (! $route instanceof Route) {
                if (Route::query()->where('domain', $domain)->exists()) {
                    $this->changed();
                }
                $route = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'domain' => $domain,
                    'node_id' => $owner->node_id, 'cluster_id' => $owner->cluster_id, 'publication' => $owner->publication,
                    'provenance' => RouteProvenance::Explicit, 'status' => RouteStatus::Pending]);
                $route->analyticsTracking()->create(['instance_id' => $instance->id]);
            }
            $route->update(['node_id' => $owner->node_id, 'cluster_id' => $owner->cluster_id, 'publication' => $owner->publication,
                'transition_node_id' => null, 'transition_cluster_id' => null, 'transition_dns_moved_at' => null]);
            $route->unsetRelations();
            $publish[] = $route->id;
        }
        foreach ($current as $route) {
            if (! in_array($route->domain, $desired, true)) {
                $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false, 'replacement_step' => null]);
                $remove[] = $route->id;
            }
        }
        $certificates = [];
        foreach (Route::query()->whereIn('id', $publish)->orderBy('id')->get() as $route) {
            $certificates = [...$certificates, ...$this->certificates($route)];
        }
        $key = static fn (array $leaf): string => $leaf['node_id'].':'.$leaf['scope'];
        $newKeys = array_map($key, $certificates);
        $cleanup = array_values(array_filter($old, static fn (array $leaf): bool => ! in_array($key($leaf), $newKeys, true)));
        $nodes = array_values(array_unique(array_column([...$old, ...$certificates], 'node_id')));
        sort($nodes);
        $root = $certificates === [] ? '' : $this->signer->rootCertificate();

        return ['publish' => $publish, 'remove' => $remove, 'snapshot' => $this->snapshot($instance),
            'certificates' => array_map(static fn (array $leaf): array => [...$leaf,
                'version' => substr(hash('sha256', $root.':'.$leaf['domain'].':'.$leaf['scope']), 0, 16), 'root_hash' => hash('sha256', $root)], $certificates),
            'cleanup' => $cleanup, 'caddy_node_ids' => $nodes, 'dns_node_ids' => $nodes === [] ? [] : ValidatedData::integerList($operation->input['dns_node_ids'])];
    }

    public function assertCurrent(Operation $operation): void
    {
        $instance = $this->instance($operation);
        $owner = $instance->unsetRelation('routes')->authoritativeRoute();
        if (($operation->input['enabled'] && ($owner === null || $this->identity($owner) !== $operation->input['owner']))
            || $this->snapshot($instance) !== $this->plan($operation)['snapshot']) {
            $this->changed();
        }
    }

    public function publish(Operation $operation): void
    {
        $this->assertCurrent($operation);
        foreach (Route::query()->whereIn('id', $this->plan($operation)['publish'])->get() as $route) {
            $route->update(['status' => RouteStatus::Activating, 'sites_published' => true,
                'replacement_step' => $route->publication === RoutePublication::Public ? RouteReplacementStep::PublicActivated : null]);
        }
    }

    public function complete(Operation $operation): void
    {
        $this->assertCurrent($operation);
        $scope = new SettingScope(SettingScopeType::Gateway);
        $key = 'analytics.site.'.ValidatedData::integer($operation->input['instance_id']);
        if ($this->settings->get($scope, $key) !== $operation->input['previous_binding']) {
            $this->changed();
        }
        foreach (Route::query()->whereIn('id', $this->plan($operation)['remove'])->get() as $route) {
            if ($route->status !== RouteStatus::Retiring || $route->sites_published) {
                $this->changed();
            }
            $route->delete();
        }
        foreach (Route::query()->whereIn('id', $this->plan($operation)['publish'])->get() as $route) {
            $route->update(['status' => RouteStatus::Active, 'failed_step' => null, 'error_code' => null,
                'replacement_step' => $route->publication === RoutePublication::Public ? RouteReplacementStep::PublicActivated : null]);
        }
        if ($operation->input['enabled']) {
            $this->settings->put($scope, $key, json_encode($operation->input['site'], JSON_THROW_ON_ERROR));
        } else {
            $this->settings->delete($scope, $key);
        }
    }

    /** @return list<Leaf> */
    private function certificates(Route $route): array
    {
        $placements = [$route];
        if ($route->hasPlacementTransition()) {
            $prior = clone $route;
            $prior->node_id = $route->transition_node_id;
            $prior->cluster_id = $route->transition_cluster_id;
            $prior->unsetRelations();
            $placements[] = $prior;
        }
        $result = [];
        foreach ($placements as $placement) {
            $hosts = ['router' => $this->sites->routerNode($placement)];
            if ($placement->publication === RoutePublication::Public) {
                $hosts['ingress'] = $this->sites->ingressNode($placement);
            }
            foreach ($hosts as $role => $node) {
                $leaf = ['node_id' => $node->id, 'user' => $node->user, 'agent_sha256' => $this->agents->pin($node),
                    'route_id' => $route->id, 'domain' => $route->domain, 'scope' => "route-{$route->id}-{$role}"];
                $result[$node->id.':'.$leaf['scope']] = $leaf;
            }
        }

        return array_values($result);
    }

    /** @return Plan */
    public function plan(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->state['tracking'] ?? []);
        if (array_diff(['publish', 'remove', 'snapshot', 'certificates', 'cleanup', 'caddy_node_ids', 'dns_node_ids'], array_keys($plan)) !== []) {
            throw new ResourceOperationException('operation.intent_not_ready', 'The parent has no observed tracking plan yet.', 409);
        }

        return ['publish' => ValidatedData::integerList($plan['publish']), 'remove' => ValidatedData::integerList($plan['remove']),
            'snapshot' => $this->objects($plan['snapshot']), 'certificates' => array_map($this->leaf(...), $this->objects($plan['certificates'])),
            'cleanup' => array_map($this->leaf(...), $this->objects($plan['cleanup'])),
            'caddy_node_ids' => ValidatedData::integerList($plan['caddy_node_ids']), 'dns_node_ids' => ValidatedData::integerList($plan['dns_node_ids'])];
    }

    /** @return list<array<string,mixed>> */
    private function objects(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->changed();
        }

        return array_map(ValidatedData::object(...), $value);
    }

    /** @param array<string,mixed> $leaf
     * @return Leaf
     */
    private function leaf(array $leaf): array
    {
        return ['node_id' => ValidatedData::integer($leaf['node_id']), 'route_id' => ValidatedData::integer($leaf['route_id']),
            'user' => ValidatedData::string($leaf['user']), 'agent_sha256' => ValidatedData::string($leaf['agent_sha256']),
            'domain' => ValidatedData::string($leaf['domain']), 'scope' => ValidatedData::string($leaf['scope']),
            ...(isset($leaf['version']) ? ['version' => ValidatedData::string($leaf['version']), 'root_hash' => ValidatedData::string($leaf['root_hash'])] : [])];
    }

    private function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        if ($operation->target_type !== 'instance' || $operation->target_id !== $instance->id || $instance->node_id !== $operation->input['instance_node_id']) {
            $this->changed();
        }

        return $instance;
    }

    private function changed(): never
    {
        throw new ResourceOperationException('analytics.tracking_changed', 'The admitted tracking ownership or placement changed.', 409);
    }
}
