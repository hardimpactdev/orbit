<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Firewall\RouterLanIngressPolicy;
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
 */
final readonly class NativeClusterRouterPublicationInput
{
    public function __construct(private DevelopmentSiteRepository $sites, private NativeRoutePublicationInput $publications,
        private NativeRouteCertificateInput $certificates, private NativeCaddyPublicationInput $connections) {}

    /** @param array<string,mixed> $inventory
     * @return array{cluster_router:array<string,mixed>,route_mutations:array<string,mixed>,certificates:list<array<string,mixed>>,participant_node_ids:list<int>}
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
        $forwardRetire = [];
        $restoreRetire = [];
        $lan = app(NativeRouteLanInput::class);
        $routerIds = array_column(RouteExecutionProof::objects($inventory['old_nodes']), 'node_id');
        foreach ($rows as $row) {
            $node = Node::query()->findOrFail(ValidatedData::integer($row['node_id']));
            $oldRouter = in_array($node->id, $routerIds, true) ? $this->routerTuples($node) : [];
            $newRouter = $candidate?->node_id === $node->id ? $this->routerTuples($node) : [];
            $previous = $this->tuples($lan->forSites($node, $before), $oldRouter);
            $desired = $this->tuples($lan->forSites($node, $after), $newRouter, $oldRouter);
            $union = $this->tuples($previous, $lan->forSites($node, $prepared), $desired);
            if ($union === [] && ! $lan->migrationPending($node->id)) {
                continue;
            }
            $forwardLan[] = ['node_id' => $node->id, 'rules' => $lan->changes($previous, $union), 'migrate' => $lan->migrationPending($node->id)];
            $restoreLan[] = ['node_id' => $node->id, 'rules' => $lan->changes($desired, $union), 'migrate' => $lan->migrationPending($node->id)];
            $forwardRetire[] = ['node_id' => $node->id, 'rules' => $lan->changes($union, $desired), 'migrate' => false];
            $restoreRetire[] = ['node_id' => $node->id, 'rules' => $lan->changes($union, $previous), 'migrate' => false];
        }
        if ($candidate !== null) {
            $inventory['candidate']['firewall'] = ['node_id' => $candidate->node_id,
                'rules' => $lan->changes([], $this->tuples($lan->forSites($candidate->node, $prepared), $this->routerTuples($candidate->node))),
                'migrate' => true, 'legacy_router' => true];
        }
        $oldNodes = [];
        foreach (RouteExecutionProof::objects($inventory['old_nodes']) as $old) {
            $node = Node::query()->findOrFail(ValidatedData::integer($old['node_id']));
            $survivors = $lan->forSites($node, $after);
            $old['firewall'] = ['node_id' => $node->id,
                'rules' => $lan->changes($this->tuples($lan->forSites($node, $prepared), $this->routerTuples($node)), $survivors),
                'migrate' => true, 'legacy_router' => true];
            $oldNodes[] = $old;
        }
        $inventory['old_nodes'] = $oldNodes;

        $compact = app(NativeRoutePublicationArtifacts::class)->capture($rows);
        $rows = $compact['publications'];

        return ['cluster_router' => $inventory, 'route_mutations' => ['schema_version' => 2, 'artifacts' => $compact['artifacts'],
            'forward' => $this->stage($forwardLeaves, $forwardLan, $after, $routes, $rows, $forwardRetire, $restoreLeaves, true),
            'restore' => $this->stage($restoreLeaves, $restoreLan, $before, $routes, $rows, $restoreRetire, $forwardLeaves)],
            'certificates' => [...$this->changedLeaves($forwardLeaves, $restoreLeaves), ...$this->changedLeaves($restoreLeaves, $forwardLeaves)], 'participant_node_ids' => $participantIds];
    }

    /** @return list<array{port:int,protocol:string,source:string}> */
    private function routerTuples(Node $node): array
    {
        $tuples = [];
        foreach (app(RouterLanIngressPolicy::class)->eligibleSources($node) as $source) {
            foreach (RouterLanIngressPolicy::Families as $family) {
                $tuples[] = ['port' => (int) $family['port'], 'protocol' => $family['protocol'], 'source' => (string) $source->lan_ip];
            }
        }

        return $tuples;
    }

    /** @param list<array{port:int,protocol:string,source:string}> ...$groups
     * @return list<array{port:int,protocol:string,source:string}>
     */
    private function tuples(array ...$groups): array
    {
        return array_values(array_unique(array_merge(...$groups), SORT_REGULAR));
    }

    /** @param list<array<string,mixed>> $leaves
     * @param  list<array<string,mixed>>  $lan
     * @param  Collection<int,DevelopmentSite>  $sites
     * @param  Collection<int,Route>  $routes
     * @param  list<array<string,mixed>>  $rows
     * @param  list<array<string,mixed>>  $retire
     * @param  list<array<string,mixed>>  $previousLeaves
     * @return array<string,mixed>
     */
    private function stage(array $leaves, array $lan, Collection $sites, Collection $routes, array $rows, array $retire, array $previousLeaves, bool $forward = false): array
    {
        return ['routes' => $routes->map(static fn (Route $route): array => ['route_id' => $route->id, 'domain' => $route->domain,
            'node_ids' => $sites->where('domain', $route->domain)->pluck('nodeId')->unique()->values()->all(),
            'certificate_scopes' => array_values(array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope'])),
                array_filter($leaves, static fn (array $leaf): bool => $leaf['domain'] === $route->domain)))])->all(), 'publications' => $rows, 'certificates' => $forward ? [] : $this->changedLeaves($leaves, $previousLeaves),
            'prepare_certificates' => $forward ? $this->changedLeaves($leaves, $previousLeaves) : [], 'url_writes' => [], 'lan' => array_values(array_unique($lan, SORT_REGULAR)),
            'retire_lan' => $retire, 'cleanup' => $this->cleanup($previousLeaves, $leaves)];
    }

    /** @param list<array<string,mixed>> $desired
     * @param  list<array<string,mixed>>  $previous
     * @return list<array<string,mixed>>
     */
    private function changedLeaves(array $desired, array $previous): array
    {
        $identity = fn (array $leaf): array => array_intersect_key($leaf,
            array_flip(['node_id', 'scope', 'domain', 'root_hash', 'agent_sha256', 'user']));
        $retained = array_map($identity, $previous);

        return array_values(array_filter($desired, fn (array $leaf): bool => ! in_array($identity($leaf), $retained, true)));
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
