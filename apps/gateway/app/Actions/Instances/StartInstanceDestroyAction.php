<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DirectInstanceDestroyIntent;
use App\Domain\Instances\InstanceCreationRecovery;
use App\Domain\Instances\InstanceLifecycleChildren;
use App\Domain\Instances\InstanceLifecycleInput;
use App\Domain\Instances\InstanceLifecycleState;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRouteRemovalAdmission;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionRemovalIntent;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceTransfer;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;
use App\Models\RouteTarget;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admission and read-only source validation for development removal. */
final readonly class StartInstanceDestroyAction
{
    public function __construct(
        private StartOperationAction $operations,
        private OperationIdempotency $idempotency,
        private DevelopmentInstanceSourceRemoval $sourceInspector,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
    ) {}

    public function execute(Instance $instance, bool $force, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.destroy')) !== null) {
            return $replay;
        }
        $snapshot = $this->snapshot($instance, $force, $this->idempotency->parent);

        return DB::transaction(function () use ($instance, $snapshot, $caller, $requestId): Operation {
            $input = app(DirectInstanceDestroyIntent::class)->record($snapshot);

            return $this->operations->execute('instance.destroy', 'instance', $instance->id, $instance->node_id, $input, $caller, $requestId, expectedWriter: 'direct');
        });
    }

    public function rollback(Operation $parent): Operation
    {
        if (! app(InstanceLifecycleChildren::class)->canRemoveFailedCreate($parent)) {
            throw new ResourceOperationException('operation.child_forbidden', 'This create has no proven failure that allows rollback removal.', 422);
        }
        $instance = app(InstanceLifecycleState::class)->instance($parent);
        $input = $this->snapshot($instance, true);
        $members = InstanceLifecycleInput::members($input);
        if (count($members) !== 1 || $members[0]['instance_id'] !== $parent->target_id) {
            throw new ResourceOperationException('instance.cleanup_incomplete', 'A create rollback cannot cascade into another Instance.', 409);
        }
        $input['members'] = array_map(static fn (array $member): array => [...$member, 'run_teardown' => false], $members);

        return DB::transaction(function () use ($input, $instance, $parent): Operation {
            $recorded = app(DirectInstanceDestroyIntent::class)->record($input);

            return $this->operations->execute('instance.destroy', 'instance', $instance->id, $parent->node_id, $recorded, 'instance.create', $parent->request_id, expectedWriter: 'direct');
        });
    }

    /** @return array<string, mixed> */
    public function snapshot(Instance $instance, bool $force, ?Operation $parent = null): array
    {
        $instance->load($this->removalRelations());
        $detached = $parent?->kind === 'route.target_set';
        if ($detached) {
            app(InstanceRouteRemovalAdmission::class)->assertAllowed($parent, $instance, $force);
        }
        if ($instance->placedOnAppProd()) {
            if ($instance->status !== InstanceState::Removing) {
                $this->assertSupported($instance);
            }

            return app(ProductionRemovalIntent::class)->snapshot($instance, $force, $detached);
        }
        $account = $this->accounts->resolve($instance->node);
        $retained = InstanceRemovalMember::query()->where('instance_id', $instance->id)->whereNull('row_deleted_at')->with('removal.members')->first();
        if ($instance->status === InstanceState::Removing) {
            if (! $retained instanceof InstanceRemovalMember || $retained->removal->requested_instance_id !== $instance->id) {
                $this->conflict($instance);
            }
            $removal = $retained->removal;
            if ($removal->force !== $force && (! $force || $removal->force || $removal->status !== InstanceRemovalStatus::Failed)) {
                $this->conflict($instance);
            }
            $members = [];
            foreach ($removal->members as $member) {
                $members[] = [...$member->toArray(), 'run_teardown' => false,
                    'source_root' => dirname((string) $member->checkout_path, 2),
                    'user' => $account->user, 'group' => $account->group,
                    'teardown_digest' => $this->teardownDigest($instance->project),
                    'member_id' => $member->id, 'route_node_id' => $member->route_id === null ? null : $this->routeNode(Route::query()->find($member->route_id)),
                ];
            }

            return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
                'name' => $instance->name, 'force' => $force, 'removal_id' => $removal->id, 'inventory_digest' => $removal->inventory_digest,
                'repository' => $instance->project->repository_url, 'repository_identity' => $instance->project->repository_identity,
                'members' => $members, 'additional_route_ids' => [], 'additional_routes' => [],
            ];
        }
        $this->assertSupported($instance);
        [$members, $inventories] = $this->deletionSet($instance, $force, $detached);
        if ($detached && $members->count() !== 1) {
            throw new ResourceOperationException('operation.child_forbidden', 'The Route disposition cannot remove another linked Instance.', 422);
        }
        $snapshots = [];
        $additional = [];
        foreach ($members as $position => $member) {
            $inventory = $inventories[$member->id];
            $route = $this->route($member, $detached);
            foreach ($member->routes as $candidate) {
                if ($route === null || $candidate->id !== $route->id) {
                    $additional[] = $candidate->id;
                }
            }
            $snapshots[] = [
                'position' => $position, 'instance_id' => $member->id, 'project_id' => $member->project_id, 'node_id' => $member->node_id,
                'route_id' => $route?->id, 'route_node_id' => $this->routeNode($route), 'name' => $member->name, 'environment' => $member->defaultAppEnv(),
                'source_layout' => $inventory->layout, 'repository_identity' => $inventory->repositoryIdentity,
                'checkout_path' => $inventory->checkoutPath, 'root' => $member->effectiveRoot(),
                'branch' => $inventory->branch, 'starting_commit' => $member->starting_commit, 'source_commit' => $inventory->startingCommit,
                'common_repository_path' => $inventory->commonRepositoryPath, 'source_identity' => $inventory->sourceIdentity,
                'linked_worktree_paths' => $inventory->linkedWorktreePaths, 'source_digest' => $inventory->digest,
                'runtime_published' => $member->status === InstanceState::Active,
                'source_root' => $this->boundaries->instanceRoot($member, $account)->value,
                'user' => $account->user, 'group' => $account->group,
                'run_teardown' => ! $this->failedCreation($member),
                'teardown_digest' => $this->teardownDigest($member->project),
                'worktree_inventory' => base64_encode($inventory->worktreeInventory), 'origin' => $member->project->repository_url,
                'row_deleted_at' => null,
            ];
        }

        return ['instance_id' => $instance->id, 'node_id' => $instance->node_id, 'project_id' => $instance->project_id,
            'name' => $instance->name, 'force' => $force, 'removal_id' => (string) Str::uuid(),
            'inventory_digest' => $this->inventoryDigest($instance->id, $force, $inventories),
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

    /** @return array{Collection<int, Instance>, array<int, InstanceSourceInventory>} */
    private function deletionSet(Instance $requested, bool $force, bool $detached = false): array
    {
        $this->assertMemberPathAvailable($requested);
        $requestedInventory = $this->inspect(
            $requested,
            $force,
            inspectContent: $requested->source_layout !== InstanceSourceLayout::Checkout->value,
        );
        $this->assertMemberPathAvailable($requested);
        $members = collect([$requested]);

        if ($requested->source_layout === InstanceSourceLayout::Checkout->value) {
            $registered = Instance::query()
                ->with(['project', 'node', 'routes.targets'])
                ->where('node_id', $requested->node_id)
                ->whereIn('checkout_path', $requestedInventory->linkedWorktreePaths)
                ->get();

            if ($registered->count() !== count($requestedInventory->linkedWorktreePaths)) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'Every linked worktree must be a registered Instance before removal.',
                    status: 409,
                );
            }

            if ($registered->count() > 1 && (! $force || $this->failedCreation($requested))) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'The checkout has registered linked worktrees; retry with --force.',
                    status: 409,
                );
            }

            if (! $force) {
                $contentInventory = $this->inspect($requested, false);
                $this->assertMemberPathAvailable($requested);

                if (
                    $contentInventory->digest !== $requestedInventory->digest
                    || $contentInventory->worktreeInventory !== $requestedInventory->worktreeInventory
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.remove_refused',
                        message: 'The linked-worktree inventory is inconsistent.',
                        status: 409,
                    );
                }

                $requestedInventory = $contentInventory;
            }

            if ($force) {
                $members = collect(
                    $registered
                        ->sortBy(static fn (Instance $member): string => sprintf(
                            '%d:%s',
                            $member->source_layout === InstanceSourceLayout::Checkout->value ? 1 : 0,
                            $member->checkout_path,
                        ))
                        ->values()
                        ->all(),
                );
            }
        }

        $inventories = [];

        foreach ($members as $member) {
            $member->loadMissing(['project', 'node', 'routes.targets']);
            $this->assertSupported($member);

            if (
                $member->node_id !== $requested->node_id
                || $member->project_id !== $requested->project_id
                || $member->defaultAppEnv() !== $requested->defaultAppEnv()
                || $member->project->repository_identity !== $requested->project->repository_identity
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: "Instance [{$member->name}] is not owned by the requested source set.",
                    status: 409,
                );
            }

            $this->route($member, $detached && $member->id === $requested->id);
            $this->assertMemberPathAvailable($member);
            $inventory = $member->id === $requested->id
                ? $requestedInventory
                : $this->inspect($member, $force);
            $this->assertMemberPathAvailable($member);

            if (
                $requested->source_layout === InstanceSourceLayout::Checkout->value
                && ($inventory->linkedWorktreePaths !== $requestedInventory->linkedWorktreePaths
                || $inventory->commonRepositoryPath !== $requestedInventory->commonRepositoryPath)
            ) {
                throw new ResourceOperationException(
                    errorCode: 'instance.remove_refused',
                    message: 'The linked-worktree inventory is inconsistent.',
                    status: 409,
                );
            }

            $inventories[$member->id] = $inventory;
        }

        if (
            $requested->source_layout === InstanceSourceLayout::Checkout->value
            && $members->where('source_layout', InstanceSourceLayout::Checkout->value)->count() !== 1
        ) {
            throw new ResourceOperationException(
                errorCode: 'instance.remove_refused',
                message: 'The linked-worktree inventory does not identify one common checkout.',
                status: 409,
            );
        }

        return [$members, $inventories];
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

    private function route(Instance $instance, bool $detached = false): ?Route
    {
        if ($this->withoutRoute($instance) || $detached && $instance->routes->isEmpty()) {
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, Route> */
    private function ownRoutes(Instance $instance): \Illuminate\Database\Eloquent\Collection
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

    private function inspect(
        Instance $instance,
        bool $force,
        bool $inspectContent = true,
    ): InstanceSourceInventory {
        try {
            return $this->sourceInspector->inspect($instance, $force, $inspectContent);
        } catch (RuntimeConvergenceException $exception) {
            throw new ResourceOperationException(
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
                status: 409,
                previous: $exception,
            );
        }
    }

    /** @param array<int, InstanceSourceInventory> $inventories */
    private function inventoryDigest(int $requestedId, bool $force, array $inventories): string
    {
        $members = collect($inventories)
            ->sortKeys()
            ->map(static fn (InstanceSourceInventory $inventory): array => [
                'id' => $inventory->instanceId,
                'digest' => $inventory->digest,
                'worktrees' => $inventory->linkedWorktreePaths,
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'requested_id' => $requestedId,
            'force' => $force,
            'members' => $members,
        ], JSON_THROW_ON_ERROR));
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
