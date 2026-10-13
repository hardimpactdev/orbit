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
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\Tasks\InstanceProvisionFailure;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskWait;
use App\Domain\Tasks\TaskWorkspaceName;
use App\Domain\Tasks\TaskWorkspaceRoute;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
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
        $node = $this->taskVms->node($group);
        $existing = $group->taskable;

        if ($existing instanceof Instance) {
            return $existing;
        }

        $visitable = $this->existingWorkspace($group)->task_workspace_routed ?? $intent->visitable;
        $sourceFailure = $this->sourceDefaultsFailure($group->project, $visitable);
        if ($sourceFailure instanceof InstanceProvisionFailure) {
            return $sourceFailure;
        }

        $vm = $this->taskVms->vm($group) ?? throw new TaskCapacityException(TaskWait::Capacity, 'Task VM: the group has no task VM.');
        // The step clock reads the app clock.
        $started = now()->getPreciseTimestamp() / 1_000_000;
        $this->clock->start($vm, $started);
        try {
            return $this->createWorkspace($group, $node, $visitable, $vm);
        } catch (ResourceOperationException|RuntimeConvergenceException $exception) {
            report($exception);

            return InstanceProvisionFailure::fromException($exception);
        } finally {
            $this->clock->start($vm, $started);
            $this->clock->step('workspace');
            $this->clock->stop();
        }
    }

    /**
     * `$vm` is the group's task VM. A new workspace there starts from the Project's workspace template, and each part
     * of the creation logs its seconds as a task VM step. A routed workspace there stops before its Route: it gets its
     * source profile, `.env`, and PHP packages, which the baseline check needs, and stays `source_resolved`. The
     * scheduler starts the check and then finishes the Route through {@see self::complete()}.
     */
    private function createWorkspace(Task $group, Node $node, bool $visitable, TaskVm $vm): Instance
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
            $visitable = $instance->task_workspace_routed ?? $visitable;
            // A claim that stopped before source preparation finished attaches the template again.
            $reserved = StoragePath::tryParse((string) $instance->checkout_path);
            $template = $instance->status === InstanceState::Reserved && $reserved instanceof StoragePath ? $this->template($vm, $group, $reserved) : null;
        } else {
            $account = $this->accounts->resolve($node);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($node->settings),
                $account,
            );
            // A task VM holds one workspace at a fixed path, where its template was built.
            $checkout = $roots->append($group->project->slug, TaskVmWorkspaceTemplates::CheckoutDirectory);
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
            function () use ($instance, $visitable, $existing, $template): Instance {
                $resolved = $this->prepareSource($instance, $existing instanceof Instance, $template);
                $this->source->inspectPrepared($resolved);
                $this->clock->step('workspace-source');

                if (! $visitable) {
                    return $resolved;
                }

                $this->development->reserve($resolved, null);
                $prepared = $this->development->prepare($resolved, null);
                $this->clock->step('workspace-prepare');

                return $prepared;
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

    /** Attaches the workspace template for the checkout `$checkout` on the group's task VM, and logs the step. */
    private function template(TaskVm $vm, Task $group, StoragePath $checkout): ?string
    {
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
}
