<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceDestroyKind;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\ProtectedPathCatalog;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteTarget;
use App\Models\WebSocketAppTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Record stored removal intent. The placed native run inspects source and account facts. */
final readonly class StartInstanceDestroyAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private CheckoutRemovalBoundary $boundaries,
    ) {}

    public function execute(Instance $instance, bool $force, ?string $caller = null, ?string $requestId = null): Operation
    {
        $kind = InstanceDestroyKind::forInstance($instance);
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        $snapshot = $this->snapshot($instance, $force);

        return DB::transaction(function () use ($instance, $snapshot, $caller, $requestId, $kind): Operation {
            if (($replay = $this->idempotency->replay($kind)) !== null) {
                return $replay;
            }
            $input = app(DirectInstanceDestroyIntent::class)->record($snapshot);

            return $this->operations->execute($kind, 'instance', $instance->id, $instance->node_id, $input, $caller, $requestId);
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(Instance $instance, bool $force, bool $routesOwnedByParent = false): array
    {
        if (! $instance->placedOnAppDev() || DatabaseConnection::query()->where('owner_instance_id', $instance->id)->whereNotNull('database_server_id')->exists()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This removal placement has no native destroy DAG.', 503);
        }
        $instance->load($this->removalRelations());
        $retained = InstanceRemovalMember::query()->where('instance_id', $instance->id)->whereNull('row_deleted_at')->with('removal.members')->first();
        if ($instance->status === InstanceState::Removing) {
            if (! $retained instanceof InstanceRemovalMember || $retained->removal->requested_instance_id !== $instance->id) {
                $this->conflict($instance);
            }
            $removal = $retained->removal;
            if ($removal->force !== $force && (! $force || $removal->force || $removal->status !== InstanceRemovalStatus::Failed)) {
                $this->conflict($instance);
            }
            if ($removal->native_intent !== null) {
                return [...$removal->native_intent, 'force' => $force];
            }
            $this->conflict($instance);
        }
        $this->assertSupported($instance);
        $this->assertMemberPathAvailable($instance);
        $members = collect([$instance]);
        $snapshots = [];
        $additional = [];
        foreach ($members as $position => $member) {
            $route = $routesOwnedByParent ? $member->routes->sortBy('id')->first() : $this->route($member);
            foreach ($member->routes as $candidate) {
                if ($route === null || $candidate->id !== $route->id) {
                    $additional[] = $candidate->id;
                }
            }
            $snapshots[] = [
                'position' => $position, 'instance_id' => $member->id, 'project_id' => $member->project_id, 'node_id' => $member->node_id,
                'route_id' => $route?->id, 'route_node_id' => $this->routeNode($route), 'name' => $member->name, 'environment' => $member->defaultAppEnv(),
                'source_layout' => $member->source_layout, 'repository_identity' => $member->project->repository_identity,
                'checkout_path' => $member->checkout_path, 'root' => $member->effectiveRoot(),
                'branch' => $member->branch, 'starting_commit' => $member->starting_commit, 'source_commit' => $member->starting_commit ?? '',
                'common_repository_path' => dirname((string) $member->checkout_path), 'source_identity' => 'pending',
                'linked_worktree_paths' => [], 'source_digest' => str_repeat('0', 64),
                'runtime_published' => $member->status === InstanceState::Active,
                'source_root' => $this->boundaries->instanceGroupingDirectory($member)->parent()?->value,
                'user' => $member->node->user, 'group' => null,
                'run_teardown' => ! $this->failedCreation($member),
                'teardown_digest' => $this->teardownDigest($member->project),
                'worktree_inventory' => null, 'origin' => null,
                'source_prepare_id' => $member->source_prepare_id, 'registration_request_id' => $member->registration_request_id,
                'stored_status' => $member->status->value,
                'failed_creation' => $this->failedCreation($member),
                'row_deleted_at' => null,
            ];
        }

        return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'name' => $instance->name, 'force' => $force, 'removal_id' => (string) Str::uuid(),
            'inventory_digest' => hash('sha256', json_encode($snapshots, JSON_THROW_ON_ERROR)),
            'source_pending' => true,
            'protected_source_roots' => app(ProtectedPathCatalog::class)->roots(),
            'repository' => $instance->project->repository_url, 'repository_identity' => $instance->project->repository_identity,
            'members' => $snapshots, 'additional_route_ids' => $additional, 'additional_routes' => array_map(fn (int $id): array => ['route_id' => $id, 'node_id' => $this->routeNode(Route::query()->findOrFail($id))], $additional),
        ];
    }

    private function teardownDigest(Project $project): string
    {
        return hash('sha256', json_encode(app(ProjectLifecycleStepStore::class)->ordered($project, LifecyclePhase::Teardown), JSON_THROW_ON_ERROR));
    }

    private function routeNode(?Route $route): ?int
    {
        return $route->node_id ?? $route?->cluster?->routerAssignment?->node_id;
    }

    private function assertSupported(Instance $instance): void
    {
        if (InstanceTransfer::query()
            ->where('instance_id', $instance->id)
            ->open()
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: 'instance.transfer_incomplete',
                message: "Instance [{$instance->name}] has an incomplete or failed transfer that must be recovered before removal.",
                status: 409,
            );
        }

        if (Instance::query()
            ->where('clone_candidate_id', $instance->id)
            ->whereNull('clone_completed_at')
            ->exists()) {
            throw new ResourceOperationException(
                errorCode: 'instance.clone_in_progress',
                message: "Instance [{$instance->name}] is the candidate for an incomplete clone.",
                status: 409,
            );
        }

        if (WebSocketAppTarget::query()->where('instance_id', $instance->id)->whereNotNull('route_id')->exists()) {
            throw new ResourceOperationException('websocket.route_in_use', 'Remove the public Reverb attachment before removing this Instance.', 409);
        }

        if (RouteAnalyticsTracking::query()->where('instance_id', $instance->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'analytics.tracking_hosts_exist',
                message: "Instance [{$instance->name}] still publishes a tracking host. Disable its analytics first.",
                status: 409,
            );
        }

        if (! $this->removableState($instance)) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] is not active.",
                status: 409,
            );
        }

        if (! $instance->placedOnAppDev() && ! $instance->placedOnAppProd()) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The Instance environment cannot be removed.',
                status: 409,
            );
        }

        if (
            $instance->placedOnAppDev()
            && ! in_array(
                $instance->source_layout,
                [InstanceSourceLayout::Checkout->value, InstanceSourceLayout::Worktree->value],
                true,
            )
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'Instance source layout is not removable.',
                status: 409,
            );
        }
    }

    private function assertMemberPathAvailable(Instance $member): void
    {
        $path = StoragePath::tryParse($member->checkout_path);

        if (! $path instanceof StoragePath) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_unsafe',
                message: "Instance [{$member->name}] has an unsafe checkout path.",
                status: 409,
            );
        }

        $this->checkoutOverlap->assertAvailable(
            $member->node_id,
            $path,
            'instance.checkout_path_unsafe',
            ignoreInstanceId: $member->id,
        );
    }

    private function route(Instance $instance): ?Route
    {
        if ($this->withoutRoute($instance)) {
            return null;
        }

        $routes = $this->ownRoutes($instance);

        if ($routes->count() !== 1) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] does not have one removable Route.",
                status: 409,
            );
        }

        $route = $routes->sole();

        if (
            ! $this->removableRouteState($route, $instance)
            || $route->project_id !== $instance->project_id
            || $route->targets->count() !== 1
            || $route->targets->sole()->instance_id !== $instance->id
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: "Instance [{$instance->name}] Route is not safe to remove.",
                status: 409,
            );
        }

        return $route;
    }

    /** @return Collection<int, Route> */
    private function ownRoutes(Instance $instance): Collection
    {
        return $instance->routes
            ->reject(fn (Route $route): bool => $route->hasWebRoot() || $this->ownUnactivatedReplacement($route, $instance))
            ->values();
    }

    private function withoutRoute(Instance $instance): bool
    {
        return $this->ownRoutes($instance)->isEmpty()
            && (! $instance->requiresRoute() || $instance->status === InstanceState::SourceResolved || $this->failedCreation($instance));
    }

    private function removableRouteState(Route $route, Instance $instance): bool
    {
        if ($this->sourceResolvedWorkspace($instance) && ! $this->failedCreation($instance)) {
            return in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed], true)
                && $route->project_id === $instance->project_id
                && $this->routePlacedOnInstance($route, $instance);
        }

        return $route->status === RouteStatus::Active
            || ($this->failedCreation($instance)
                && in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed], true)
                && $this->routePlacedOnInstance($route, $instance)
                && $route->project_id === $instance->project_id);
    }

    private function routePlacedOnInstance(Route $route, Instance $instance): bool
    {
        return $route->node_id === $instance->node_id
            || ($route->node_id === null && $route->cluster_id !== null
                && $route->cluster_id === $instance->node->cluster_id);
    }

    private function failedCreation(Instance $instance): bool
    {
        return InstanceCreationRecovery::isPreActivation($instance);
    }

    private function removableState(Instance $instance): bool
    {
        if ($instance->status === InstanceState::Active || $this->failedCreation($instance)) {
            return true;
        }

        if (! $this->sourceResolvedWorkspace($instance)) {
            return false;
        }

        $routes = $instance->routes()->with('targets')->get();
        if ($routes->isEmpty()) {
            return ! RouteTarget::query()->where('instance_id', $instance->id)->exists();
        }

        if ($routes->count() !== 1) {
            return false;
        }

        $route = $routes->sole();

        return $this->removableRouteState($route, $instance)
            && $route->targets->count() === 1
            && $route->targets->sole()->instance_id === $instance->id;
    }

    private function sourceResolvedWorkspace(Instance $instance): bool
    {
        return $instance->placedOnAppDev()
            && $instance->status === InstanceState::SourceResolved;
    }

    private function ownUnactivatedReplacement(Route $route, Instance $instance): bool
    {
        return ! $route->hasWebRoot()
            && $route->isUnactivatedReplacement()
            && $route->targets->count() === 1
            && $route->targets->sole()->instance_id === $instance->id;
    }

    private function conflict(Instance $instance): never
    {
        throw new ResourceOperationException(
            errorCode: 'instance.removal_conflict',
            message: "Instance [{$instance->name}] belongs to a different removal request.",
            status: 409,
        );
    }

    /** @return list<string> */
    private function removalRelations(): array
    {
        return [
            'project',
            'node',
            'routes.targets.instance.node.roles',
            'routes.cluster.routerAssignment.node',
        ];
    }
}
