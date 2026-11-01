<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Route-owned transfer intent. The Instance parent owns files, placement and its final result. */
final readonly class NativeRouteTransferPlan
{
    /** Reserve the destination Route inside the parent's admission transaction.
     * @return array<string,mixed>
     */
    public function capture(InstanceTransfer $transfer): array
    {
        return DB::transaction(function () use ($transfer): array {
            $transfer = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $instance = Instance::query()->with(['node', 'project'])->lockForUpdate()->findOrFail($transfer->instance_id);
            $source = Route::query()->with(['targets', 'cluster.routerAssignment.node'])->lockForUpdate()->findOrFail($transfer->source_route_id);
            $destination = Node::query()->with('cluster.routerAssignment.node')->findOrFail($transfer->destination_node_id);
            if ($transfer->cutover_at !== null || $instance->node_id !== $transfer->source_node_id
                || $source->targets->pluck('instance_id')->all() !== [$instance->id]
                || $source->project_id !== $instance->project_id || $source->cluster?->routerAssignment?->node_id !== $transfer->source_router_node_id) {
                throw $this->conflict();
            }
            $placement = app(RouteStateResolver::class)->forNode($destination);
            $router = $destination->cluster?->routerAssignment?->node;
            if (! $router instanceof Node) {
                throw new ResourceOperationException('cluster.router_required', 'Transfer requires the destination Router.', 409);
            }
            $routes = Route::query()->with('targets')->whereHas('targets', fn ($query) => $query->where('instance_id', $instance->id))
                ->where(fn ($query) => $query->whereKey($source->id)->orWhereNotNull('web_root'))->orderBy('id')->lockForUpdate()->get();
            foreach ($routes as $route) {
                if ($route->targets->pluck('instance_id')->all() !== [$instance->id] || $route->hasPlacementTransition()
                    || ($route->id !== $source->id && ($route->status !== RouteStatus::Active || $route->replaces_route_id !== null || $route->replaced_by_route_id !== null))
                    || ($route->publication === RoutePublication::Public && $route->cluster_id !== $placement->clusterId)) {
                    throw $this->conflict();
                }
            }
            $before = $routes->map(fn (Route $route): array => $this->routeRow($route))->all();
            $candidate = $transfer->destination_route_id === null ? null : Route::query()->findOrFail($transfer->destination_route_id);
            if ($candidate === null) {
                if ($source->domain === $transfer->destination_domain) {
                    $candidate = $source;
                } else {
                    if (Route::query()->where('domain', $transfer->destination_domain)->exists()) {
                        throw new ResourceOperationException('route.domain_conflict', 'The destination Route domain is already owned.', 409);
                    }
                    $candidate = Route::query()->create(['project_id' => $source->project_id, 'node_id' => $placement->nodeId,
                        'cluster_id' => $placement->clusterId, 'generation_basis_node_id' => $destination->id, 'domain' => $transfer->destination_domain,
                        'web_root' => $source->web_root, 'provenance' => RouteProvenance::Generated, 'publication' => $source->publication,
                        'status' => RouteStatus::Pending, 'replaces_route_id' => $source->id, 'replacement_step' => RouteReplacementStep::Reserved]);
                    $candidate->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
                    $source->update(['replaced_by_route_id' => $candidate->id]);
                }
                $transfer->update(['destination_route_id' => $candidate->id]);
            }
            if ($candidate->domain !== $transfer->destination_domain || $candidate->project_id !== $instance->project_id
                || $candidate->targets()->pluck('instance_id')->all() !== [$instance->id]) {
                throw $this->conflict();
            }
            $desiredRoutes = $routes->map(fn (Route $route): Route => $route->id === $source->id ? $candidate : $route);
            $leaves = [];
            $prepared = [];
            foreach ($desiredRoutes as $route) {
                $scope = $route->hasWebRoot() ? "route-{$route->id}" : "app-instance-{$instance->id}";
                $prepared[] = ['route_id' => $route->id, 'instance_id' => $instance->id, 'node_id' => $destination->id,
                    'production' => false, 'certificate_scope' => $scope];
                $leaves[] = app(NativeRouteCertificateInput::class)->convergenceInput($destination, $scope, $route->domain);
                if ($router->id !== $destination->id) {
                    $leaves[] = app(NativeRouteCertificateInput::class)->convergenceInput($router, "route-{$route->id}-router", $route->domain);
                }
            }
            $dns = PrivateDnsPublicationNodes::query()->orderBy('id')->pluck('id')->all();
            $nodes = array_values(array_unique(ValidatedData::integerList([$transfer->source_node_id, $destination->id, $transfer->source_router_node_id, $router->id, ...$dns, 209])));
            sort($nodes);
            $retire = [];
            foreach ([$instance->node, Node::query()->findOrFail($transfer->source_router_node_id)] as $node) {
                $scopes = $node->id === $instance->node_id ? ["app-instance-{$instance->id}", ...$routes->filter(fn (Route $route): bool => $route->hasWebRoot())->map(fn (Route $route): string => "route-{$route->id}")->all()] : [];
                if ($node->id === $transfer->source_router_node_id) {
                    $scopes = [...$scopes, ...$routes->map(fn (Route $route): string => "route-{$route->id}-router")->all()];
                }
                $retire[$node->id] = ['node_id' => $node->id, 'user' => $node->user,
                    'agent_sha256' => app(AgentDelivery::class)->pin($node), 'certificate_scopes' => array_values(array_unique($scopes)),
                    'lan_route_ids' => $node->id === $instance->node_id ? $routes->modelKeys() : [], 'shared_php' => $node->id === $instance->node_id,
                    'production_instance_ids' => [], 'url_instance_ids' => [], 'ingress_firewall' => false];
            }

            return ['transfer_id' => $transfer->id, 'instance_id' => $instance->id, 'source_route_id' => $source->id,
                'destination_route_id' => $candidate->id, 'source_node_id' => $instance->node_id, 'destination_node_id' => $destination->id,
                'source_router_node_id' => $transfer->source_router_node_id, 'destination_router_node_id' => $router->id,
                'destination_path' => $transfer->destination_path, 'destination_placement' => ['node_id' => $placement->nodeId, 'cluster_id' => $placement->clusterId],
                'routes' => $before, 'retire_nodes' => array_values($retire), 'participant_node_ids' => $nodes,
                'target' => ['route_id' => $candidate->id, 'mode' => 'targets', 'binding_only' => false, 'noop' => false, 'recover_forward' => false,
                    'prepared' => $prepared, 'workloads' => $prepared, 'staging_certificates' => [], 'live_certificates' => $leaves, 'certificates' => $leaves,
                    'staging_removals' => [], 'node_ids' => $nodes, 'workload_node_ids' => [$destination->id],
                    'router_node_ids' => array_values(array_unique([$router->id, $transfer->source_router_node_id])), 'dns_node_ids' => $dns,
                    'caddy_node_ids' => array_values(array_unique([$instance->node_id, $destination->id, $router->id, $transfer->source_router_node_id])),
                    'vacated_route_ids' => [], 'removals' => [], 'proposal' => ['targets' => [$instance->id], 'dispositions' => []]]];
        });
    }

    /** Compose after the Instance parent's placement write, in the SAME transaction.
     * @param  array<string,mixed>  $snapshot
     */
    public function cutover(array $snapshot): void
    {
        $placement = ValidatedData::object($snapshot['destination_placement']);
        foreach (RouteExecutionProof::objects($snapshot['routes']) as $row) {
            $route = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($row['route_id']));
            if ($route->id === $snapshot['source_route_id'] && $route->id !== $snapshot['destination_route_id']) {
                $route->update(['status' => RouteStatus::Retiring, 'sites_published' => false, 'replacement_step' => RouteReplacementStep::DatabaseCutover]);

                continue;
            }
            $route->update([...$placement, 'sites_published' => true]);
        }
        $destination = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($snapshot['destination_route_id']));
        $destination->update([...$placement, 'sites_published' => true, 'status' => RouteStatus::Active,
            'replacement_step' => $snapshot['source_route_id'] === $destination->id ? null : RouteReplacementStep::Cleanup,
            'generation_basis_node_id' => $destination->provenance === RouteProvenance::Generated ? $snapshot['destination_node_id'] : $destination->generation_basis_node_id,
            'failed_step' => null, 'error_code' => null]);
    }

    /** Only pre-cutover rollback is allowed. A cut-over transfer recovers forward.
     * @param  array<string,mixed>  $snapshot
     */
    public function rollback(array $snapshot): void
    {
        $instance = Instance::query()->findOrFail(ValidatedData::integer($snapshot['instance_id']));
        if ($instance->node_id !== $snapshot['source_node_id']) {
            throw $this->conflict();
        }
        if ($snapshot['source_route_id'] !== $snapshot['destination_route_id']) {
            Route::query()->whereKey($snapshot['source_route_id'])->update(['replaced_by_route_id' => null]);
            Route::query()->whereKey($snapshot['destination_route_id'])->delete();
        }
    }

    /** Apply after survivor publications and source effects. Retains all moved web-root Route IDs.
     * @param  array<string,mixed>  $snapshot
     */
    public function retire(array $snapshot): void
    {
        if ($snapshot['source_route_id'] !== $snapshot['destination_route_id']) {
            Route::query()->whereKey($snapshot['destination_route_id'])->update(['replaces_route_id' => null, 'replacement_step' => null]);
            Route::query()->whereKey($snapshot['source_route_id'])->delete();
        }
    }

    /** @return array<string,mixed> */
    private function routeRow(Route $route): array
    {
        return ['route_id' => $route->id, 'domain' => $route->domain, 'web_root' => $route->web_root,
            'node_id' => $route->node_id, 'cluster_id' => $route->cluster_id, 'publication' => $route->publication->value];
    }

    private function conflict(): ResourceOperationException
    {
        return new ResourceOperationException('instance.transfer_route_conflict', 'The complete admitted Route transfer placement changed.', 409);
    }
}
