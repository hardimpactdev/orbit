<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Collection;

/** Capture Router publication phases without selecting a Router or changing Route rows.
 * @phpstan-type LanRule array{node_id:int,route_id:int,operation:'converge',router:string,destination:string}
 */
final readonly class NativeClusterRouterPublicationInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private NativeRoutePublicationInput $publications,
        private NativeRouteCertificateInput $certificates, private NativeCaddyPublicationInput $connections) {}

    /** @param array<string,mixed> $inventory
     * @return array{route_mutations:array<string,mixed>,certificates:list<array<string,mixed>>,participant_node_ids:list<int>}
     */
    public function capture(Operation $operation, array $inventory): array
    {
        $clusterId = ValidatedData::integer($inventory['cluster_id']);
        $candidate = is_array($inventory['candidate'])
            ? NodeRole::query()->with('node')->findOrFail(ValidatedData::integer($inventory['candidate']['assignment_id'])) : null;
        $routes = Route::query()->with(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node',
            'cluster.ingressAssignment.node', 'transitionCluster.routerAssignment.node', 'transitionNode', 'node', 'customProxy', 'analyticsTracking'])
            ->where('cluster_id', $clusterId)->where(static fn ($query) => $query->where('sites_published', true)
            ->orWhere(static fn ($query) => $query->where('status', RouteStatus::Pending)->whereNotNull('replaces_route_id')))
            ->orderBy('id')->get();
        $storedCluster = Cluster::query()->with(['routerAssignment.node', 'ingressAssignment.node'])->findOrFail($clusterId);
        $domains = ValidatedData::stringList($routes->pluck('domain')->all());
        $before = $this->sites->all();
        $unaffected = $before->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
        $after = clone $unaffected;
        foreach ($routes as $route) {
            $selected = clone $route;
            $cluster = clone $storedCluster;
            $assignment = $candidate === null ? null : clone $candidate;
            $assignment?->forceFill(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
            $cluster->setRelation('routerAssignment', $assignment);
            $selected->setRelation('cluster', $cluster);
            foreach ($this->sites->routeSites($selected) as $site) {
                if ($site->secondary && $site->scope === "route-{$route->id}-router") {
                    continue;
                }
                $after->push($site);
            }
        }
        $prepared = $before->keyBy(static fn (DevelopmentSite $site): string => $site->addressKey());
        foreach ($after as $site) {
            $prepared->put($site->addressKey(), $site);
        }
        $prepared = $prepared->values();
        $targetNodeIds = array_values(array_unique(ValidatedData::integerList([ValidatedData::integer($operation->node_id),
            ...$before->whereIn('domain', $domains)->pluck('nodeId')->all(), ...$after->whereIn('domain', $domains)->pluck('nodeId')->all(),
            ...array_column(RouteExecutionProof::objects($inventory['old_nodes']), 'node_id'),
            ...($candidate === null ? [] : [$candidate->node_id]), ...ValidatedData::integerList($inventory['dns_node_ids'])])));
        $participantIds = array_values(array_unique([209, ...$targetNodeIds]));
        sort($participantIds);
        $rows = [];
        foreach (Node::query()->whereIn('id', $targetNodeIds)->orderBy('id')->get() as $node) {
            $serves = $before->where('nodeId', $node->id)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))
                || $after->where('nodeId', $node->id)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true));
            $rows[] = ['node_id' => $node->id, 'user' => $node->user, 'serves_routes' => $serves,
                ...$this->connections->connectionForNode($node),
                'before' => $this->snapshot($node, $before, $clusterId, null, false),
                'prepared' => $this->snapshot($node, $prepared, $clusterId, $candidate?->node_id),
                'withdrawn' => $this->snapshot($node, $after, $clusterId, $candidate?->node_id),
                'after' => $this->snapshot($node, $after, $clusterId, $candidate?->node_id)];
        }
        $forwardLeaves = $this->leaves($after, $domains);
        $restoreLeaves = $this->leaves($before, $domains);
        $forwardLan = [];
        $restoreLan = [];
        foreach (RouteExecutionProof::objects($inventory['workloads']) as $workload) {
            if (is_array($workload['firewall'] ?? null)) {
                $rule = $workload['firewall'];
                $forwardLan[] = ['node_id' => ValidatedData::integer($workload['node_id']), 'route_id' => ValidatedData::integer($rule['route_id']), 'operation' => 'converge',
                    'router' => ValidatedData::string($rule['router']), 'destination' => ValidatedData::string($rule['workload'])];
            }
            foreach (RouteExecutionProof::objects(ValidatedData::object($workload['retire_firewall'] ?? [])['remove'] ?? []) as $rule) {
                $restoreLan[] = ['node_id' => ValidatedData::integer($workload['node_id']), 'route_id' => ValidatedData::integer($workload['route_id']), 'operation' => 'converge',
                    'router' => ValidatedData::string($rule['source']), 'destination' => ValidatedData::string($rule['destination'])];
            }
        }
        $allLan = array_values(array_unique([...$forwardLan, ...$restoreLan], SORT_REGULAR));

        return ['route_mutations' => [
            'forward' => $this->stage($forwardLeaves, $forwardLan, $after, $routes, $rows, $allLan, $restoreLeaves),
            'restore' => $this->stage($restoreLeaves, $restoreLan, $before, $routes, $rows, $allLan, $forwardLeaves)],
            'certificates' => array_values(array_unique([...$forwardLeaves, ...$restoreLeaves], SORT_REGULAR)), 'participant_node_ids' => $participantIds];
    }

    /** @param list<array<string,mixed>> $leaves
     * @param  list<LanRule>  $lan
     * @param  Collection<int,DevelopmentSite>  $sites
     * @param  Collection<int,Route>  $routes
     * @param  list<array<string,mixed>>  $rows
     * @param  list<LanRule>  $allLan
     * @param  list<array<string,mixed>>  $previousLeaves
     * @return array<string,mixed>
     */
    private function stage(array $leaves, array $lan, Collection $sites, Collection $routes, array $rows, array $allLan, array $previousLeaves): array
    {
        $retire = [];
        foreach ($allLan as $rule) {
            $key = $rule['node_id'].':'.$rule['route_id'].':'.$rule['destination'];
            $keep = array_values(array_unique(array_column(array_filter($lan, static fn (array $kept): bool => $kept['node_id'] === $rule['node_id']
                && $kept['route_id'] === $rule['route_id'] && $kept['destination'] === $rule['destination']), 'router')));
            $retire[$key] = array_intersect_key($rule, array_flip(['node_id', 'route_id', 'destination'])) + ['operation' => 'retire', 'keep' => $keep];
        }

        return ['routes' => $routes->map(static fn (Route $route): array => ['route_id' => $route->id, 'domain' => $route->domain,
            'node_ids' => $sites->where('domain', $route->domain)->pluck('nodeId')->unique()->values()->all(),
            'certificate_scopes' => array_values(array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope'])),
                array_filter($leaves, static fn (array $leaf): bool => $leaf['domain'] === $route->domain)))])->all(), 'publications' => $rows, 'certificates' => $leaves,
            'prepare_certificates' => $leaves, 'url_writes' => [], 'lan' => array_values(array_unique($lan, SORT_REGULAR)),
            'retire_lan' => array_values($retire), 'cleanup' => $this->cleanup($previousLeaves, $leaves)];
    }

    /** @param Collection<int,DevelopmentSite> $sites
     * @return array<string,mixed>
     */
    private function snapshot(Node $node, Collection $sites, int $clusterId, ?int $routerId, bool $override = true): array
    {
        return app(RoutePublicationSites::class)->render($sites, function () use ($node, $sites, $clusterId, $routerId, $override): array {
            $snapshot = $this->publications->snapshot($node, $sites);
            if ($override && $snapshot['dns'] !== null) {
                $snapshot['dns'] = $this->publications->dns($node, clusterOverrides: [$clusterId => ['router_node_id' => $routerId]]);
            }

            return $snapshot;
        });
    }

    /** @param Collection<int,DevelopmentSite> $sites
     * @param  list<string>  $domains
     * @return list<array<string,mixed>>
     */
    private function leaves(Collection $sites, array $domains): array
    {
        $leaves = [];
        foreach ($sites as $site) {
            if ($site->publicListener || ! in_array($site->domain, $domains, true)) {
                continue;
            }
            $scope = $site->certificateScope ?? $site->scope;
            $leaves[$site->nodeId.':'.$scope] ??= $this->certificates->convergenceInput(Node::query()->findOrFail($site->nodeId), $scope, $site->domain);
        }

        return array_values($leaves);
    }

    /** @param list<array<string,mixed>> $previous
     * @param  list<array<string,mixed>>  $kept
     * @return list<array<string,mixed>>
     */
    private function cleanup(array $previous, array $kept): array
    {
        $retained = array_map($this->leafKey(...), $kept);

        return array_values(array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'user', 'scope', 'agent_sha256'])) + ['in_use' => false],
            array_filter($previous, fn (array $leaf): bool => ! in_array($this->leafKey($leaf), $retained, true))));
    }

    /** @param array<string,mixed> $leaf */
    private function leafKey(array $leaf): string
    {
        return ValidatedData::integer($leaf['node_id']).':'.ValidatedData::string($leaf['scope']);
    }
}
