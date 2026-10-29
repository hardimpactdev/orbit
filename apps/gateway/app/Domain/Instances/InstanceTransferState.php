<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/** Recorded transfer placement and DB-only cutover. Swamp drives all Node effects. */
final readonly class InstanceTransferState
{
    public function __construct(private RouteStateResolver $routeState) {}

    public function transfer(Operation $operation): InstanceTransfer
    {
        $transfer = InstanceTransfer::query()->findOrFail(ValidatedData::string($operation->input['transfer_id']));
        if ($operation->target_type !== 'instance' || $operation->target_id !== $transfer->instance_id
            || $operation->input['instance_id'] !== $transfer->instance_id
            || (in_array($operation->kind, ['instance.transfer', 'instance.transfer_rollback'], true) && $operation->node_id !== $transfer->source_node_id)
            || (in_array($operation->kind, ['instance.transfer_destination', 'instance.transfer_activate'], true) && $operation->node_id !== $transfer->destination_node_id)) {
            throw $this->conflict('operation.execution_forbidden', 'The transfer placement does not belong to this Operation.');
        }
        foreach (['source_path', 'destination_path', 'destination_name', 'destination_domain', 'destination_node_id'] as $field) {
            if (array_key_exists($field, $operation->input) && $operation->input[$field] !== $transfer->getAttribute($field)) {
                throw $this->conflict('instance.transfer_retry_conflict', 'The admitted transfer placement changed.');
            }
        }

        return $transfer;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function transition(Operation $operation, array $input): array
    {
        $transfer = $this->transfer($operation);
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($transfer->instance_id);

        return match ($input['action']) {
            'transfer-cutover' => $this->cutover($operation, $instance, $transfer),
            'transfer-finish' => $this->finish($operation, $instance, $transfer),
            'transfer-rollback-state' => $this->rollback($operation, $instance, $transfer),
            default => throw $this->conflict('operation.execution_forbidden', 'Unknown transfer state transition.'),
        };
    }

    /** @return array<string, mixed> */
    private function cutover(Operation $operation, Instance $instance, InstanceTransfer $transfer): array
    {
        return DB::transaction(function () use ($operation, $instance, $transfer): array {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $transfer->refresh();
            if ($operation->cancel_requested_at !== null && $transfer->cutover_at === null) {
                throw $this->conflict('operation.cancelled', 'Cancellation won before the transfer cutover commit.');
            }
            $execution = app(InstanceLifecycleExecution::class);
            $execution->assertChild($operation, 'transfer-destination', 'instance.transfer_destination');
            if ($transfer->cutover_at !== null) {
                return ['cutover' => true, 'transfer_id' => $transfer->id];
            }
            if ($instance->node_id !== $transfer->source_node_id || $instance->checkout_path !== $transfer->source_path) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'The source placement changed before cutover.');
            }
            $destination = Node::query()->findOrFail($transfer->destination_node_id);
            app(ProcessAdmissionLock::class)->run([$instance->id], function () use ($instance, $destination, $transfer, $operation): void {
                app(ScheduleTargetUseGuard::class)->assertInstanceStable($instance->refresh());
                $this->prepareRoute($instance, $destination, $transfer);
                $this->cutoverPlacement($instance, $destination, $transfer, ValidatedData::integer($operation->input['source_cluster_id']));
            });

            return ['cutover' => true, 'transfer_id' => $transfer->id];
        });
    }

    /** @return array<string, mixed> */
    private function finish(Operation $operation, Instance $instance, InstanceTransfer $transfer): array
    {
        if ($transfer->completed_at !== null) {
            if ($transfer->status !== InstanceTransferStatus::Completed || $transfer->current_step !== InstanceTransferStep::Completed
                || $transfer->cutover_at === null || $instance->node_id !== $transfer->destination_node_id
                || $instance->checkout_path !== $transfer->destination_path) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'The completed transfer placement changed.');
            }

            return ['transfer_id' => $transfer->id, 'completed' => true];
        }
        $execution = app(InstanceLifecycleExecution::class);
        $execution->assertChild($operation, 'transfer-retire-source', 'route.retire_source');
        $execution->proven($operation, 'transfer-cleanup-source');
        DB::transaction(function () use ($instance, $transfer): void {
            [$lockedTransfer, $sourceRoute, $destinationRoute] = $this->lockCleanupRoutes($instance, $transfer);
            if ($destinationRoute->id !== $sourceRoute->id) {
                $sourceRoute->delete();
                $destinationRoute->update(['replacement_step' => null, 'replaces_route_id' => null,
                    'replaced_by_route_id' => null, 'failed_step' => null, 'error_code' => null]);
            }
            $source = Node::query()->findOrFail($lockedTransfer->source_node_id);
            app(VitePortAllocator::class)->release($instance, $source);
            app(SsrPortAllocator::class)->release($instance, $source);
            $lockedTransfer->update(['status' => InstanceTransferStatus::Completed, 'current_step' => InstanceTransferStep::Completed,
                'completed_at' => now(), 'recovery_evidence' => null, 'failed_step' => null, 'error_code' => null]);
        });

        return ['transfer_id' => $transfer->id, 'completed' => true];
    }

    /** @return array<string, mixed> */
    private function rollback(Operation $operation, Instance $instance, InstanceTransfer $transfer): array
    {
        if ($transfer->cutover_at !== null || $instance->node_id !== $transfer->source_node_id) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'A cut-over transfer only recovers forward.');
        }
        $execution = app(InstanceLifecycleExecution::class);
        if ($operation->kind !== 'instance.transfer_rollback') {
            throw $this->conflict('operation.execution_forbidden', 'Rollback state belongs to the admitted compensation child.');
        }
        $execution->assertChild($operation, 'transfer-restore-source', 'process.instance_runtime');
        $execution->assertChild($operation, 'transfer-discard-destination', 'instance.transfer_discard');
        DB::transaction(function () use ($instance, $transfer): void {
            $locked = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            InstanceEnvironmentValue::query()->where('instance_id', $instance->id)
                ->whereIn('env_key', $locked->imported_environment_keys ?? [])->delete();
            if ($locked->destination_route_id !== null && $locked->destination_route_id !== $locked->source_route_id) {
                $route = Route::query()->lockForUpdate()->find($locked->destination_route_id);
                if ($route !== null && $route->status === RouteStatus::Active) {
                    throw $this->conflict('instance.transfer_cleanup_conflict', 'The destination Route became active before rollback.');
                }
                $route?->delete();
                Route::query()->whereKey($locked->source_route_id)->where('replaced_by_route_id', $locked->destination_route_id)
                    ->update(['replaced_by_route_id' => null]);
            }
            $destination = Node::query()->findOrFail($locked->destination_node_id);
            app(VitePortAllocator::class)->release($instance, $destination);
            app(SsrPortAllocator::class)->release($instance, $destination);
            $locked->update(['imported_environment_keys' => [], 'destination_route_id' => null,
                'current_step' => InstanceTransferStep::Reserved, 'status' => InstanceTransferStatus::Failed, 'recovery_evidence' => null]);
        });

        return ['transfer_id' => $transfer->id, 'restored' => true];
    }

    public function prepareRoute(Instance $instance, Node $destination, InstanceTransfer $transfer): void
    {
        $route = Route::query()->findOrFail($transfer->source_route_id);
        $placement = $this->routeState->forNode($destination);

        if ($route->domain === $transfer->destination_domain) {
            $transfer->update(['destination_route_id' => $route->id]);
            $transfer->refresh();

            return;
        }

        $replacement = Route::query()->create([
            'project_id' => $instance->project_id,
            'node_id' => $placement->nodeId,
            'cluster_id' => $placement->clusterId,
            'generation_basis_node_id' => $destination->id,
            'domain' => $transfer->destination_domain,
            'web_root' => $route->web_root,
            'provenance' => RouteProvenance::Generated,
            'publication' => $route->publication,
            'status' => RouteStatus::Pending,
            'replaces_route_id' => $route->id,
            'replacement_step' => RouteReplacementStep::Reserved,
        ]);
        $replacement->targets()->create([
            'instance_id' => $instance->id,
            'position' => 0,
        ]);
        $route->update(['replaced_by_route_id' => $replacement->id]);
        $transfer->update(['destination_route_id' => $replacement->id]);
        $transfer->refresh();
    }

    private function cutoverPlacement(Instance $instance, Node $destination, InstanceTransfer $transfer, int $sourceClusterId): void
    {
        DB::transaction(function () use ($instance, $destination, $transfer, $sourceClusterId): void {
            $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            Node::query()->whereKey($destination->id)->lockForUpdate()->firstOrFail();
            $lockedTransfer = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $sourceRoute = Route::query()->lockForUpdate()->findOrFail($lockedTransfer->source_route_id);
            $destinationRoute = Route::query()->lockForUpdate()->findOrFail(
                $lockedTransfer->destination_route_id ?? $lockedTransfer->source_route_id,
            );
            $placement = $this->routeState->forNode($destination);

            if ($sourceRoute->cluster_id !== $sourceClusterId) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'The source Route placement changed before cutover.');
            }
            $webRootRoutes = $this->webRootRoutes(
                $lockedInstance->id,
                array_values(array_unique([$sourceRoute->id, $destinationRoute->id])),
                lock: true,
            );
            $this->assertTransferableWebRootRoutes($webRootRoutes, $lockedInstance->id, $placement->clusterId);
            $lockedTransfer->update(['source_router_node_id' => $this->sourceRouterId($sourceRoute)]);

            $lockedInstance->update([
                'node_id' => $destination->id,
                'vite_port' => StoredInteger::fromOrZero(DB::table('vite_port_assignments')->where('instance_id', $instance->id)->where('node_id', $destination->id)->value('port')),
                'ssr_port' => $this->destinationSsrPort($instance, $destination),
                'name' => $lockedTransfer->destination_name,
                'checkout_path' => $lockedTransfer->destination_path,
                'source_layout' => InstanceSourceLayout::Checkout,
            ]);

            if ($destinationRoute->id === $sourceRoute->id) {
                $destinationRoute->update([
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                    'generation_basis_node_id' => $sourceRoute->provenance === RouteProvenance::Generated
                        ? $destination->id
                        : $sourceRoute->generation_basis_node_id,
                ]);
            } else {
                $sourceRoute->update([
                    'status' => RouteStatus::Retiring,
                    'replacement_step' => RouteReplacementStep::DatabaseCutover,
                ]);
                $destinationRoute->update([
                    'status' => RouteStatus::Active,
                    'failed_step' => null,
                    'error_code' => null,
                    'replacement_step' => RouteReplacementStep::DatabaseCutover,
                ]);
            }

            // Another site of the Instance has an explicit domain, so it keeps its ID and domain and moves
            // with the Instance. Source cleanup retires the leaves of every recorded Route with a web root,
            // the transfer's own Route included when it has one.
            foreach ($webRootRoutes as $webRootRoute) {
                $webRootRoute->update([
                    'node_id' => $placement->nodeId,
                    'cluster_id' => $placement->clusterId,
                ]);
            }

            $movedWebRootRouteIds = [
                ...($sourceRoute->hasWebRoot() ? [$sourceRoute->id] : []),
                ...$webRootRoutes->modelKeys(),
            ];
            $lockedTransfer->update([
                'status' => InstanceTransferStatus::InProgress,
                'current_step' => InstanceTransferStep::Cutover,
                // A transfer that moves no Route with a web root records nothing, as before.
                'web_root_route_ids' => $movedWebRootRouteIds === [] ? null : $movedWebRootRouteIds,
                'cutover_at' => now(),
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        $transfer->refresh();
    }

    /** @param list<int> $exceptRouteIds
     * @return EloquentCollection<int, Route>
     */
    private function webRootRoutes(int $instanceId, array $exceptRouteIds, bool $lock = false): EloquentCollection
    {
        $query = Route::query()
            ->whereNotNull('web_root')
            ->whereKeyNot($exceptRouteIds)
            ->whereHas('targets', static fn ($targets) => $targets->where('instance_id', $instanceId))
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate()->with(['targets' => static fn ($targets) => $targets->lockForUpdate()]);
        } else {
            $query->with('targets');
        }

        return $query->get();
    }

    /**
     * Routes with a web root move with the Instance at cutover and keep their IDs and domains. Each must
     * be an active, settled site of this Instance alone. Transfer does not move a public edge, so a
     * public one cannot change Cluster.
     *
     * @param  EloquentCollection<int, Route>  $routes
     */
    private function assertTransferableWebRootRoutes(EloquentCollection $routes, int $instanceId, ?int $destinationClusterId): void
    {
        foreach ($routes as $route) {
            if (
                $route->status !== RouteStatus::Active
                || $route->targets->pluck('instance_id')->all() !== [$instanceId]
                || $route->replaces_route_id !== null
                || $route->replaced_by_route_id !== null
                || $route->hasPlacementTransition()
            ) {
                throw $this->conflict(
                    'instance.lifecycle_conflict',
                    "Route [{$route->domain}] with a web root is not ready for transfer.",
                );
            }

            if ($route->publication === RoutePublication::Public && $route->cluster_id !== $destinationClusterId) {
                throw $this->conflict(
                    'instance.transfer_public_web_root_route',
                    "Public Route [{$route->domain}] with a web root cannot move to another Cluster. Make it private, transfer, and publish it again.",
                );
            }
        }
    }

    private function sourceRouterId(Route $route): int
    {
        $route->load('cluster.routerAssignment.node');
        $router = $route->cluster?->routerAssignment?->node;
        if (! $router instanceof Node) {
            throw $this->conflict('instance.transfer_source_router_unknown', 'The source Route requires its original Router.');
        }

        return $router->id;
    }

    /** @return array{InstanceTransfer, Route, Route} */
    private function lockCleanupRoutes(Instance $instance, InstanceTransfer $transfer): array
    {
        $lockedInstance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
        $lockedTransfer = InstanceTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
        $sourceRoute = Route::query()->lockForUpdate()->find($lockedTransfer->source_route_id);
        $destinationRoute = Route::query()->lockForUpdate()->find($lockedTransfer->destination_route_id);
        if (
            $lockedTransfer->instance_id !== $lockedInstance->id
            || $lockedTransfer->cutover_at === null
            || $lockedTransfer->completed_at !== null
            || $lockedTransfer->current_step !== InstanceTransferStep::DestinationActivated
            || $lockedInstance->node_id !== $lockedTransfer->destination_node_id
            || $lockedInstance->checkout_path !== $lockedTransfer->destination_path
            || ! $sourceRoute instanceof Route
            || ! $destinationRoute instanceof Route
            || $destinationRoute->status !== RouteStatus::Active
            || $destinationRoute->domain !== $lockedTransfer->destination_domain
        ) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer placement changed before source cleanup.');
        }

        foreach ([$sourceRoute, $destinationRoute] as $route) {
            $targets = $route->targets()->lockForUpdate()->pluck('instance_id')->all();
            if ($route->project_id !== $lockedInstance->project_id || $targets !== [$lockedInstance->id]) {
                throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer Route ownership changed before source cleanup.');
            }
        }

        $replaced = $sourceRoute->id !== $destinationRoute->id;
        if (
            $sourceRoute->replaced_by_route_id !== ($replaced ? $destinationRoute->id : null)
            || $destinationRoute->replaces_route_id !== ($replaced ? $sourceRoute->id : null)
            || $sourceRoute->replaces_route_id !== null
            || $destinationRoute->replaced_by_route_id !== null
            || $sourceRoute->status !== ($replaced ? RouteStatus::Retiring : RouteStatus::Active)
            || $sourceRoute->replacement_step !== ($replaced ? RouteReplacementStep::DatabaseCutover : null)
            || $destinationRoute->replacement_step !== ($replaced ? RouteReplacementStep::DatabaseCutover : null)
        ) {
            throw $this->conflict('instance.transfer_cleanup_conflict', 'Transfer Route lifecycle changed before source cleanup.');
        }

        return [$lockedTransfer, $sourceRoute, $destinationRoute];
    }

    private function destinationSsrPort(Instance $instance, Node $destination): ?int
    {
        $port = DB::table('ssr_port_assignments')->where('instance_id', $instance->id)->where('node_id', $destination->id)->value('port');

        return $port === null ? null : StoredInteger::from($port);
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
