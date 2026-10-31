<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Data\Instances\TransferInstanceData;
use App\Domain\AppDev\SsrPortAllocator;
use App\Domain\AppDev\VitePortAllocator;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NativeInstanceTransferIntent;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Routes\NativeRouteTransferPlan;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Schedules\ScheduleTargetUseGuard;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

/** Validates immutable transfer placement and records intent without moving source or runtime. */
final readonly class StartInstanceTransferAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private RouteStateResolver $routeState,
        private ScheduleTargetUseGuard $schedules,
    ) {}

    public function execute(Instance $instance, TransferInstanceData $data, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.transfer')) !== null) {
            return $replay;
        }
        app(DirectOperation::class)->writer('instance.transfer');

        return DB::transaction(function () use ($instance, $data, $caller, $requestId): Operation {
            $instance->loadMissing(['project', 'node', 'routes.targets', 'removalMember']);
            app(NativeInstanceTransferIntent::class)->assertSupported($instance, $data->sqliteSourcePath);
            $existing = $this->existingTransfer($instance, $data);
            $transfer = $existing ?? $this->reserve($instance, $data);
            $destination = Node::query()->findOrFail($transfer->destination_node_id);
            $source = Node::query()->findOrFail($transfer->source_node_id);
            app(VitePortAllocator::class)->assign($instance);
            app(VitePortAllocator::class)->assign($instance, $destination);
            app(SsrPortAllocator::class)->assign($instance);
            app(SsrPortAllocator::class)->assign($instance, $destination);

            $routePlan = app(NativeRouteTransferPlan::class)->capture($transfer);
            $plan = app(NativeInstanceTransferIntent::class)->capture($transfer);

            return $this->operations->execute('instance.transfer', 'instance', $instance->id, $transfer->source_node_id, [
                'direct_transfer' => $plan, 'route_transfer' => $routePlan,
                'participant_node_ids' => $routePlan['participant_node_ids'],
                'environment_fields' => [(string) $source->id => 'source_route_environment', (string) $destination->id => 'destination_route_environment'],
                'private_bindings' => [
                    'source_route_environment' => ['owner_node_id' => $source->id, 'read_node_ids' => [$source->id], 'write_node_ids' => []],
                    'destination_route_environment' => ['owner_node_id' => $destination->id, 'read_node_ids' => [$destination->id], 'write_node_ids' => []],
                ],
                'source_route_environment' => ['$secret' => 'instance.transfer_route_environment', 'id' => $instance->id],
                'destination_route_environment' => ['$secret' => 'instance.transfer_route_environment', 'id' => $instance->id],
                'instance_id' => $instance->id, 'project_id' => $instance->project_id, 'transfer_id' => $transfer->id,
                'created' => $existing === null, 'node_id' => $transfer->source_node_id,
                'destination_node_id' => $transfer->destination_node_id, 'destination_path' => $transfer->destination_path,
                'destination_name' => $transfer->destination_name, 'destination_domain' => $transfer->destination_domain,
                'source_router_node_id' => $transfer->source_router_node_id, 'source_route_id' => $transfer->source_route_id,
                'source_cluster_id' => $source->cluster_id,
                'source_path' => $transfer->source_path, 'source_layout' => $transfer->source_layout->value,
                'common_repository_path' => $transfer->common_repository_path, 'sqlite_source_path' => $transfer->sqlite_source_path,
                'repository' => $instance->project->repository_url, 'repository_identity' => $instance->project->repository_identity,
                'initial_step' => $transfer->current_step->value, 'initial_cutover' => $transfer->cutover_at !== null,
                'initial_completed' => $transfer->completed_at !== null,
            ], $caller, $requestId);
        });
    }

    private function existingTransfer(Instance $instance, TransferInstanceData $data): ?InstanceTransfer
    {
        $existing = InstanceTransfer::query()
            ->where('instance_id', $instance->id)
            ->open()
            ->orderByDesc('created_at')
            ->first();

        if (! $existing instanceof InstanceTransfer) {
            $completed = InstanceTransfer::query()
                ->where('instance_id', $instance->id)
                ->whereNotNull('completed_at')
                ->orderByDesc('completed_at')
                ->first();

            if (
                $completed instanceof InstanceTransfer
                && $this->sameRequest($completed, $instance, $data)
            ) {
                return $completed;
            }

            return null;
        }

        if (! $this->sameRequest($existing, $instance, $data)) {
            throw $this->conflict(
                'instance.transfer_retry_conflict',
                'Only the identical transfer request can resume this Instance.',
            );
        }

        if (($existing->recovery_evidence['rollback_pending'] ?? false) === true) {
            throw $this->conflict('instance.transfer_recovery_pending', 'The recorded transfer recovery must finish before another attempt can start.');
        }

        return $existing;
    }

    private function sameRequest(
        InstanceTransfer $transfer,
        Instance $instance,
        TransferInstanceData $data,
    ): bool {
        return $transfer->destination_node_id === $data->nodeId
            && $transfer->requested_name === $data->name
            && $transfer->sqlite_source_path === $data->sqliteSourcePath
            && $transfer->instance_id === $instance->id;
    }

    private function reserve(Instance $instance, TransferInstanceData $data): InstanceTransfer
    {
        $this->schedules->assertInstanceStable($instance);
        [$destination, $path, $domain, $route] = $this->preflight($instance, $data);

        return InstanceTransfer::query()->create([
            'instance_id' => $instance->id,
            'source_node_id' => $instance->node_id,
            'source_router_node_id' => $this->sourceRouterId($route),
            'destination_node_id' => $destination->id,
            'requested_name' => $data->name,
            'destination_name' => $data->name ?? $instance->name,
            'destination_path' => $path->value,
            'destination_domain' => $domain,
            'sqlite_source_path' => $data->sqliteSourcePath,
            'source_layout' => $instance->source_layout,
            'source_path' => $instance->checkout_path,
            'common_repository_path' => $instance->registration_common_repository_path,
            'source_route_id' => $route->id,
            'status' => InstanceTransferStatus::Reserved,
            'current_step' => InstanceTransferStep::Reserved,
        ]);
    }

    /** @return array{Node, StoragePath, string, Route} */
    private function preflight(Instance $instance, TransferInstanceData $data): array
    {
        $instance->refresh()->loadMissing(['project', 'node', 'routes.targets']);
        $this->assertEligibleSource($instance);
        $destination = Node::query()->findOrFail($data->nodeId);
        $this->assertEligibleDestination($instance, $destination);
        $name = $data->name ?? $instance->name;
        $this->assertDestinationIdentity($instance, $name);
        $path = $this->destinationPath($destination, $instance->project->slug, $name);
        $this->assertDestinationAvailable($destination, $path);
        $route = $this->authoritativeRoute($instance);
        $placement = $this->routeState->forNode($destination);

        if ($placement->clusterId !== null) {
            $this->routeState->assertRouter($placement->clusterId);
        }

        $domain = $this->destinationDomain($instance, $route, $name, $placement);
        $this->assertDestinationDomain($instance, $route, $domain);

        return [$destination, $path, $domain, $route];
    }

    private function assertEligibleSource(Instance $instance): void
    {
        if ($instance->status !== InstanceState::Active || $instance->provisioning_step !== 'active') {
            throw $this->conflict('instance.lifecycle_conflict', 'The Instance is not active for transfer.');
        }

        if (! $instance->placedOnAppDev()) {
            throw $this->conflict(
                'instance.production_refused',
                'Transfer accepts only an active development Instance.',
            );
        }

        if ($instance->removalMember !== null) {
            throw $this->conflict('instance.removal_conflict', 'The Instance is being removed.');
        }

        $this->assertActiveAppDevCluster($instance->node, 'source');
    }

    private function assertEligibleDestination(Instance $instance, Node $destination): void
    {
        if ($destination->id === $instance->node_id) {
            throw $this->conflict('instance.same_node', 'Transfer requires a distinct destination Node.');
        }

        $this->assertActiveAppDevCluster($destination, 'destination');
        $instance->loadMissing('project');
        app(DevelopmentNodeExclusion::class)->assertAvailable($instance->project, $destination);
    }

    private function assertActiveAppDevCluster(Node $node, string $role): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw $this->conflict('instance.node_inactive', "The {$role} Node is not an active Linux Node.");
        }

        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw $this->conflict('instance.node_not_app_dev', "The {$role} Node has no active app-dev role.");
        }

        $cluster = $node->cluster_id === null ? null : Cluster::query()->find($node->cluster_id);

        if (! $cluster instanceof Cluster || $cluster->state !== ClusterState::Active) {
            throw $this->conflict(
                'instance.standalone_unsupported',
                "The {$role} Node must belong to an active Cluster.",
            );
        }
    }

    private function assertDestinationIdentity(Instance $instance, string $name): void
    {
        $taken = Instance::query()
            ->where('project_id', $instance->project_id)
            ->where('name', $name)
            ->whereKeyNot($instance->id)
            ->exists();

        if ($taken) {
            throw $this->conflict(
                'instance.identity_conflict',
                "Instance name [{$name}] is already owned. Retry with a different name.",
            );
        }
    }

    private function destinationPath(Node $destination, string $projectSlug, string $name): StoragePath
    {
        $root = data_get($destination->settings, 'apps.path');
        if (! is_string($root) || StoragePath::tryParse($root) === null) {
            throw $this->conflict('operation.kind_unavailable', 'Native transfer requires a stored absolute apps path.');
        }

        return StoragePath::parse($root)->append($projectSlug, $name);
    }

    private function assertDestinationAvailable(Node $destination, StoragePath $path): void
    {
        $this->checkoutOverlap->assertAvailable($destination->id, $path, 'instance.destination_exists');
    }

    private function authoritativeRoute(Instance $instance): Route
    {
        $route = $instance->authoritativeRoute();

        if (! $route instanceof Route) {
            throw $this->conflict('instance.lifecycle_conflict', 'The Instance has no authoritative Route.');
        }

        if ($route->replaced_by_route_id !== null || $route->replaces_route_id !== null) {
            throw $this->conflict(
                'instance.lifecycle_conflict',
                'Transfer cannot start while a Route domain change is incomplete.',
            );
        }

        return $route;
    }

    private function destinationDomain(
        Instance $instance,
        Route $route,
        string $name,
        RoutePlacement $placement,
    ): string {
        if ($route->provenance === RouteProvenance::Explicit) {
            return $route->domain;
        }

        return $this->routeState->generatedDomain($instance->project->slug, $name, $placement->effectiveTld);
    }

    private function assertDestinationDomain(Instance $instance, Route $route, string $domain): void
    {
        $taken = Route::query()
            ->where('domain', $domain)
            ->whereKeyNot($route->id)
            ->exists();

        if ($taken) {
            throw $this->conflict('route.domain_conflict', "Route domain [{$domain}] is already owned.");
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

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
