<?php

declare(strict_types=1);

use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;

function cancellable_task_group(TaskGroupStatus $status, ?string $prUrl = null): Task
{
    $project = Project::query()->create([
        'name' => 'cancel-app',
        'slug' => 'cancel-app',
        'repository_url' => 'git@github.com:hardimpactdev/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'cancel-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.160',
        'wireguard_ip' => '10.44.0.160',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancel me',
        'brief' => 'Remove the stuck task workspace.',
        'status' => $status,
        'pr_url' => $prUrl,
    ]);
    test_task_workspace($group, [
        'node_id' => $node->id,
        'name' => 'task-21',
        'branch_override' => null,
        'checkout_path' => '/srv/orbit/apps/cancel-app/task-21',
    ]);

    return $group->fresh(['project', 'taskable']) ?? $group;
}

/** Records each removal and deletes the row, as a completed removal does. */
function cancel_recording_remover(): object
{
    $remover = new class implements InstanceRemover
    {
        /** @var list<array{0: int, 1: bool}> */
        public array $calls = [];

        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            $this->calls[] = [$instance->id, $force];
            $instance->delete();

            return new InstanceRemoval;
        }
    };
    app()->instance(InstanceRemover::class, $remover);

    return $remover;
}

/** Records each push, and fails while `$failures` remain. */
function cancel_recording_publisher(int $failures = 0): object
{
    $publisher = new class($failures) implements TaskPullRequestPublisher
    {
        /** @var list<int> */
        public array $pushes = [];

        /** @var list<string> */
        public array $commits = [];

        /** @var list<int> the database transaction level at each push */
        public array $transactionLevels = [];

        public function __construct(private int $failures) {}

        public function publish(Task $group, string $body, string $commit): string
        {
            throw new LogicException('Cancel never opens a pull request.');
        }

        public function push(Task $group, string $commit): void
        {
            $this->pushes[] = $group->id;
            $this->commits[] = $commit;
            $this->transactionLevels[] = DB::transactionLevel();
            if ($this->failures-- > 0) {
                throw new TaskPullRequestException('The task branch could not be pushed.');
            }
        }

        public function pushHandoff(Task $group, string $commit): void
        {
            throw new LogicException('Cancel pushes no handoff.');
        }

        /** @var list<array{0: int, 1: bool}> each deleted group and whether its workspace Instance still existed */
        public array $deletedHandoffBranches = [];

        public bool $deleteFails = false;

        public function deleteHandoffBranch(Task $group): void
        {
            $this->deletedHandoffBranches[] = [$group->id, Instance::query()->where('task_workspace_owner_id', $group->id)->exists()];
            if ($this->deleteFails) {
                throw new TaskPullRequestException('The handoff branch could not be deleted: GitHub refused the request.');
            }
        }
    };
    app()->instance(TaskPullRequestPublisher::class, $publisher);

    return $publisher;
}

function cancel_approval(Task $task, string $commit): void
{
    TaskComment::query()->create([
        'task_group_id' => $task->parent_id,
        'task_id' => $task->id,
        'type' => 'approved',
        'body' => 'Approved.',
        'author' => 'reviewer',
        'review_attempt' => 1,
        'commit_sha' => $commit,
        'posted_at' => now(),
    ]);
}

function cancel_subtask(Task $group, TaskStatus $status, int $position = 1): Task
{
    return Task::query()->create([
        'parent_id' => $group->id,
        'position' => $position,
        'title' => 'Subtask '.$position,
        'brief' => 'Part of the group.',
        'status' => $status,
    ]);
}

beforeEach(function (): void {
    bind_task_node_reachability();
});

it('cancels an eligible group and removes its shared Instance with its checkout', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $instanceId = $group->taskable_id;

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    // Removal, not a row delete, so the checkout on the Node goes too.
    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and(Instance::query()->find($instanceId))->toBeNull();
});

it('honors an already cancelled group and cleans up an attached Instance', function (): void {
    app(TaskExtensionState::class)->enable();
    cancel_recording_remover();
    $group = cancellable_task_group(TaskGroupStatus::Cancelled);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(Instance::query()->count())->toBe(0);
});

it('returns 409 for a completed group or a settling group with a pull request', function (TaskGroupStatus $status, ?string $prUrl): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group($status, $prUrl);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_cancellable')
                ->and($exception->status)->toBe(409);
        });
})->with([
    'settling with a pull request' => [TaskGroupStatus::Settling, 'https://github.com/hardimpactdev/orbit/pull/7'],
    'waiting for review' => [TaskGroupStatus::WaitingForReview, 'https://github.com/hardimpactdev/orbit/pull/7'],
    'completed' => [TaskGroupStatus::Completed, null],
]);

it('pushes approved commits before it cancels a settling group without a pull request', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    $approved = cancel_subtask($group, TaskStatus::Completed, 1);
    cancel_approval($approved, str_repeat('c', 40));
    $cancelledSubtask = cancel_subtask($group, TaskStatus::Cancelled, 2);
    $instanceId = $group->taskable_id;
    $testLevel = DB::transactionLevel();

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($publisher->pushes)->toBe([$group->id])
        ->and($publisher->commits)->toBe([str_repeat('c', 40)])
        ->and($publisher->transactionLevels)->toBe([$testLevel])
        ->and($remover->calls)->toBe([[$instanceId, true]])
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelledSubtask->fresh()->status)->toBe(TaskStatus::Cancelled);
});

it('keeps the settling group and its Instance when the push fails', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    cancel_recording_publisher(failures: 1);
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_approval(cancel_subtask($group, TaskStatus::Completed), str_repeat('c', 40));
    $instanceId = $group->taskable_id;

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.push_failed')
                ->and($exception->status)->toBe(502);
        });

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
        ->and($group->fresh()->taskable_id)->toBe($instanceId)
        ->and($remover->calls)->toBe([]);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($remover->calls)->toBe([[$instanceId, true]]);
});

it('does not push a settling group without approved subtasks', function (): void {
    app(TaskExtensionState::class)->enable();
    cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Settling);
    cancel_subtask($group, TaskStatus::Cancelled);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($publisher->pushes)->toBe([])
        ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled);
});

it('asks for assistance and keeps the clone when removal of a source-resolved workspace is refused', function (): void {
    app(TaskExtensionState::class)->enable();
    app()->instance(InstanceRemover::class, new class implements InstanceRemover
    {
        public function execute(Instance $instance, bool $force): InstanceRemoval
        {
            throw new ResourceOperationException('instance.force_failed', 'The checkout could not be inspected.', 409);
        }
    });
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $instanceId = $group->taskable_id;

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(ResourceOperationException::class, 'The checkout could not be inspected.');

    $fresh = $group->fresh();
    expect($fresh?->status)->toBe(TaskGroupStatus::Running)
        ->and($fresh?->taskable_id)->toBe($instanceId)
        ->and($fresh?->assistance_requested)->toBeTrue()
        ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: The checkout could not be inspected.')
        ->and(Instance::query()->find($instanceId))->not->toBeNull();
});

/**
 * A group that holds no Instance while the `task-{id}` workspace it owns stays on the Node. Without `$owned`, the
 * Instance is the user's own, with the same name and branch and no owner.
 */
function cancel_unattached_workspace(TaskGroupStatus $status, string $instanceStatus = 'source_resolved', bool $owned = true): array
{
    $group = cancellable_task_group($status);
    $attached = $group->taskable;
    $group->taskable()->dissociate();
    $group->save();
    $attached?->delete();
    $name = 'task-'.$group->id;
    $attributes = [
        'node_id' => Node::query()->where('name', 'cancel-node')->value('id'),
        'checkout_path' => "/srv/orbit/apps/cancel-app/{$name}",
        'status' => $instanceStatus,
    ];
    $workspace = $owned
        ? test_task_workspace($group, $attributes, attach: false)
        : Instance::query()->create(['project_id' => $group->project_id, 'name' => $name, 'branch_override' => $name, ...$attributes]);

    return [$group->fresh(['project', 'taskable']) ?? $group, $workspace];
}

describe('a workspace the group never attached', function (): void {
    it('removes the leftover workspace of a group swept back to todo', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($remover->calls)->toBe([[$workspace->id, true]])
            ->and(Instance::query()->find($workspace->id))->toBeNull();
    });

    it('removes the leftover workspace of a group stranded in reserved past the bound', function (): void {
        $this->freezeTime();
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
        $group->forceFill(['reserved_at' => now()->subSeconds(3601)])->save();

        app(CancelTaskGroupAction::class)->execute($group);

        expect($remover->calls)->toBe([[$workspace->id, true]]);
    });

    it('leaves the workspace to a claim still in flight', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved, 'reserved');
        $group->forceFill(['reserved_at' => now()->subSeconds(30)])->save();

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($remover->calls)->toBe([])
            ->and(Instance::query()->find($workspace->id))->not->toBeNull();
    });

    it('removes a workspace the claim attached before the cancel landed', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
        $group->forceFill(['reserved_at' => now()->subSeconds(30)])->save();
        $stale = $group->fresh(['project', 'taskable']);
        $group->taskable()->associate($workspace);
        $group->save();

        app(CancelTaskGroupAction::class)->execute($stale);

        expect($remover->calls)->toBe([[$workspace->id, true]])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->fresh()?->taskable_id)->toBeNull();
    });

    it('removes the workspace a claim attached after cancel looked for it', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
        $group->forceFill(['reserved_at' => now()->subSeconds(30)])->save();
        // Cancel saw the claim in flight and left the workspace alone. The claim attaches it before cancel's lock.
        $attached = false;
        $database = app('db');
        $double = Mockery::mock($database);
        $double->shouldReceive('transaction')->andReturnUsing(static function (Closure $callback, int $attempts = 1) use ($database, $group, $workspace, &$attached): mixed {
            if (! $attached) {
                $attached = true;
                $claimed = Task::topLevel()->findOrFail($group->id);
                $claimed->taskable()->associate($workspace);
                $claimed->save();
            }

            return $database->connection()->transaction($callback, $attempts);
        });
        DB::swap($double);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($attached)->toBeTrue()
            ->and($remover->calls)->toBe([[$workspace->id, true]])
            ->and($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($cancelled->taskable_id)->toBeNull()
            ->and(Instance::query()->whereKey($workspace->id)->exists())->toBeFalse();
    });

    it('keeps a half-provisioned workspace and asks for assistance when removal refuses', function (): void {
        app(TaskExtensionState::class)->enable();
        app()->instance(InstanceRemover::class, new class implements InstanceRemover
        {
            public function execute(Instance $instance, bool $force): InstanceRemoval
            {
                throw new ResourceOperationException('instance.remove_refused', 'Instance is not active.', 409);
            }
        });
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo, 'checkout_prepared');

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class, 'Instance is not active.');

        $fresh = $group->fresh();
        expect($fresh?->status)->toBe(TaskGroupStatus::Todo)
            ->and($fresh?->assistance_requested)->toBeTrue()
            ->and($fresh?->assistance_reason)->toBe('Workspace removal failed: Instance is not active.')
            ->and(Instance::query()->find($workspace->id))->not->toBeNull();
    });

    it('never removes a user Instance named and branched like the workspace', function (): void {
        app(TaskExtensionState::class)->enable();
        $remover = cancel_recording_remover();
        [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Todo, owned: false);

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($remover->calls)->toBe([])
            ->and(Instance::query()->find($workspace->id))->name->toBe('task-'.$group->id)->branch_override->toBe('task-'.$group->id);
    });
});

/** Binds only the Node-side source, projection, and lock boundaries; cancellation uses the real remover. */
function cancel_real_remover(Instance $workspace, bool $refused = false): void
{
    foreach ([InstanceEnvironmentOperationLock::class, ProcessAdmissionLock::class, AppDevSourceOperationLock::class] as $contract) {
        $lock = Mockery::mock($contract);
        $lock->shouldReceive($contract === AppDevSourceOperationLock::class ? 'synchronized' : 'run')
            ->andReturnUsing(static fn (mixed $owner, Closure $operation): mixed => $operation());
        app()->instance($contract, $lock);
    }
    $source = Mockery::mock(DevelopmentInstanceSourceRemoval::class);
    $finalizer = Mockery::mock(DevelopmentInstanceSourceFinalizer::class);
    if ($refused) {
        $source->shouldNotReceive('inspect');
        $finalizer->shouldNotReceive('finalize');
    } else {
        $source->shouldReceive('inspect')->withArgs(static fn (Instance $instance, bool $force): bool => $instance->id === $workspace->id && $force)
            ->andReturn(new InstanceSourceInventory(
                instanceId: $workspace->id,
                layout: $workspace->source_layout,
                repositoryIdentity: $workspace->project->repository_identity,
                checkoutPath: $workspace->checkout_path,
                root: '/srv/orbit/apps',
                branch: $workspace->branch,
                startingCommit: str_repeat('a', 40),
                commonRepositoryPath: $workspace->checkout_path,
                sourceIdentity: 'cancel-workspace:'.$workspace->id,
                linkedWorktreePaths: [$workspace->checkout_path],
                digest: hash('sha256', $workspace->checkout_path),
            ));
        $finalizer->shouldReceive('prepare');
        $finalizer->shouldReceive('revalidate')->andReturn(InstanceSourceRevalidationState::Present);
        $finalizer->shouldReceive('finalize')->once()->andReturn(hash('sha256', 'cancel-receipt'));
    }
    app()->instance(DevelopmentInstanceSourceRemoval::class, $source);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $finalizer);
    $projector = new class implements InstanceRemovalProjector
    {
        public function clearRouteTarget(InstanceRemovalMember $member): string
        {
            Route::query()->findOrFail($member->route_id)->delete();

            return 'deleted';
        }

        public function withdrawPhpPool(InstanceRemovalMember $member): void {}

        public function cleanupRuntime(InstanceRemovalMember $member): void {}
    };
    app()->instance(InstanceRemovalProjector::class, $projector);
    app()->instance(InstanceRemover::class, app(DestroyInstanceAction::class));
}

function cancel_pending_route(Instance $workspace): Route
{
    $workspace->update(['root' => 'public', 'branch' => $workspace->name, 'starting_commit' => str_repeat('a', 40)]);
    $workspace->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $route = Route::query()->create([
        'project_id' => $workspace->project_id,
        'node_id' => $workspace->node_id,
        'domain' => 'cancel-workspace-'.$workspace->id.'.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);

    return $route;
}

it('removes an attached routed source-resolved workspace with its pending Route', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $workspace = $group->taskable;
    $route = cancel_pending_route($workspace);
    $target = $route->targets()->sole();
    cancel_real_remover($workspace);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->taskable_id)->toBeNull()
        ->and($cancelled->assistance_requested)->toBeFalse();
    $this->assertModelMissing($workspace);
    $this->assertModelMissing($route);
    $this->assertModelMissing($target);
    expect(InstanceRemoval::query()->sole()->status->value)->toBe('completed');
});

it('re-cancels a cancelled group and removes its unattached routed source-resolved workspace', function (): void {
    app(TaskExtensionState::class)->enable();
    [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Cancelled);
    $route = cancel_pending_route($workspace);
    $target = $route->targets()->sole();
    cancel_real_remover($workspace);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);
    $again = app(CancelTaskGroupAction::class)->execute($cancelled);

    expect($again->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($again->taskable_id)->toBeNull()
        ->and($again->assistance_requested)->toBeFalse()
        ->and($again->assistance_reason)->toBeNull();
    $this->assertModelMissing($workspace);
    $this->assertModelMissing($route);
    $this->assertModelMissing($target);
    expect(InstanceRemoval::query()->count())->toBe(1);
});

it('asks for assistance and keeps a routed source-resolved workspace with an active or shared Route', function (string $routeState): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $workspace = $group->taskable;
    $route = cancel_pending_route($workspace);
    $target = $route->targets()->sole();
    if ($routeState === 'active') {
        $route->update(['status' => 'active']);
    } else {
        $other = Instance::query()->create([
            'project_id' => $workspace->project_id,
            'node_id' => $workspace->node_id,
            'name' => 'other',
            'checkout_path' => '/srv/orbit/apps/cancel-app/other',
            'status' => 'source_resolved',
        ]);
        // Exercise inconsistent stored targets without weakening the production persistence contract.
        $trigger = DB::table('sqlite_master')->where('name', 'route_targets_contract_insert')->sole()->sql;
        DB::statement('DROP TRIGGER route_targets_contract_insert');
        try {
            $otherTarget = $route->targets()->create(['instance_id' => $other->id, 'position' => 1]);
        } finally {
            DB::statement($trigger);
        }
    }
    cancel_real_remover($workspace, refused: true);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.remove_refused'));

    expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
        ->and($group->fresh()->taskable_id)->toBe($workspace->id)
        ->and($group->fresh()->assistance_requested)->toBeTrue()
        ->and($group->fresh()->assistance_reason)->toStartWith(RemoveTaskWorkspaceAction::RemovalFailedPrefix)
        ->and(InstanceRemoval::query()->count())->toBe(0);
    $this->assertModelExists($workspace);
    $this->assertModelExists($route);
    $this->assertModelExists($target);
    if (isset($other, $otherTarget)) {
        $this->assertModelExists($other);
        $this->assertModelExists($otherTarget);
    }
})->with(['active', 'shared']);

it('preserves the removal error after a claim attaches during cancellation', function (bool $deleted): void {
    app(TaskExtensionState::class)->enable();
    [$group, $workspace] = cancel_unattached_workspace(TaskGroupStatus::Reserved);
    $group->forceFill(['reserved_at' => now()])->save();
    $failure = new ResourceOperationException('instance.remove_refused', 'Original removal failure.', 409);
    $remover = Mockery::mock(InstanceRemover::class);
    $remover->shouldReceive('execute')->once()->withArgs(static fn (Instance $instance, bool $force): bool => $instance->is($workspace) && $force)
        ->andReturnUsing(static function (Instance $instance) use ($deleted, $failure): never {
            if ($deleted) {
                $instance->delete();
            }

            throw $failure;
        });
    app()->instance(InstanceRemover::class, $remover);
    $database = app('db');
    $double = Mockery::mock($database);
    $attached = false;
    $double->shouldReceive('transaction')->andReturnUsing(static function (Closure $callback, int $attempts = 1) use ($database, $group, $workspace, &$attached): mixed {
        if (! $attached) {
            $attached = true;
            $claimed = Task::topLevel()->findOrFail($group->id);
            $claimed->taskable()->associate($workspace);
            $claimed->save();
        }

        return $database->connection()->transaction($callback, $attempts);
    });
    DB::swap($double);

    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception)->toBe($failure));

    $this->assertDatabaseHas('tasks', [
        'id' => $group->id,
        'status' => 'cancelled',
        'taskable_id' => $deleted ? null : $workspace->id,
        'assistance_requested' => true,
        'assistance_kind' => 'removal_failure',
        'assistance_reason' => 'Workspace removal failed: Original removal failure.',
    ]);
    expect(Instance::query()->whereKey($workspace->id)->exists())->toBe(! $deleted);
})->with(['row already removed' => true, 'row retained for cleanup' => false]);

it('clears removal assistance once when a refused cancellation succeeds on retry', function (): void {
    app(TaskExtensionState::class)->enable();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $remover = Mockery::mock(InstanceRemover::class);
    $remover->shouldReceive('execute')->once()->andThrow(new ResourceOperationException('instance.remove_refused', 'Removal refused.', 409));
    app()->instance(InstanceRemover::class, $remover);
    expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(ResourceOperationException::class);
    expect($group->fresh()?->assistance_kind)->toBe(AssistanceKind::RemovalFailure);

    cancel_recording_remover();
    $cancelled = app(CancelTaskGroupAction::class)->execute($group);
    app(CancelTaskGroupAction::class)->execute($cancelled);

    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($cancelled->assistance_requested)->toBeFalse()
        ->and($cancelled->taskable_id)->toBeNull()
        ->and(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)
            ->where('description', 'assistance cleared')->count())->toBe(1);
});

it('deletes the handoff branch of a group it cancels once its workspace is gone, and only after a handoff pushed one', function (bool $handedOff): void {
    app(TaskExtensionState::class)->enable();
    cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $subtask = cancel_subtask($group, TaskStatus::Running);
    TaskCheck::query()->create([
        'task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed,
        'commit_sha' => $handedOff ? str_repeat('c', 40) : null,
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);

    expect(app(CancelTaskGroupAction::class)->execute($group)->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($publisher->deletedHandoffBranches)->toBe($handedOff ? [[$group->id, false]] : []);
})->with(['after a handoff CI' => true, 'without one' => false]);

it('asks for assistance once when the handoff branch cannot be deleted, and still removes the workspace', function (bool $retry): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $publisher->deleteFails = true;
    $group = cancellable_task_group(TaskGroupStatus::Running);
    if ($retry) {
        // An earlier cancel could not remove the workspace and asked for assistance; this cancel retries the removal.
        TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node did not answer.');
    }
    $subtask = cancel_subtask($group, TaskStatus::Running);
    TaskCheck::query()->create([
        'task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'commit_sha' => str_repeat('c', 40),
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);

    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    // The request replaces the earlier removal failure, because the workspace is gone and only the branch is left.
    expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($remover->calls)->toHaveCount(1)
        ->and($cancelled->fresh()?->taskable_id)->toBeNull()
        ->and($publisher->deletedHandoffBranches)->toBe([[$group->id, false]])
        ->and($cancelled->fresh()?->assistance_kind)->toBe(AssistanceKind::RemovalFailure)
        ->and($cancelled->fresh()?->assistance_reason)->toBe(cancel_branch_left_reason($group))
        ->and(Activity::query()->where('subject_id', $group->id)->where('command', 'tasks:assistance')->pluck('description')->all())
        ->toBe($retry ? ['assistance requested', 'assistance changed'] : ['assistance requested']);
})->with(['a first cancel' => false, 'a cancel that retries a failed removal' => true]);

it('deletes a handoff branch left by an earlier cancel when the task is cancelled again, and then clears the request', function (): void {
    app(TaskExtensionState::class)->enable();
    $remover = cancel_recording_remover();
    $publisher = cancel_recording_publisher();
    $publisher->deleteFails = true;
    $group = cancellable_task_group(TaskGroupStatus::Running);
    $subtask = cancel_subtask($group, TaskStatus::Running);
    TaskCheck::query()->create([
        'task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'commit_sha' => str_repeat('c', 40),
        'pid' => 1, 'process_started' => 'earlier', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(),
    ]);
    app(CancelTaskGroupAction::class)->execute($group);
    expect($group->fresh()?->assistance_reason)->toBe(cancel_branch_left_reason($group));

    // The operator restored the App access; the repeated cancel tries the delete once more.
    $publisher->deleteFails = false;
    $cancelled = app(CancelTaskGroupAction::class)->execute($group);

    expect($remover->calls)->toHaveCount(1)
        ->and($publisher->deletedHandoffBranches)->toBe([[$group->id, false], [$group->id, false]])
        ->and($cancelled->fresh()?->assistance_requested)->toBeFalse()
        ->and(Activity::query()->where('subject_id', $group->id)->where('command', 'tasks:assistance')->pluck('description')->all())
        ->toBe(['assistance requested', 'assistance cleared']);
});

function cancel_branch_left_reason(Task $group): string
{
    return RemoveTaskWorkspaceAction::HandoffBranchLeftPrefix.'The handoff branch could not be deleted: GitHub refused the request. Delete task-'
        .$group->id.'-handoff on GitHub or restore the App access, then cancel or complete the task again.';
}
