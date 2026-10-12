<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCeilings;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskConcurrencyGuard;
use App\Domain\Tasks\TaskWait;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Domain\Tasks\TaskWorkspaceRoute;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TaskWorkspaceProvisioner implements InstanceProvisioning, TaskWorkspaceRoute
{
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentInstanceSourceLifecycle $source,
        private DevelopmentInstanceProvisioner $development,
        private TaskConcurrencyGuard $ceilings,
        private AgentDriverRegistry $drivers,
        private TaskVmWorkspace $taskVms,
        private TaskVmStepClock $clock,
    ) {}

    public function provision(InstanceProvisionIntent $intent): Instance|InstanceProvisionFailure
    {
        return $this->provisionWorkspace($intent);
    }

    private function provisionWorkspace(InstanceProvisionIntent $intent): Instance|InstanceProvisionFailure
    {
        $group = $intent->group->loadMissing(['project', 'taskable']);
        $taskVmNode = null;
        if (($group->task_compute ?? $group->project->task_compute) === TaskCompute::Vm) {
            $taskVmNode = $this->taskVms->node($group);
        }
        $existing = $group->taskable;

        if ($existing instanceof Instance) {
            return $existing;
        }

        $workspace = $this->existingWorkspace($group);
        if ($workspace instanceof Instance && $workspace->status === InstanceState::Reserved && ! $this->hasCapacity($workspace->node)) {
            $failure = $this->releaseEmptyReservation($group, $workspace);
            if ($failure instanceof InstanceProvisionFailure) {
                return $failure;
            }
            $workspace = null;
        }
        $visitable = $this->routingForClaim($workspace, $intent->visitable);

        $sourceFailure = $this->sourceDefaultsFailure($group->project, $visitable);
        if ($sourceFailure instanceof InstanceProvisionFailure) {
            return $sourceFailure;
        }

        $drivers = [$intent->group->implementer_agent_driver, $intent->group->reviewer_agent_driver];
        $node = $taskVmNode instanceof Node
            ? $this->taskVmNode($taskVmNode, $drivers)
            : $this->selectNode($group->project, $drivers, $this->existingWorkspaceNodeId($workspace));

        if ($node instanceof InstanceProvisionFailure) {
            return $node;
        }

        $vm = $taskVmNode instanceof Node ? $this->taskVms->vm($group) : null;
        // The step clock reads the app clock.
        $started = now()->getPreciseTimestamp() / 1_000_000;
        if ($vm instanceof TaskVm) {
            $this->clock->start($vm, $started);
        }
        try {
            return $this->createWorkspace($group, $node, $visitable, $vm);
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            report($exception);

            return InstanceProvisionFailure::fromException($exception);
        } finally {
            if ($vm instanceof TaskVm) {
                $this->clock->start($vm, $started);
                $this->clock->step('workspace');
                $this->clock->stop();
            }
        }
    }

    /**
     * `$vm` is the group's task VM. A new workspace there starts from the Project's workspace template, and each part
     * of the creation logs its seconds as a task VM step. A routed workspace there stops before its Route: it gets its
     * source profile, `.env`, and PHP packages, which the baseline check needs, and stays `source_resolved`. The
     * scheduler starts the check and then finishes the Route through {@see self::complete()}.
     */
    private function createWorkspace(Task $group, Node $node, bool $visitable, ?TaskVm $vm = null): Instance
    {
        $name = TaskWorkspaceName::for($group);
        $existing = Instance::query()
            ->where('project_id', $group->project_id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof Instance) {
            // Only the group's own workspace carries its task branch. Another Instance with the name is never adopted.
            if ($existing->branch_override !== $name) {
                throw new ResourceOperationException(
                    'instance.name_taken',
                    "Instance [{$name}] exists without the task branch and is not this group's workspace.",
                    409,
                );
            }

            if ($existing->node_id !== $node->id) {
                throw new ResourceOperationException(
                    'instance.placement_conflict',
                    'Instance placement is immutable.',
                    409,
                );
            }

            $instance = $existing;
            $visitable = $this->recordedRouting($instance, $visitable);
            // A claim that stopped before source preparation finished attaches the template again.
            $reserved = StoragePath::tryParse((string) $instance->checkout_path);
            $template = $instance->status === InstanceState::Reserved && $reserved instanceof StoragePath ? $this->template($vm, $group, $reserved) : null;
        } else {
            $account = $this->accounts->resolve($node);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($node->settings),
                $account,
            );
            // A task VM holds one workspace at a fixed path, where its template was built; a shared Node holds many.
            $checkout = $roots->append($group->project->slug, $vm instanceof TaskVm ? TaskVmWorkspaceTemplates::CheckoutDirectory : $name);
            $this->checkoutOverlap->assertAvailable($node->id, $checkout, 'instance.path_taken');
            $this->destinationGuard->assertUnoccupied($node, $checkout);
            $template = $this->template($vm, $group, $checkout);

            $instance = Instance::query()->create([
                'project_id' => $group->project_id,
                'node_id' => $node->id,
                'name' => $name,
                'source_layout' => InstanceSourceLayout::Checkout,
                'source_prepare_id' => (string) Str::uuid(),
                'checkout_path' => $checkout->value,
                'root' => $visitable ? $group->project->root : null,
                'branch_override' => $name,
                'task_workspace_routed' => $visitable,
                'status' => InstanceState::Reserved,
            ]);
        }

        return $this->sourceLock->synchronized(
            $instance->node_id,
            function () use ($instance, $visitable, $existing, $template, $vm): Instance {
                $resolved = $this->prepareSource($instance, $existing instanceof Instance, $template);
                $this->source->inspectPrepared($resolved);
                $this->clock->step('workspace-source');

                if (! $visitable) {
                    return $resolved;
                }

                $this->development->reserve($resolved, null);
                if ($vm instanceof TaskVm) {
                    $prepared = $this->development->prepare($resolved, null);
                    $this->clock->step('workspace-prepare');

                    return $prepared;
                }
                $completed = $this->development->complete($resolved, null);
                $this->clock->step('workspace-route');

                return $completed;
            },
        );
    }

    public function pending(Task $group): bool
    {
        $instance = $group->taskable;

        return $instance instanceof Instance
            && $instance->task_workspace_routed === true
            && $instance->status === InstanceState::SourceResolved;
    }

    /**
     * Projects the Route under the Node's source lock, as creation does, and logs `workspace-route` for a task VM. A
     * failure leaves the workspace `source_resolved` with its Route pending, so the next call tries again.
     */
    public function complete(Task $group): ?InstanceProvisionFailure
    {
        $group = $group->fresh(['project', 'taskable']) ?? $group;
        if (! $this->pending($group)) {
            return null;
        }
        $instance = $group->taskable;
        assert($instance instanceof Instance);
        $vm = $this->taskVms->vm($group);
        if ($vm instanceof TaskVm) {
            $this->clock->start($vm);
        }
        try {
            // Removal holds the same lock, so a workspace it removed meanwhile is gone here and needs nothing.
            $this->sourceLock->synchronized($instance->node_id, function () use ($instance): void {
                $current = Instance::query()->find($instance->id);
                if ($current instanceof Instance && $current->status === InstanceState::SourceResolved) {
                    $this->development->complete($current, null);
                }
            });
            $this->clock->step('workspace-route');

            return null;
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            report($exception);

            return InstanceProvisionFailure::fromException($exception);
        } finally {
            $this->clock->stop();
        }
    }

    /**
     * An existing workspace keeps the mode recorded at creation. The Project setting
     * applies only when no workspace exists. A row with no record is routed only when
     * it already chose a Route, matching the one-time migration.
     */
    private function routingForClaim(?Instance $workspace, bool $selected): bool
    {
        if (! $workspace instanceof Instance) {
            return $selected;
        }

        if (is_bool($workspace->task_workspace_routed)) {
            return $workspace->task_workspace_routed;
        }

        return $workspace->status === InstanceState::Active
            || (is_string($workspace->root) && $workspace->root !== '')
            || $workspace->routes()->exists();
    }

    /**
     * A resumed workspace keeps the mode stored when it was created.
     * A row that has no record adopts the mode this claim already resolved.
     */
    private function recordedRouting(Instance $instance, bool $selected): bool
    {
        if (is_bool($instance->task_workspace_routed)) {
            return $instance->task_workspace_routed;
        }

        $instance->update(['task_workspace_routed' => $selected]);

        return $selected;
    }

    /**
     * Attaches the workspace template for the checkout `$checkout` on the group's task VM, and logs the step. A group
     * without a task VM gets none.
     */
    private function template(?TaskVm $vm, Task $group, StoragePath $checkout): ?string
    {
        if (! $vm instanceof TaskVm) {
            return null;
        }
        $parent = $checkout->parent();
        $template = $parent instanceof StoragePath ? $this->taskVms->template($vm, $group, $parent->value) : null;
        $this->clock->step('workspace-template');

        return $template;
    }

    private function prepareSource(Instance $instance, bool $allowExisting, ?string $template = null): Instance
    {
        while (true) {
            $instance->refresh()->loadMissing(['project', 'node']);

            if ($instance->status === InstanceState::Reserved) {
                // Reclaim may follow a completed prepare whose state transition never persisted.
                // The source lifecycle still verifies identity before accepting an existing checkout.
                $this->source->prepare($instance, $allowExisting, $template);
                $this->transition($instance, InstanceState::Reserved, [
                    'status' => InstanceState::CheckoutPrepared,
                ]);

                continue;
            }

            if ($instance->status === InstanceState::CheckoutPrepared) {
                $this->source->inspectPrepared($instance);
                $resolution = $this->source->resolve($instance);
                $this->assertResolution($instance, $resolution);
                $this->transition($instance, InstanceState::CheckoutPrepared, [
                    'branch' => $resolution->branch,
                    'starting_commit' => $resolution->startingCommit,
                    'status' => InstanceState::SourceResolved,
                ]);

                continue;
            }

            $this->source->inspectPrepared($instance);
            $this->assertStoredResolution($instance, $this->source->inspectResolved($instance));

            return $instance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(Instance $instance, InstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($instance, $from, $attributes): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);

            if ($locked->status !== $from) {
                throw new ResourceOperationException(
                    'instance.lifecycle_conflict',
                    'Instance lifecycle evidence changed.',
                    409,
                );
            }

            $locked->update($attributes);
        });
    }

    private function assertResolution(Instance $instance, DevelopmentSourceResolution $resolution): void
    {
        if (
            $resolution->branch !== $this->expectedBranch($instance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_invalid',
                'Resolved source identity is invalid.',
                409,
            );
        }
    }

    private function assertStoredResolution(
        Instance $instance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $instance->branch !== $this->expectedBranch($instance)
            || ! is_string($instance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $instance->starting_commit) !== 1
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'Instance source identity changed.',
                409,
            );
        }

        $this->assertResolution($instance, $resolution);

        if (
            $instance->branch !== $resolution->branch
            || $instance->starting_commit !== $resolution->startingCommit
        ) {
            throw new ResourceOperationException(
                'instance.source_identity_changed',
                'Instance source identity changed.',
                409,
            );
        }
    }

    private function expectedBranch(Instance $instance): string
    {
        if (is_string($instance->branch_override) && $instance->branch_override !== '') {
            return $instance->branch_override;
        }

        return $instance->name;
    }

    private function sourceDefaultsFailure(Project $project, bool $visitable): ?InstanceProvisionFailure
    {
        if (! is_string($project->default_branch) || ! GitBranchName::isValid($project->default_branch)) {
            return new InstanceProvisionFailure('Project default branch is missing or invalid.');
        }

        if (! GitRepositoryOrigin::isValid($project->repository_url)) {
            return new InstanceProvisionFailure('Project repository is missing or invalid.');
        }

        if ($visitable && (! is_string($project->root) || ! ProjectRoot::isValid($project->root, $project->type))) {
            return new InstanceProvisionFailure('Routed workspace Project root is missing or invalid.');
        }

        return null;
    }

    /** Release database-only reservations; never remove a checkout or infer absence from missing source metadata. */
    private function releaseEmptyReservation(Task $group, Instance $workspace): ?InstanceProvisionFailure
    {
        try {
            return $this->sourceLock->synchronized($workspace->node_id, fn (): ?InstanceProvisionFailure => DB::transaction(function () use ($group, $workspace): ?InstanceProvisionFailure {
                $locked = Instance::query()->lockForUpdate()->findOrFail($workspace->id);
                if ($locked->status !== InstanceState::Reserved
                    || $locked->project_id !== $group->project_id
                    || $locked->branch_override !== TaskWorkspaceName::for($group)
                    || $locked->starting_commit !== null || $locked->seed_commit !== null
                    || $locked->routes()->exists()
                    || Task::query()->whereMorphedTo('taskable', $locked)->exists()) {
                    return new InstanceProvisionFailure('Reserved workspace ['.$locked->id.'] has source or attachment evidence and stays on Node ['.$locked->node_id.'].');
                }
                $path = StoragePath::tryParse((string) $locked->checkout_path);
                if ($path === null) {
                    return new InstanceProvisionFailure('Reserved workspace ['.$locked->id.'] has no valid checkout path to verify.');
                }
                $this->destinationGuard->assertUnoccupied($locked->node, $path);
                $locked->delete();

                return null;
            }));
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            return new InstanceProvisionFailure('Reserved workspace ['.$workspace->id.'] stays on Node ['.$workspace->node_id.']: its checkout could not be proved absent. '.$exception->getMessage());
        }
    }

    /**
     * A workspace that an interrupted claim created but never attached keeps its Node, so a later claim resumes it
     * there instead of creating a second one.
     */
    private function existingWorkspace(Task $group): ?Instance
    {
        $name = TaskWorkspaceName::for($group);

        return Instance::query()
            ->where('project_id', $group->project_id)
            ->where('name', $name)
            ->where('branch_override', $name)
            ->first();
    }

    private function existingWorkspaceNodeId(?Instance $workspace): ?int
    {
        $nodeId = $workspace?->node_id;

        return is_numeric($nodeId) ? (int) $nodeId : null;
    }

    /**
     * A `vm` group works on its own task VM Node, when every driver of the group allows it.
     *
     * @param  list<string>  $drivers
     */
    private function taskVmNode(Node $node, array $drivers): Node|InstanceProvisionFailure
    {
        if (array_all($drivers, fn (string $driver): bool => $this->drivers->get($driver)->allows($node))) {
            return $node;
        }

        return new InstanceProvisionFailure('No Node fits: driver(s) ['.implode(', ', array_unique($drivers)).'] not allowed on task VM Node ['.$node->name.'].');
    }

    /**
     * @param  list<string>  $drivers  Every driver the group uses must allow the Node.
     * @param  int|null  $pinnedNodeId  The Node of the group's existing workspace. Only that Node can then fit.
     */
    private function selectNode(Project $project, array $drivers, ?int $pinnedNodeId = null): Node|InstanceProvisionFailure
    {
        $nodes = Node::query()
            ->where('status', LifecycleStatus::Active)
            ->where('platform', 'linux')
            ->whereNotIn('id', self::taskVmNodeIds())
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active),
            )
            ->with('projectNodeExclusions')
            ->orderBy('id')
            ->get();

        // A Node that opts out of the task worker runs its Gateway Git as the managed user, so agents never work there.
        $eligible = $nodes->filter(fn (Node $node): bool => ! TaskWorkerUser::optedOut($node) && ! $node->projectNodeExclusions->contains('project_id', $project->id));
        $pinned = $eligible->filter(static fn (Node $node): bool => $pinnedNodeId === null || $node->id === $pinnedNodeId);
        $fitting = $pinned->filter(fn (Node $node): bool => array_all($drivers, fn (string $driver): bool => $this->drivers->get($driver)->allows($node)));

        $selected = $fitting
            ->filter(fn (Node $node): bool => $this->hasCapacity($node))
            ->sortBy(fn (Node $node): array => [$this->ceilings->activeForNode($node->id), $node->id])
            ->first();

        if ($selected instanceof Node) {
            return $selected;
        }

        if ($fitting->isNotEmpty()) {
            throw new TaskCapacityException(TaskWait::Capacity, fleetFull: ! $this->anyAppDevNodeHasCapacity());
        }

        if ($nodes->isEmpty()) {
            return new InstanceProvisionFailure('No active Linux app-dev Node is available.');
        }
        if ($eligible->isEmpty()) {
            return new InstanceProvisionFailure('Project node exclusion leaves no available Node.');
        }
        if ($pinned->isEmpty()) {
            return new InstanceProvisionFailure('Pinned workspace Node ['.$pinnedNodeId.'] is not an available active Linux app-dev Node or is excluded by the Project.');
        }

        return new InstanceProvisionFailure('No Node fits: driver(s) ['.implode(', ', array_unique($drivers)).'] not allowed on the available'.($pinnedNodeId === null ? '' : ' pinned workspace').' Node(s).');
    }

    private function hasCapacity(Node $node): bool
    {
        return $this->ceilings->activeForNode($node->id) < TaskCeilings::PerNode;
    }

    private function anyAppDevNodeHasCapacity(): bool
    {
        return Node::query()
            ->where('status', LifecycleStatus::Active)
            ->where('platform', 'linux')
            ->whereNotIn('id', self::taskVmNodeIds())
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', RoleName::AppDev)
                    ->where('status', LifecycleStatus::Active),
            )
            ->get()
            ->contains(fn (Node $node): bool => $this->hasCapacity($node));
    }

    /**
     * Shared groups never use the Node of a task VM that is not destroyed.
     *
     * @return Builder<TaskVm>
     */
    private static function taskVmNodeIds(): Builder
    {
        return TaskVm::query()->live()->whereNotNull('node_id')->select('node_id');
    }
}
