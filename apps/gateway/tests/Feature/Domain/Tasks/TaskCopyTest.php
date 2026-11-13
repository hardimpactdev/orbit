<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskRuntime;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Jobs\TaskVms\AdmitTaskVm;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('tasks', true);
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    // The workspace read: one SSH call to the Node, answered by the test.
    $this->ssh = new class implements SshExecutor
    {
        /** @var array<string, mixed> */
        public array $answer = ['state' => 'clean', 'baseline' => 'none', 'head' => 'main', 'modified' => ['count' => 0, 'names' => []],
            'untracked' => ['count' => 0, 'names' => []], 'stashes' => ['count' => 0, 'names' => []],
            'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];

        public int $calls = 0;

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;

            return new CommandResult(0, json_encode($this->answer, JSON_THROW_ON_ERROR), '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $this->ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    config(['orbit.tasks.copies_max' => 1]);
});

/** A nested-fleet Project and its default on a switched-on Node, as a finished create and refresh leave it: active, running, with a seed. */
function copySource(string $slug = 'trial'): Instance
{
    $node = Node::query()->where('name', 'beast')->first() ?? containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => true]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node->refresh())->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

function copyGroup(Project $project): Task
{
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
    $group->tasks()->create(['title' => 'Step', 'brief' => 'Do it', 'position' => 1, 'status' => TaskStatus::Todo]);

    return $group;
}

function startCopy(Task $group): Operation
{
    return app(TaskProvisionIntent::class)->start($group, 'test', null);
}

/** @return array<string, int> */
function copyRows(): array
{
    return ['instances' => Instance::query()->count(), 'runtimes' => InstanceRuntime::query()->count(), 'operations' => Operation::query()->count(),
        'task_vms' => TaskVm::query()->count(), 'nodes' => Node::query()->count()];
}

function copyRefusal(Closure $start): string
{
    try {
        $start();
    } catch (ResourceOperationException $error) {
        return $error->errorCode.' '.$error->status;
    }

    return 'admitted';
}

/** The copy as its successful create leaves it: active on its branch, its group running. */
function finishedCopy(Task $group, Operation $operation): Instance
{
    $operation->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();
    $copy = Instance::query()->findOrFail($operation->target_id);
    $copy->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    $group->update(['status' => TaskGroupStatus::Running]);

    return $copy->refresh();
}

/** The run of a destroy ended well. The vault copies of a container destroy are another test's matter, so this run has none. */
function destroySucceeded(Operation $destroy): void
{
    $destroy->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1'], 'input' => Arr::except($destroy->input, 'container_destroy')])->save();
    app(DevelopmentInstanceDestroyProjection::class)->apply($destroy->refresh(), ['status' => 'succeeded', 'run_id' => 'run-1',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
}

function runningOperation(Operation $operation): Operation
{
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();

    return $operation->refresh();
}

describe('the decision point', function (): void {
    it('starts a task of a nested-fleet Project as a copy and keeps every other Project on its task VM', function (): void {
        $project = containerDefaultProject();
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Vm);
        InstancePlacement::taskWorkspace($project);
        $project->update(['nested_fleet' => true]);
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Copy)
            ->and(copyRefusal(fn () => InstancePlacement::taskWorkspace($project)))->toBe('operation.kind_unavailable 503');
    });
});

describe('a task start on the copy path', function (): void {
    it('admits one clone of the default named and branched task-<id>, owned by and bound to the group, with no task VM', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $runtime = InstanceRuntime::query()->where('instance_id', $copy->id)->sole();
        $group->refresh();
        expect($operation->kind)->toBe('instance.create')
            ->and($copy->name)->toBe('task-'.$group->id)
            ->and($copy->branch_override)->toBe('task-'.$group->id)
            ->and($copy->node_id)->toBe($default->node_id)
            ->and($copy->task_workspace_owner_id)->toBe($group->id)
            ->and($runtime->incus_project)->toBe(InstancePlacement::NestedProject)
            ->and($runtime->clone_source_instance_id)->toBe($default->id)
            ->and($group->status)->toBe(TaskGroupStatus::Reserved)
            ->and($group->taskable_id)->toBe($copy->id)
            ->and(TaskVm::query()->count())->toBe(0);
        Queue::assertNotPushed(AdmitTaskVm::class);
    });

    it('replays the same create for a retry with the request\'s key', function (): void {
        $group = copyGroup(copySource()->project);
        $idempotency = app(OperationIdempotency::class);
        $start = fn (): Operation => $idempotency->under('tasks.provision', 'retry-key', 'fingerprint', fn (): Operation => startCopy(Task::topLevel()->findOrFail($group->id)));

        $first = $start();
        $second = $start();

        expect($second->id)->toBe($first->id)->and($first->idempotency_key)->toBe('retry-key')
            ->and(Instance::query()->where('task_workspace_owner_id', $group->id)->count())->toBe(1);
    });

    it('is refused by name and leaves nothing when the default cannot be copied', function (Closure $arrange): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $arrange($default);
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_source_unavailable 409')
            ->and(copyRows())->toBe($before)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($group->taskable_id)->toBeNull();
    })->with([
        // As Project 79's default: a finished fixture whose container is stopped.
        'a stopped default' => [fn (Instance $default) => $default->runtime->update(['power_state' => InstanceRuntimePowerState::Stopped])],
        'a default without a seed' => [fn (Instance $default) => $default->runtime->update(['seed_commit' => null])],
        'a default that is not active' => [fn (Instance $default) => $default->update(['status' => InstanceState::Reserved])],
        'no default' => [function (Instance $default): void {
            $default->runtime()->delete();
            $default->delete();
        }],
    ]);

    it('is refused by name and leaves nothing when the default\'s container does not run, whatever its record says', function (array $answer): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $this->ssh->answer = $answer;
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_source_unavailable 409')
            ->and($default->runtime->refresh()->power_state)->toBe(InstanceRuntimePowerState::Running)
            ->and(copyRows())->toBe($before)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Todo)
            ->and($this->ssh->calls)->toBe(1);
    })->with([
        // As Project 79's default on 2026-11-13: stopped by hand, its record still running.
        'stopped' => [['state' => 'unknown', 'reason' => 'stopped']],
        'asleep' => [['state' => 'unknown', 'reason' => 'asleep']],
        'unreachable' => [['state' => 'unknown', 'reason' => 'unreachable']],
        'silent' => [['state' => 'unknown', 'reason' => 'timeout']],
    ]);

    it('holds at most the Gateway\'s number of copies on the Node, failing copies included', function (): void {
        $default = copySource();
        startCopy(copyGroup($default->project));
        $second = copyGroup($default->project);
        $before = copyRows();

        expect(copyRefusal(fn () => startCopy($second)))->toBe('tasks.copy_capacity_full 409')
            ->and(copyRows())->toBe($before)
            ->and($second->refresh()->status)->toBe(TaskGroupStatus::Todo);
        config(['orbit.tasks.copies_max' => 2]);
        expect(startCopy($second)->kind)->toBe('instance.create');
    });

    it('refuses a group that is not todo, and a name that an Instance already holds', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        containerCreate($default->project, $default->node, 'task-'.$group->id);
        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.copy_name_taken 409');
        $group->update(['status' => TaskGroupStatus::Backlog]);
        expect(copyRefusal(fn () => startCopy($group)))->toBe('tasks.not_todo 409');
    });
});

describe('the end of the copy\'s create', function (): void {
    it('fails the group and queues the copy\'s destroy when the create fails; a clone that no group owns changes nothing', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $operation = runningOperation(startCopy($group));
        $normal = runningOperation(containerCreate($default->project, $default->node, 'feature'));
        $failed = ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'error_code' => 'instance.create_failed', 'result' => ['ready' => false]];

        app(DevelopmentInstanceCreateProjection::class)->apply($operation, [...$failed, 'result' => [...$failed['result'], 'instance_id' => $operation->target_id]]);
        app(DevelopmentInstanceCreateProjection::class)->apply($normal, [...$failed, 'result' => [...$failed['result'], 'instance_id' => $normal->target_id]]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($group->taskable_id)->toBe($operation->target_id)
            ->and($operation->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($normal->refresh()->status)->toBe(OperationStatus::Failed);
        Queue::assertPushed(DestroyTaskCopy::class, 1);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
    });

    it('runs the group when the create succeeds with the copy active, and waits while the copy is not fully set up', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $copy->update(['status' => InstanceState::SourceResolved]);

        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);

        $copy->update(['status' => InstanceState::Active]);
        expect(TaskCopyLifecycle::ready($operation->refresh(), $copy->refresh()))->toBeTrue();
        $operation->forceFill(['status' => 'running', 'finished_at' => null])->save();
        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Succeeded, 'finished_at' => now()]);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($group->started_at)->not->toBeNull();
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('fails the group and queues the destroy when the create is cancelled before its run starts', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);

        app(CancelOperationAction::class)->execute($operation);

        expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($group->taskable_id)->toBe($operation->target_id);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
    });

    it('leaves the group reserved with its copy when the create\'s budget expired, as its run may still be stopping', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        app(OperationStore::class)->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out']);

        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)->and($group->taskable_id)->toBe($operation->target_id);
        Queue::assertNotPushed(DestroyTaskCopy::class);
    });

    it('destroys a failed group\'s copy with force, and leaves the copy of a group that still runs', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = startCopy($group);
        $copy = Instance::query()->findOrFail($operation->target_id);
        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

        $group->update(['status' => TaskGroupStatus::Running]);
        new DestroyTaskCopy($copy->id)->handle(app(StartInstanceDestroyAction::class));
        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(0);

        $group->update(['status' => TaskGroupStatus::Failed]);
        new DestroyTaskCopy($copy->id)->handle(app(StartInstanceDestroyAction::class));
        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()->and($destroy->triggered_by)->toBe('tasks');
    });
});

describe('a cancel of a group that owns a copy', function (): void {
    it('records what the copy holds, admits the copy\'s destroy with force and cancels the group when the destroy succeeds', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 3, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 3]]]];

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($cancel->kind)->toBe('tasks.cancel')
            ->and($cancel->status)->toBe(OperationStatus::Succeeded)
            ->and($cancel->result['destroy_operation_id'])->toBe($destroy->id)
            ->and($cancel->result['workspace'])->toMatchArray(['state' => 'dirty', 'head' => str_repeat('e', 40), 'unpushed' => 3, 'uncommitted' => 3,
                'modified' => 1, 'untracked' => 2, 'stashes' => 0])
            ->and($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            // The group runs on until the copy is gone.
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409');

        destroySucceeded($destroy);

        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->taskable_id)->toBeNull()
            ->and($group->tasks()->firstOrFail()->status)->toBe(TaskStatus::Cancelled);
    });

    it('records a stopped copy as unknown and still admits its destroy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'stopped'];

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($cancel->result['workspace'])->toMatchArray(['state' => 'unknown', 'reason' => 'stopped', 'unpushed' => null])
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    it('is refused while the copy\'s create still runs', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and($operation->refresh()->status)->toBe(OperationStatus::Running);
    });

    it('leaves the destroy of a clone that no group owns as it was', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);
        $group->update(['status' => TaskGroupStatus::Running]);
        $clone = Instance::query()->findOrFail(containerCreate($default->project, $default->node, 'feature')->target_id);
        destroySucceeded(app(StartInstanceDestroyAction::class)->execute($clone, true));

        expect(Instance::query()->find($clone->id))->toBeNull()->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    });
});
