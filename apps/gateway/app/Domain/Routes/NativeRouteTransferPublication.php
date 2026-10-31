<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Full public before/after capture, before any physical cutover or terminal database projection. */
final readonly class NativeRouteTransferPublication
{
    public function __construct(private DevelopmentSiteRepository $sites, private NativeRoutePublicationInput $publications) {}

    /** The parent holds its admission transaction and records this immutable result as run input.
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $destinationInstallations
     * @return array{schema_version:int,nodes:list<array<string,mixed>>}
     */
    public function capture(array $plan, array $destinationInstallations): array
    {
        $instance = Instance::query()->with(['node', 'project'])->findOrFail(ValidatedData::integer($plan['instance_id']));
        $destination = Node::query()->with('cluster.routerAssignment.node')->findOrFail(ValidatedData::integer($plan['destination_node_id']));
        if ($instance->node_id !== $plan['source_node_id']) {
            throw new ResourceOperationException('route.transfer_conflict', 'Transfer publication capture requires the admitted source placement.', 409);
        }
        $target = ValidatedData::object($destinationInstallations['target']);
        $identity = ValidatedData::object($target['instance']);
        if (($destinationInstallations['instance_id'] ?? null) !== $instance->id
            || ($destinationInstallations['node_id'] ?? null) !== $destination->id
            || ($identity['checkout_path'] ?? null) !== $plan['destination_path']
            || array_diff(['checkout_path', 'root', 'source_is_laravel', 'vite_port', 'ssr_port'], array_keys($identity)) !== []) {
            throw new ResourceOperationException('route.transfer_conflict', 'Transfer publication requires the canonical admitted destination Process identity.', 409);
        }
        $moving = clone $instance;
        $moving->forceFill(Arr::only($identity, ['checkout_path', 'root', 'source_is_laravel', 'vite_port', 'ssr_port']));
        $moving->node_id = $destination->id;
        $moving->setRelation('node', $destination);
        $routes = Route::query()->with(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node', 'transitionCluster.routerAssignment.node', 'transitionNode'])
            ->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))->orderBy('id')->get();
        $before = $this->sites->all();
        $domains = $routes->pluck('domain')->all();
        $after = $before->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
        $movedIds = array_column(RouteExecutionProof::objects($plan['routes']), 'route_id');
        $placement = ValidatedData::object($plan['destination_placement']);
        foreach ($routes as $stored) {
            if ($stored->id === $plan['source_route_id'] && $stored->id !== $plan['destination_route_id']) {
                continue;
            }
            $route = clone $stored;
            $route->setRelation('targets', $stored->targets->map(static function ($row) use ($moving) {
                $target = clone $row;
                if ($target->instance_id === $moving->id) {
                    $target->setRelation('instance', $moving);
                }

                return $target;
            }));
            if (in_array($route->id, $movedIds, true) || $route->id === $plan['destination_route_id']) {
                $route->node_id = $placement['node_id'] === null ? null : ValidatedData::integer($placement['node_id']);
                $route->cluster_id = $placement['cluster_id'] === null ? null : ValidatedData::integer($placement['cluster_id']);
                $route->setRelation('cluster', $destination->cluster);
                $route->setRelation('node', $route->node_id === null ? null : $destination);
                $route->status = RouteStatus::Active;
                $route->sites_published = true;
                $route->replacement_step = null;
                $route->replaces_route_id = null;
                $route->replaced_by_route_id = null;
            } elseif (! $before->contains(static fn (DevelopmentSite $site): bool => $site->domain === $route->domain)) {
                continue;
            }
            $after->push(...$this->sites->projectSites($route));
        }
        $target = ValidatedData::object($plan['target']);
        $nodeIds = array_values(array_unique([...ValidatedData::integerList($target['caddy_node_ids']),
            ...ValidatedData::integerList($target['dns_node_ids']),
            ...$before->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->map(static fn (DevelopmentSite $site): int => $site->nodeId)->all(),
            ...$after->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->map(static fn (DevelopmentSite $site): int => $site->nodeId)->all()]));
        sort($nodeIds);
        $nodes = [];
        foreach ($nodeIds as $nodeId) {
            $node = Node::query()->findOrFail($nodeId);
            $nodes[] = ['node_id' => $node->id, 'user' => $node->user,
                ...app(NativeCaddyPublicationInput::class)->connectionForNode($node),
                'before' => $this->publications->snapshot($node, $before), 'after' => $this->publications->snapshot($node, $after)];
        }

        return ['schema_version' => 1, 'nodes' => $nodes];
    }
}
