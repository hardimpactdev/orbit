<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceCompleteAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\FailUnreadableResultAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\ReadAgentThreadAction;
use App\Actions\Tasks\StopTaskSubtaskAction;
use App\Data\Tasks\AgentThreadData;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDestroyReplicationSlots;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Schedules\NativeSchedules;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCopyLifecycle;
use App\Domain\Tasks\TaskCopyStart;
use App\Domain\Tasks\TaskCopyTurn;
use App\Domain\Tasks\TaskCopyTurnProjection;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\Tasks\TaskRuntime;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTurnIntent;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Jobs\TaskVms\AdmitTaskVm;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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

        /** @var list<?string> */
        public array $inputs = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;
            $this->inputs[] = $command->input;

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

/**
 * A Project and its container default as a finished create (and, for a nested-fleet Project, refresh) leaves it: active, running, with
 * a seed. A nested-fleet Project's Node has its switch on; any other Project's default is a plain container.
 */
function copySource(string $slug = 'trial', bool $nested = true): Instance
{
    $node = Node::query()->where('name', 'beast')->first() ?? containerDefaultNode();
    if ($nested) {
        $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    }
    Http::fake(['api.github.com/repos/nckrtl/'.$slug => Http::response(['full_name' => 'nckrtl/'.$slug, 'private' => false])]);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/'.$slug.'.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => $nested]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node->refresh())->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    // The nested-fleet default that tasks copy today was made before the inner volume, so it records none
    // (docs/reference/development-containers.md#the-inner-volume); a test that needs the volume sets it.
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running, 'inner_volume' => null]);

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
    it('starts a task of a nested-fleet Project as a copy and keeps a Project without a container default on its task VM', function (): void {
        $project = containerDefaultProject();
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Vm);
        InstancePlacement::taskWorkspace($project);
        $project->update(['nested_fleet' => true]);
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Copy)
            ->and(copyRefusal(fn () => InstancePlacement::taskWorkspace($project)))->toBe('operation.kind_unavailable 503');
    });

    it('starts a task of a Project whose default is a container as a copy, and a default without a container keeps the task VM', function (): void {
        $default = copySource('app', nested: false);
        $project = $default->project;
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Copy);
        InstanceRuntime::query()->where('instance_id', $default->id)->delete();
        expect(InstancePlacement::taskRuntime($project))->toBe(TaskRuntime::Vm);
    });

    it('leaves the task VM writers and a group without a copy on the VM path when the default of a flag-off Project is a container', function (): void {
        $project = copySource('app', nested: false)->project;
        InstancePlacement::taskWorkspace($project);
        $group = copyGroup($project);
        $group->update(['status' => TaskGroupStatus::Running]);

        expect(copyRefusal(fn () => copyTurn($group)))->toBe('task_vm.project_unsupported 409');
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

    it('gives a task copy of a nested-fleet default with an inner volume its own volume, copied from the default\'s seed, and removes it with the copy', function (): void {
        $default = copySource();
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $container = containerIntentOf($operation);
        $clone = ValidatedData::object($container['clone']);
        // docs/reference/development-containers.md#the-inner-volume: the copy's own volume, from the default's volume snapshot of the seed's name.
        expect(InstanceRuntime::query()->where('instance_id', $copy->id)->sole()->inner_volume)->toBe('inner-'.$copy->id)
            ->and(ValidatedData::object($container['inner_volume'])['name'])->toBe('inner-'.$copy->id)
            ->and($clone['inner_volume'])->toBe('inner-'.$default->id)
            ->and($clone['snapshot'])->toBe('seed-'.str_repeat('c', 40))
            ->and(ValidatedData::object($container['allowed_devices'])['inner-pool'])->toBe(['type' => 'disk', 'pool' => InstanceHostIntent::Pool,
                'source' => 'inner-'.$copy->id, 'path' => '/srv/inner-pool']);

        $operation->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        $group->update(['status' => TaskGroupStatus::Failed]);
        new DestroyTaskCopy($copy->id)->handle(app(StartInstanceDestroyAction::class));
        expect(Operation::query()->where('kind', 'instance.destroy')->sole()->input['container_destroy'])
            ->toMatchArray(['name' => 'instance-'.$copy->id, 'inner_volume' => 'inner-'.$copy->id]);
    });

    it('refuses a copy of a default with an inner volume while its deployment retakes the seed pair; a default without one is admitted', function (bool $volume): void {
        $default = copySource();
        if ($volume) {
            InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        }
        Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $default->id, 'node_id' => $default->node_id,
            'status' => 'running', 'input' => [], 'state' => [], 'result' => null]);
        $group = copyGroup($default->project);
        $before = copyRows();

        if ($volume) {
            // docs/reference/development-containers.md#the-seed-pair: a copy must not read one half before the retake and one after.
            expect(copyRefusal(fn () => startCopy($group)))->toBe('instance.default_seed_in_flight 409')->and(copyRows())->toBe($before);
        } else {
            // Instance 2454's shape: a one-snapshot seed, admitted during a deployment as before.
            expect(startCopy($group)->kind)->toBe('instance.create');
        }
    })->with(['inner volume' => true, 'no inner volume (Instance 2454)' => false]);

    it('gives a task copy of a nested-fleet default without an inner volume (Instance 2454\'s shape) none: no volume in its record, intent or devices', function (): void {
        $default = copySource();
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $container = containerIntentOf($operation);
        expect(InstanceRuntime::query()->where('instance_id', $copy->id)->sole()->inner_volume)->toBeNull()
            ->and($container)->not->toHaveKey('inner_volume')
            ->and(ValidatedData::object($container['clone']))->not->toHaveKey('inner_volume')
            ->and(ValidatedData::object($container['allowed_devices']))->not->toHaveKey('inner-pool');
    });

    it('admits a plain clone for a Project whose default is a container: no nested project, no fleet, no Club key', function (): void {
        $default = copySource('app', nested: false);
        $group = copyGroup($default->project);

        $operation = startCopy($group);

        $copy = Instance::query()->findOrFail($operation->target_id);
        $runtime = InstanceRuntime::query()->where('instance_id', $copy->id)->sole();
        $container = containerIntentOf($operation);
        expect($operation->kind)->toBe('instance.create')
            ->and($copy->name)->toBe('task-'.$group->id)
            ->and($copy->branch_override)->toBe('task-'.$group->id)
            ->and($copy->task_workspace_owner_id)->toBe($group->id)
            ->and($runtime->incus_project)->toBe(InstanceHostIntent::Project)
            ->and($runtime->clone_source_instance_id)->toBe($default->id)
            ->and($container)->not->toHaveKey('club_key')
            ->and(ValidatedData::object($container['clone'])['clean_workspace'] ?? null)->toBeTrue()
            // The copy's runtime reads running only when the create's result is recorded, after the checkout cleaned the tree: no check,
            // baseline included, is admitted before (tasks.instance_not_running), and a turn waits for `active`.
            ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Stopped)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Reserved)
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
    ]);

    it('admits a running default whose Git read ran out of time or found no repository', function (array $answer): void {
        $group = copyGroup(copySource()->project);
        $this->ssh->answer = $answer;

        expect(startCopy($group)->kind)->toBe('instance.create')->and($this->ssh->calls)->toBe(1);
    })->with([
        'timeout' => [['state' => 'unknown', 'reason' => 'timeout']],
        'not a repository' => [['state' => 'unknown', 'reason' => 'not_a_repository']],
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

    it('caps only nested-fleet copies: a copy of a plain container Project neither counts nor waits', function (): void {
        $nested = copySource();
        startCopy(copyGroup($nested->project));
        $plain = copySource('app', nested: false);

        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create')
            ->and(TaskCopyStart::live($nested->node_id))->toBe(1);
        config(['orbit.tasks.copies_max' => 0]);
        expect(startCopy(copyGroup($plain->project))->kind)->toBe('instance.create')
            ->and(copyRefusal(fn () => startCopy(copyGroup($nested->project))))->toBe('tasks.copy_capacity_full 409');
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

    it('runs the group when the copy\'s completion makes it active, and fails the group when the completion fails', function (): void {
        $default = copySource();
        config(['orbit.tasks.copies_max' => 2]);
        $ends = [];
        foreach ([OperationStatus::Succeeded, OperationStatus::Failed] as $status) {
            $group = copyGroup($default->project);
            $create = startCopy($group);
            $copy = Instance::query()->findOrFail($create->target_id);
            // The create succeeded at the thread; the copy waits for its completion.
            $copy->update(['status' => InstanceState::SourceResolved]);
            app(OperationStore::class)->change($create, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
            $completion = Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $copy->id, 'node_id' => $copy->node_id,
                'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
            expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);
            if ($status === OperationStatus::Succeeded) {
                $copy->update(['status' => InstanceState::Active]);
            }
            app(OperationStore::class)->change($completion, ['status' => $status, 'finished_at' => now(),
                ...($status === OperationStatus::Failed ? ['error_code' => 'instance.container_process_inactive'] : [])]);
            $ends[] = [$group->refresh()->status, $copy->id];
        }
        expect([$ends[0][0], $ends[1][0]])->toBe([TaskGroupStatus::Running, TaskGroupStatus::Failed]);
        Queue::assertPushed(DestroyTaskCopy::class, 1);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $ends[1][1]);
    });

    it('fails the group and queues the destroy when the create\'s end records a refused completion, instead of leaving it reserved', function (): void {
        $group = copyGroup(copySource()->project);
        $create = startCopy($group);
        $copy = Instance::query()->findOrFail($create->target_id);
        $copy->update(['status' => InstanceState::SourceResolved]);
        app(OperationStore::class)->change($create, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Reserved);

        // What DevelopmentInstanceCreateProjection records when the completion's admission refuses at the create's end.
        $completion = app(StartInstanceCompleteAction::class)->refused($copy, $create->refresh(), 'cluster.router_required', 'The Cluster Route requires a recorded Router.');

        expect([$completion->status, $completion->error_code])->toBe([OperationStatus::Failed, 'cluster.router_required'])
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $copy->id);
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

    it('fails the group and queues the destroy when the create\'s run ended without a readable result', function (): void {
        $group = copyGroup(copySource()->project);
        $operation = runningOperation(startCopy($group));

        app(FailUnreadableResultAction::class)->execute($operation, 'run-1', ['status' => 'succeeded', 'completedAt' => now()->toIso8601ZuluString()], 'finish', 'skipped');

        expect($operation->refresh()->error_code)->toBe('operation.result_unreadable')
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
        Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $operation->target_id);
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
    it('refuses a copy that holds work origin lacks, with its counts, and changes nothing', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 3, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 3]]]];

        try {
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
            $this->fail('The cancel destroyed unpublished work.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_unpublished_work')->and($error->status)->toBe(409)
                ->and($error->details)->toBe(['head' => str_repeat('e', 40), 'unpushed' => 3, 'uncommitted' => 3, 'modified' => 1, 'untracked' => 2, 'stashes' => 0]);
        }
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    });

    it('records what the copy holds, admits the copy\'s destroy with force on discard and cancels the group when the destroy succeeds', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 2, 'names' => ['b', 'c']],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 3, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 3]]]];

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($cancel->kind)->toBe('tasks.cancel')
            ->and($cancel->status)->toBe(OperationStatus::Succeeded)
            ->and($cancel->result['destroy_operation_id'])->toBe($destroy->id)
            ->and($cancel->result['workspace'])->toMatchArray(['state' => 'dirty', 'head' => str_repeat('e', 40), 'unpushed' => 3, 'uncommitted' => 3,
                'modified' => 1, 'untracked' => 2, 'stashes' => 0])
            ->and($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            // The group runs on until the copy is gone.
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running);
        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true)))->toBe('operation.target_busy 409');

        destroySucceeded($destroy);

        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($group->taskable_id)->toBeNull()
            ->and($group->tasks()->firstOrFail()->status)->toBe(TaskStatus::Cancelled);
    });

    it('refuses a copy it cannot read unless the caller discards it, then records it as unknown and admits its destroy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $this->ssh->answer = ['state' => 'unknown', 'reason' => 'stopped'];

        try {
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
            $this->fail('The cancel destroyed an unread copy.');
        } catch (ResourceOperationException $error) {
            expect($error->errorCode)->toBe('tasks.copy_workspace_unknown')->and($error->status)->toBe(409)->and($error->details)->toBe(['reason' => 'stopped']);
        }
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse();

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);

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

    it('is refused before the copy\'s destroy while a timed-out Operation still holds the group', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $held = Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => [], 'status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out',
            'state' => ['swamp_run_id' => '5d0c3a8e-4f7b-4a51-9a63-0e2b1c7d9f10', 'native_timeout' => ['budget_seconds' => 900]], 'sequence' => 1, 'attempts' => 1,
            'started_at' => now()->subMinutes(20), 'finished_at' => now()->subMinutes(5)]);

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull()
            ->and($held->refresh()->lock_key)->not->toBeNull();
    });

    it('names only the commits the group published with success to the read, so a published copy with no later work cancels without discard', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $published = str_repeat('f', 40);
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => $copy->name, 'commit' => $published], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);
        // A failed publish of this group and a publish of another group pushed nothing of this copy: neither commit reaches the read.
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => $copy->name, 'commit' => str_repeat('a', 40)], 'status' => OperationStatus::Failed, 'sequence' => 2, 'attempts' => 1]);
        $other = copyGroup($group->project);
        Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $other->id, 'node_id' => $copy->node_id,
            'input' => [], 'result' => ['branch' => 'other', 'commit' => str_repeat('b', 40)], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1]);

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($cancel->result['workspace']['state'])->toBe('clean')
            ->and(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue()
            ->and(last($this->ssh->inputs))->toContain('PUBLISHED = json.loads(base64.b64decode("'.base64_encode(json_encode([$published])).'"))');
    });

    it('reads the copy, its replication slots and its Schedules outside the transaction that records the cancel and the destroy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $outside = DB::transactionLevel();
        $levels = new ArrayObject;
        app()->instance(InstanceDestroyReplicationSlots::class, new class($levels)
        {
            public function __construct(private ArrayObject $levels) {}

            public function assertNone(array $input): void
            {
                $this->levels['slots'] = DB::transactionLevel();
            }
        });
        app()->instance(NativeSchedules::class, new class($levels)
        {
            public function __construct(private ArrayObject $levels) {}

            public function removeForInstances(array $instanceIds): void
            {
                $this->levels['schedules'] = DB::transactionLevel();
            }
        });
        Operation::creating(function (Operation $operation) use ($levels): void {
            $levels[$operation->kind] = DB::transactionLevel();
        });

        app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($levels['slots'])->toBe($outside)->and($levels['schedules'])->toBe($outside)
            ->and($levels['tasks.cancel'])->toBeGreaterThan($outside)
            ->and(Operation::query()->where('kind', 'instance.destroy')->sole()->id)
            ->toBe(Operation::query()->where('kind', 'tasks.cancel')->sole()->result['destroy_operation_id']);
    });

    it('refuses a dirty copy over HTTP with its counts, and admits its destroy when the request discards it', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
        $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => 'task-1',
            'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 0, 'names' => []],
            'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 2, 'branch_count' => 1, 'branches' => [['name' => 'task-1', 'count' => 2]]]];

        $this->withHeader('Idempotency-Key', 'cancel-dirty')->postJson("/api/v1/tasks/{$group->id}/cancel")
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.copy_unpublished_work')
            ->assertJsonPath('error.details.unpushed', 2)->assertJsonPath('error.details.uncommitted', 1);
        expect(Operation::query()->whereIn('kind', ['instance.destroy', 'tasks.cancel'])->exists())->toBeFalse();

        $this->withHeader('Idempotency-Key', 'cancel-discard')->postJson("/api/v1/tasks/{$group->id}/cancel", ['discard' => true])
            ->assertStatus(202)->assertJsonPath('data.kind', 'tasks.cancel');
        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    it('admits no destroy when another Operation takes the group lock after the cancel\'s checks', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        // The race: the first Operation the cancel writes after its checks finds the group lock taken by another Operation.
        $raced = false;
        Operation::creating(function (Operation $operation) use ($group, $copy, &$raced): void {
            if ($raced || $operation->kind === 'tasks.publish') {
                return;
            }
            $raced = true;
            Operation::query()->create(['kind' => 'tasks.publish', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $copy->node_id,
                'lock_key' => OperationStore::lockKey('task_group', $group->id), 'input' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);
        });

        expect(copyRefusal(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)))->toBe('operation.target_busy 409')
            ->and($raced)->toBeTrue()
            ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse()
            ->and(Operation::query()->where('kind', 'tasks.cancel')->exists())->toBeFalse()
            ->and(Instance::query()->find($copy->id))->not->toBeNull();
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

describe('a complete of a group that owns a copy', function (): void {
    it('removes a copy with an inner volume and its volume in the one destroy that a cancel or a complete admits', function (string $end): void {
        $default = copySource();
        InstanceRuntime::query()->where('instance_id', $default->id)->update(['inner_volume' => 'inner-'.$default->id]);
        $group = copyGroup($default->project);
        $copy = finishedCopy($group, startCopy($group));
        if ($end === 'cancel') {
            $this->ssh->answer = ['state' => 'dirty', 'baseline' => 'recorded', 'head' => str_repeat('e', 40),
                'modified' => ['count' => 1, 'names' => ['a.php']], 'untracked' => ['count' => 0, 'names' => []],
                'stashes' => ['count' => 0, 'names' => []], 'commits' => ['count' => 0, 'branch_count' => 0, 'branches' => []]];
            app(TaskCancelIntent::class)->start($group->refresh(), 'test', null, discard: true);
        } else {
            $group->update(['status' => TaskGroupStatus::Settling]);
            $group->tasks()->update(['status' => TaskStatus::Completed]);
            app(CompleteTaskGroupAction::class)->execute($group->refresh());
        }

        // docs/reference/development-containers.md#copies-of-the-volume: the copy's own volume, never the default's.
        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)
            ->and($destroy->input['container_destroy'])->toMatchArray(['name' => 'instance-'.$copy->id, 'inner_volume' => 'inner-'.$copy->id]);
    })->with(['cancel', 'complete']);

    it('admits the copy\'s destroy with force, completes the group and lets go of the copy when the destroy succeeds', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);

        $completed = app(CompleteTaskGroupAction::class)->execute($group->refresh());

        $destroy = Operation::query()->where('kind', 'instance.destroy')->sole();
        expect($destroy->target_id)->toBe($copy->id)->and($destroy->input['force'])->toBeTrue()
            ->and($completed->status)->toBe(TaskGroupStatus::Completed)
            ->and($completed->taskable_id)->toBe($copy->id)
            ->and($completed->assistance_reason)->toBeNull();
        // A repeated complete reuses the open destroy.
        app(CompleteTaskGroupAction::class)->execute($group->refresh());
        expect(Operation::query()->where('kind', 'instance.destroy')->count())->toBe(1);

        destroySucceeded($destroy);

        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->taskable_id)->toBeNull();
    });

    it('keeps the copy of a completed group when its destroy fails, and admits a new destroy on the next complete', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $group->update(['status' => TaskGroupStatus::Settling]);
        $group->tasks()->update(['status' => TaskStatus::Completed]);
        app(CompleteTaskGroupAction::class)->execute($group->refresh());
        Operation::query()->where('kind', 'instance.destroy')->sole()->forceFill(['status' => 'failed', 'finished_at' => now(), 'lock_key' => null])->save();

        app(CompleteTaskGroupAction::class)->execute($group->refresh());

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->count())->toBe(2)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Completed)
            ->and($group->taskable_id)->toBe($copy->id);
    });
});

describe('a stop of a subtask in a copy', function (): void {
    it('sends no interrupt to a t3 thread, which has none, and does not fail; the subtask stays as it was', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $subtask->id, 'node_id' => $copy->node_id, 'driver' => 't3',
            'runtime_key' => 'instance:'.$copy->id, 'external_id' => (string) Str::uuid(), 'role' => 'implementer', 'model' => 'gpt-5.6-luna', 'effort' => null]);
        $subtask->update(['status' => TaskStatus::Running, 'implementer_agent_thread_id' => $thread->id]);

        app(StopTaskSubtaskAction::class)->execute($group->refresh(), $subtask->refresh());

        expect($subtask->refresh()->status)->toBe(TaskStatus::Running);
    });
});

/** One turn of the group's current subtask, as `task:turn` sends it. */
function copyTurn(Task $group, array $change = []): Operation
{
    // A finished create leaves the copy's container running.
    Instance::query()->find($group->refresh()->taskable_id)?->runtime?->update(['power_state' => InstanceRuntimePowerState::Running]);
    $subtask = $group->tasks()->firstOrFail();

    return app(TaskTurnIntent::class)->start($group->refresh(), TaskTurnData::from([
        'subtask' => $subtask->id, 'role' => 'implementer', 'prompt' => 'Run Doctor and commit a note.', ...$change,
    ]), 'test', null);
}

/** The copy turn's run ended with this shaped turn, as `@nckrtl/task-copy-turn` writes it. */
function copyTurnEnded(Operation $turn, string $status, array $shaped): Operation
{
    $turn = runningOperation($turn);

    return app(TaskCopyTurnProjection::class)->apply($turn, ['status' => $status, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => ['turn' => $shaped]]);
}

/** The shaped turn of a completed run in T3 thread THREAD. */
function copyTurnDone(string $thread): array
{
    return ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => $thread, 'run_id' => 'run:1', 'provider_instance' => 'claudeMedium',
        'model' => 'claude-opus-5-5', 'questions' => [], 'transcript' => []];
}

/** The shaped turn of a run in T3 thread THREAD that asked one question and whose run Orbit stopped. */
function copyTurnAsked(string $thread): array
{
    return [...copyTurnDone($thread), 'state' => 'asking_for_input', 'questions' => [['request_id' => 'request:1', 'id' => 'Which color?', 'header' => 'Color',
        'question' => 'Which color?', 'options' => [['label' => 'Red', 'description' => 'Choose red', 'value' => null], ['label' => 'Blue', 'description' => 'Choose blue', 'value' => null]],
        'multi_select' => false, 'allow_custom_answer' => null, 'initial_answer' => null, 'required' => null]]];
}

/** A passed handoff check of the group's subtask in its copy, with the check Operation that records it: the reviewer's candidate. */
function copyCandidate(Task $group): TaskCheck
{
    $subtask = $group->tasks()->firstOrFail();
    $head = str_repeat('d', 40);
    $check = TaskCheck::query()->create(['task_id' => $subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Passed, 'pid' => 1, 'process_started' => 'x',
        'head_before' => str_repeat('1', 40), 'tree_before' => str_repeat('2', 40), 'head_after' => str_repeat('3', 40), 'tree_after' => str_repeat('4', 40),
        'commit_sha' => $head, 'exit_code' => 0, 'output' => '', 'started_at' => now(), 'finished_at' => now()]);
    Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $group->id, 'node_id' => null,
        'input' => ['subtask' => $subtask->id, 'phase' => 'handoff', 'check_id' => $check->id], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1,
        'result' => ['outcome' => 'passed', 'check_id' => $check->id, 'commit' => $head, 'head' => $check->head_after, 'tree' => $check->tree_after, 'ci' => null]]);

    return $check;
}

describe('a turn of a group that owns a copy', function (): void {
    it('admits one tasks.copy_turn on the copy\'s Node with the composed prompt and the copy\'s model, and names no credential', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));

        $turn = copyTurn($group);

        $thread = AgentThread::query()->where('task_group_id', $group->id)->sole();
        expect($turn->kind)->toBe('tasks.copy_turn')
            ->and($turn->node_id)->toBe($copy->node_id)
            ->and($turn->input['runtime'])->toBe(['project' => InstancePlacement::NestedProject, 'name' => $copy->runtime->container_name])
            ->and($turn->input['branch'])->toBe('task-'.$group->id)
            ->and(Arr::except($turn->input['turn'], 'prompt'))->toBe(['t3' => '/opt/orbit-agent/current/agent/bin/t3', 'node' => '/opt/orbit-runtime/current/bin/node',
                'thread' => 'new', 'provider' => 'claudeMedium', 'model' => 'claude-opus-5-5', 'candidate' => null, 'seconds' => 3600,
                'title' => 'Orbit task '.$group->id.' thread '.$thread->id])
            // The implementer learns how to ask: a question ends the turn.
            ->and($turn->input['turn']['prompt'])->toBe("Subtask: Step\n\nBrief:\nDo it\n\nPrompt of this turn:\nRun Doctor and commit a note.\n\n"
                .'To ask a question, use your question tool. Asking ends this turn; the answer comes as the next turn.')
            ->and($thread->only(['driver', 'role', 'model', 'effort', 'node_id']))->toBe(['driver' => 't3', 'role' => 'implementer', 'model' => 'claude-opus-5-5',
                'effort' => 'medium', 'node_id' => $copy->node_id])
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($thread->id)
            ->and(TaskVm::query()->count())->toBe(0)
            // T3 issues its own session inside the copy: no vault key, no token.
            ->and(app(DirectOperation::class)->definition('tasks.copy_turn'))->not->toHaveKey('execution_copies')
            ->and(json_encode($turn->input, JSON_THROW_ON_ERROR))->not->toContain('vault')->not->toContain('token')->not->toContain('session');
    });

    it('admits a tasks.copy_turn in the copy of a plain container Project, in that copy\'s own Incus project', function (): void {
        $group = copyGroup(copySource('app', nested: false)->project);
        $copy = finishedCopy($group, startCopy($group));

        $turn = copyTurn($group);

        expect($turn->kind)->toBe('tasks.copy_turn')
            ->and($turn->input['runtime'])->toBe(['project' => InstanceHostIntent::Project, 'name' => $copy->runtime->container_name])
            ->and($turn->input['branch'])->toBe('task-'.$group->id)
            ->and(TaskVm::query()->count())->toBe(0);
    });

    it('takes the model and effort of a new thread from its role\'s settings, through the provider instance of that effort', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        config(['orbit.tasks.copy_turn_models' => ['implementer' => 'claude-sonnet-5-5', 'reviewer' => 'claude-haiku-5-5']]);

        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $reviewer = copyTurn($group, ['role' => 'reviewer', 'check' => copyCandidate($group)->id]);

        // The shipped efforts: medium for the implementer, high for the reviewer.
        expect($implementer->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeMedium', 'model' => 'claude-sonnet-5-5'])
            ->and($reviewer->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeHigh', 'model' => 'claude-haiku-5-5'])
            ->and(AgentThread::query()->findOrFail($implementer->input['thread_id'])->only(['model', 'effort']))->toBe(['model' => 'claude-sonnet-5-5', 'effort' => 'medium'])
            ->and(AgentThread::query()->findOrFail($reviewer->input['thread_id'])->only(['model', 'effort']))->toBe(['model' => 'claude-haiku-5-5', 'effort' => 'high']);
    });

    it('refuses a new thread whose role\'s model or effort the copy cannot run, and records nothing', function (array $settings, string $refusal): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        config($settings);
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group)))->toBe($refusal)
            ->and(AgentThread::query()->count())->toBe(0)->and(Operation::query()->count())->toBe($before);
    })->with([
        'a provider in the model' => [['orbit.tasks.copy_turn_models.implementer' => 'claudeAgent/claude-opus-5-5'], 'tasks.copy_turn_model_invalid 503'],
        'an effort Claude Code has no instance for' => [['orbit.tasks.implementer_effort' => 'minimal'], 'tasks.copy_turn_effort_invalid 503'],
    ]);

    it('gives a task copy\'s T3 one Claude provider instance per effort, each with CLAUDE_CODE_EFFORT_LEVEL', function (): void {
        $group = copyGroup(copySource()->project);

        $files = collect(ValidatedData::object(containerIntentOf(startCopy($group))['clone'])['files'])->keyBy('path');

        $instances = $files['/home/agent/.t3/userdata/settings.json']['json']['providerInstances'];
        expect(array_keys($instances))->toBe(['claudeLow', 'claudeMedium', 'claudeHigh', 'claudeXhigh', 'claudeMax'])
            ->and($instances['claudeMedium'])->toBe(['driver' => 'claudeAgent', 'displayName' => 'Claude, medium effort',
                'environment' => [['name' => 'CLAUDE_CODE_EFFORT_LEVEL', 'value' => 'medium']]])
            ->and($files['/home/agent/.t3/userdata/settings.json']['json']['linkedEnvironmentsEnabled'])->toBeTrue();
    });

    it('continues the role\'s thread without a thread id: the same Orbit thread and T3 title, its own model and effort, and no new thread', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        copyTurnEnded($first, 'succeeded', copyTurnDone('mcp:66105c2c'));
        config(['orbit.tasks.copy_turn_models.implementer' => 'claude-sonnet-5-5', 'orbit.tasks.implementer_effort' => 'high']);

        $next = copyTurn($group, ['prompt' => 'Fix the failing test.']);
        copyTurnEnded($next, 'succeeded', copyTurnDone('mcp:66105c2c'));
        // --thread=ID may name the thread the turn continues.
        $named = copyTurn($group, ['thread' => (string) $first->input['thread_id']]);

        expect($next->input['thread_id'])->toBe($first->input['thread_id'])
            ->and(Arr::except($next->input['turn'], 'prompt'))->toBe(['t3' => '/opt/orbit-agent/current/agent/bin/t3', 'node' => '/opt/orbit-runtime/current/bin/node',
                'thread' => 'continue', 'provider' => null, 'model' => 'claude-opus-5-5', 'candidate' => null, 'seconds' => 3600, 'title' => $first->input['turn']['title']])
            ->and($next->input['turn']['prompt'])->toEndWith("Prompt of this turn:\nFix the failing test.\n\n".TaskCopyTurn::AskRule)
            ->and($named->input['thread_id'])->toBe($first->input['thread_id'])->and($named->input['turn']['thread'])->toBe('continue')
            ->and(AgentThread::query()->where('task_group_id', $group->id)->sole()->effort)->toBe('medium')
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($first->input['thread_id']);
    });

    it('starts a new thread of the role on --thread=new, which later turns then continue', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $first = copyTurn($group);
        copyTurnEnded($first, 'succeeded', copyTurnDone('mcp:1'));

        $fresh = copyTurn($group, ['thread' => 'new']);
        copyTurnEnded($fresh, 'succeeded', copyTurnDone('mcp:2'));
        $next = copyTurn($group);

        expect($fresh->input['thread_id'])->not->toBe($first->input['thread_id'])->and($fresh->input['turn']['thread'])->toBe('new')
            ->and($next->input['thread_id'])->toBe($fresh->input['thread_id'])->and($next->input['turn']['thread'])->toBe('continue')
            ->and($group->tasks()->firstOrFail()->implementer_agent_thread_id)->toBe($fresh->input['thread_id']);
    });

    it('starts a new thread when the role\'s recorded thread is not a T3 thread of this copy', function (): void {
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $earlier = AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3', 'runtime_key' => 'instance:'.($copy->id + 1000),
            'external_id' => 'mcp:old', 'role' => 'implementer']);
        $group->tasks()->firstOrFail()->update(['implementer_agent_thread_id' => $earlier->id]);

        $turn = copyTurn($group);

        expect($turn->input['thread_id'])->not->toBe($earlier->id)->and($turn->input['turn']['thread'])->toBe('new');
    });

    it('runs a reviewer turn on the checked candidate in its own thread, recorded on the group, and continues it', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $implementer = copyTurn($group);
        copyTurnEnded($implementer, 'succeeded', copyTurnDone('mcp:1'));
        $check = copyCandidate($group);

        $review = copyTurn($group, ['role' => 'reviewer', 'check' => $check->id, 'prompt' => 'Review the checked commit.']);
        copyTurnEnded($review, 'succeeded', copyTurnDone('mcp:2'));
        $again = copyTurn($group, ['role' => 'reviewer', 'check' => $check->id]);

        $thread = AgentThread::query()->findOrFail($review->input['thread_id']);
        expect($thread->id)->not->toBe($implementer->input['thread_id'])
            ->and($thread->only(['driver', 'role', 'model', 'effort', 'external_id']))->toBe(['driver' => 't3', 'role' => 'reviewer', 'model' => 'claude-opus-5-5',
                'effort' => 'high', 'external_id' => 'mcp:2'])
            ->and($review->input)->toMatchArray(['role' => 'reviewer', 'check' => $check->id, 'candidate' => $check->commit_sha])
            // The phase checks the candidate out for the reviewer, and the prompt names it.
            ->and($review->input['turn'])->toMatchArray(['thread' => 'new', 'provider' => 'claudeHigh', 'candidate' => $check->commit_sha])
            ->and($review->input['turn']['prompt'])->toContain("Prompt of this turn:\nReview the checked commit.\n\nThe checked commit under review: {$check->commit_sha}.")
            ->and($again->input['turn']['candidate'])->toBe($check->commit_sha)
            ->and($group->refresh()->reviewer_agent_thread_id)->toBe($thread->id)
            ->and($again->input['thread_id'])->toBe($thread->id)->and($again->input['turn']['thread'])->toBe('continue');
    });

    it('refuses a thread id that is not the role\'s thread this turn continues', function (Closure $thread): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $own = copyTurn($group);
        copyTurnEnded($own, 'succeeded', copyTurnDone('mcp:1'));
        $other = $thread($group, $copy, AgentThread::query()->findOrFail($own->input['thread_id']));
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group, ['thread' => (string) $other])))->toBe('tasks.thread_role_mismatch 422')
            ->and(Operation::query()->count())->toBe($before);
    })->with([
        'the implementer\'s thread for a reviewer turn' => [function (Task $group, Instance $copy, AgentThread $own): int {
            $own->update(['role' => 'reviewer']);

            return $own->id;
        }],
        'a task VM thread' => [fn (Task $group, Instance $copy, AgentThread $own): int => AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 'pi',
            'runtime_key' => 'node:'.$copy->node_id, 'external_id' => 'pi-session', 'role' => 'implementer'])->id],
        'a thread of an earlier copy' => [fn (Task $group, Instance $copy, AgentThread $own): int => AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3',
            'runtime_key' => 'instance:'.($copy->id + 1000), 'external_id' => 'mcp:old', 'role' => 'implementer'])->id],
        'a thread of another group' => [function (Task $group, Instance $copy, AgentThread $own): int {
            $elsewhere = Task::query()->create(['project_id' => $group->project_id, 'title' => 'Other', 'brief' => 'Other', 'status' => TaskGroupStatus::Todo]);

            return AgentThread::query()->create(['task_group_id' => $elsewhere->id, 'driver' => 't3', 'runtime_key' => 'instance:'.$copy->id,
                'external_id' => 'mcp:other', 'role' => 'implementer'])->id;
        }],
        'no such thread' => [fn (Task $group, Instance $copy, AgentThread $own): int => $own->id + 1000],
    ]);

    it('replays the same turn for a retry with the request\'s key', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $idempotency = app(OperationIdempotency::class);
        $start = fn (): Operation => $idempotency->under('tasks.turn', 'turn-key', 'fingerprint', fn (): Operation => copyTurn(Task::topLevel()->findOrFail($group->id)));

        $first = $start();

        expect($start()->id)->toBe($first->id)->and($first->idempotency_key)->toBe('turn-key')
            ->and(AgentThread::query()->where('task_group_id', $group->id)->count())->toBe(1);
    });

    it('is refused by name and records nothing before the copy is active, or for what a copy turn does not do yet', function (Closure $arrange, array $change, string $refusal): void {
        $group = copyGroup(copySource()->project);
        $arrange($group, startCopy($group));
        $before = Operation::query()->count();

        expect(copyRefusal(fn () => copyTurn($group, $change)))->toBe($refusal)
            ->and(Operation::query()->count())->toBe($before)
            ->and(AgentThread::query()->count())->toBe(0);
    })->with([
        'a copy still being created' => [fn (Task $group, Operation $create) => runningOperation($create), [], 'tasks.copy_not_ready 409'],
        'a copy not fully set up' => [function (Task $group, Operation $create): void {
            finishedCopy($group, $create)->update(['status' => InstanceState::Reserved]);
        }, [], 'tasks.copy_not_ready 409'],
        'a consult' => [fn (Task $group, Operation $create) => finishedCopy($group, $create), ['role' => 'consult'], 'tasks.copy_turn_unsupported 422'],
        'a reviewer turn on no checked candidate' => [fn (Task $group, Operation $create) => finishedCopy($group, $create), ['role' => 'reviewer', 'check' => 1], 'tasks.commit_not_checked 422'],
        'a model of the caller' => [fn (Task $group, Operation $create) => finishedCopy($group, $create), ['model' => 'gpt-5.6-luna'], 'tasks.copy_turn_unsupported 422'],
    ]);

    it('refuses a nested-fleet group without a copy instead of reaching for a task VM', function (): void {
        $group = copyGroup(copySource()->project);
        $group->update(['status' => TaskGroupStatus::Running]);

        expect(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.no_workspace 409')
            ->and(TaskVm::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(1);
        Queue::assertNotPushed(AdmitTaskVm::class);
    });

    it('records a completed turn on its thread, with T3\'s thread id and a transcript that outlives the copy', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $copy = finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);
        $transcript = [['id' => 'run:1:0', 'kind' => 'message', 'label' => 'user', 'text' => 'Run Doctor.', 'at' => ''],
            ['id' => 'run:1:1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Committed d867aba2.', 'at' => '']];

        $ended = copyTurnEnded($turn, 'succeeded', ['state' => 'done', 'error' => null, 'ended' => true, 't3_thread_id' => 'mcp:66105c2c', 'run_id' => 'run:1', 'transcript' => $transcript]);

        $thread = AgentThread::query()->where('task_group_id', $group->id)->sole();
        expect($ended->status)->toBe(OperationStatus::Succeeded)
            ->and($ended->result)->toMatchArray(['state' => 'done', 't3_thread_id' => 'mcp:66105c2c'])->and($ended->result)->not->toHaveKey('transcript')
            ->and($thread->state)->toBe(AgentThreadState::Done)->and($thread->external_id)->toBe('mcp:66105c2c')->and($thread->tokens)->toBeNull();

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);
        destroySucceeded(Operation::query()->findOrFail($cancel->result['destroy_operation_id']));

        $read = app(ReadAgentThreadAction::class)->execute($group->refresh(), $thread->refresh(), null);
        expect(Instance::query()->find($copy->id))->toBeNull()
            ->and($read['archived'])->toBeTrue()
            ->and(array_column($read['data']['entries'] ?? $read['entries'] ?? [], 'text'))->toBe(['Run Doctor.', 'Committed d867aba2.']);
    });

    it('records a turn that ran past its bound as failed with tasks.turn_timeout', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $timedOut = copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'The turn ran longer than its bound.',
            'error_code' => 'tasks.turn_timeout', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => null, 'transcript' => []]);

        expect($timedOut->error_code)->toBe('tasks.turn_timeout')
            ->and(AgentThread::query()->findOrFail($timedOut->input['thread_id'])->only(['state', 'error']))
            ->toBe(['state' => AgentThreadState::Failed, 'error' => 'The turn ran longer than its bound.']);
    });

    it('is stopped by the group\'s cancel before the copy\'s destroy is admitted', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        $cancel = app(TaskCancelIntent::class)->start($group->refresh(), 'test', null);

        expect($turn->refresh()->status)->toBe(OperationStatus::Cancelled)
            ->and(Operation::query()->findOrFail($cancel->result['destroy_operation_id'])->kind)->toBe('instance.destroy');
    });

    it('refuses more turns and checks while an earlier turn may still run in the copy, until task:cancel', function (string $status, array $shaped): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $cut = copyTurnEnded(copyTurn($group), $status, $shaped);
        $subtask = $group->tasks()->firstOrFail();

        // The default turn continues the same thread: a run whose end is unknown holds it too.
        expect(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.copy_turn_unended 409')
            ->and(copyRefusal(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)))
            ->toBe('tasks.copy_turn_unended 409')
            ->and(Operation::query()->where('kind', 'tasks.check')->exists())->toBeFalse()
            // A cancelled turn records nothing on its thread.
            ->and(AgentThread::query()->findOrFail($cut->input['thread_id'])->state)->toBe($status === 'cancelled' ? null : AgentThreadState::Failed);
        try {
            copyTurn($group);
        } catch (ResourceOperationException $error) {
            expect($error->details)->toBe(['operation_id' => $cut->id]);
        }

        expect(app(TaskCancelIntent::class)->start($group->refresh(), 'test', null)->status)->toBe(OperationStatus::Succeeded);
    })->with([
        'a wait cut by the bound' => ['failed', ['state' => 'failed', 'error' => 'Bound.', 'error_code' => 'tasks.turn_timeout', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => null, 'transcript' => []]],
        'a cancelled run' => ['cancelled', []],
        'a phase that wrote no end' => ['failed', ['state' => 'failed', 'error' => 'No end.', 'error_code' => 'tasks.copy_turn_failed', 'ended' => false, 't3_thread_id' => null, 'run_id' => null, 'transcript' => []]],
        'a reviewer checkout left unrestored' => ['failed', ['state' => 'failed', 'error' => 'Unrestored.', 'error_code' => 'tasks.copy_review_unrestored', 'ended' => false, 't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'transcript' => []]],
    ]);

    it('admits the next turn after a turn whose T3 run ended, also a failed one', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        copyTurnEnded(copyTurn($group), 'failed', ['state' => 'failed', 'error' => 'T3 run failed: api_error_529', 'error_code' => 'tasks.turn_failed', 'ended' => true,
            't3_thread_id' => 'mcp:1', 'run_id' => 'run:1', 'transcript' => []]);

        expect(copyTurn($group)->kind)->toBe('tasks.copy_turn');
    });

    it('records a turn that asked as a succeeded Operation with its questions on the thread, and holds no other turn or check', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        $thread = AgentThread::query()->findOrFail($asked->input['thread_id']);
        expect($asked->status)->toBe(OperationStatus::Succeeded)->and($asked->error_code)->toBeNull()
            ->and($asked->result)->toMatchArray(['state' => 'asking_for_input', 'ended' => true, 'questions' => copyTurnAsked('mcp:1')['questions']])
            ->and($thread->state)->toBe(AgentThreadState::AskingForInput)
            ->and($thread->questions)->toBe(copyTurnAsked('mcp:1')['questions'])
            // task:agent:list reads the question with the thread.
            ->and(AgentThreadData::fromModel($thread)->toArray()['questions'])->toBe(copyTurnAsked('mcp:1')['questions']);

        // The stopped run holds nothing: a new thread and a check are admitted.
        $fresh = copyTurn($group, ['thread' => 'new']);
        copyTurnEnded($fresh, 'succeeded', copyTurnDone('mcp:2'));
        expect(app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)->kind)->toBe('tasks.check');
    });

    it('quotes the questions of a thread\'s last turn before the answer that continues it, and clears them when that turn is recorded', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $asked = copyTurnEnded(copyTurn($group), 'succeeded', copyTurnAsked('mcp:1'));

        $answer = copyTurn($group, ['prompt' => 'Blue.']);

        expect($answer->input['thread_id'])->toBe($asked->input['thread_id'])->and($answer->input['turn']['thread'])->toBe('continue')
            ->and($answer->input['turn']['prompt'])->toEndWith("Prompt of this turn:\nYou asked in your last turn:\n[Color] Which color?\n- Red: Choose red\n"
                ."- Blue: Choose blue\n\nAnswer:\nBlue.\n\n".TaskCopyTurn::AskRule);
        copyTurnEnded($answer, 'succeeded', copyTurnDone('mcp:1'));
        expect(AgentThread::query()->findOrFail($asked->input['thread_id'])->questions)->toBeNull()
            // A thread that asked nothing last time quotes nothing.
            ->and(copyTurn($group, ['prompt' => 'Next.'])->input['turn']['prompt'])->not->toContain('You asked');
    });

    it('holds every turn, its own thread\'s included, and every check while a run that asked was not stopped', function (string $code): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $subtask = $group->tasks()->firstOrFail();
        $held = copyTurnEnded(copyTurn($group), 'failed', [...copyTurnAsked('mcp:1'), 'ended' => false, 'error' => 'Not stopped.', 'error_code' => $code]);

        expect($held->error_code)->toBe($code)
            ->and(AgentThread::query()->findOrFail($held->input['thread_id'])->state)->toBe(AgentThreadState::AskingForInput)
            ->and(copyRefusal(fn () => copyTurn($group)))->toBe('tasks.copy_turn_unended 409')
            ->and(copyRefusal(fn () => copyTurn($group, ['thread' => 'new'])))->toBe('tasks.copy_turn_unended 409')
            ->and(copyRefusal(fn () => app(TaskCheckIntent::class)->start($group->refresh(), new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null)))
            ->toBe('tasks.copy_turn_unended 409');
    })->with(['tasks.copy_question_unstopped', 'tasks.copy_question_unread']);

    it('names the provider instance, the model and the effort that instance carries in the turn\'s result', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));

        $done = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:1'), 'provider_instance' => 'claudeXhigh']);
        $foreign = copyTurnEnded(copyTurn($group), 'succeeded', [...copyTurnDone('mcp:1'), 'provider_instance' => 'codex']);

        expect($done->result)->toMatchArray(['provider_instance' => 'claudeXhigh', 'model' => 'claude-opus-5-5', 'effort' => 'xhigh'])
            ->and($foreign->result)->toMatchArray(['provider_instance' => 'codex', 'effort' => null])
            ->and(array_map(InstanceContainer::claudeEffort(...), array_map(InstanceContainer::claudeInstance(...), InstanceContainer::ClaudeEfforts)))
            ->toBe(InstanceContainer::ClaudeEfforts);
    });

    it('marks the thread failed when Swamp refuses to start the turn, so the subtask points at no silent thread', function (): void {
        $group = copyGroup(copySource()->project);
        finishedCopy($group, startCopy($group));
        $turn = copyTurn($group);

        // As StartSwampRun::refuse leaves it: the Operation failed before any run.
        $turn->forceFill(['status' => 'failed', 'error_code' => 'operation.start_refused', 'finished_at' => now()])->save();
        app(TaskCopyTurnProjection::class)->refused($turn);

        expect(AgentThread::query()->findOrFail($turn->input['thread_id'])->only(['state', 'error']))
            ->toBe(['state' => AgentThreadState::Failed, 'error' => 'Swamp refused to start the turn.'])
            // A turn that never started a run leaves the group free.
            ->and(copyTurn($group)->kind)->toBe('tasks.copy_turn');
    });

    it('serves a T3 thread only from its archive', function (): void {
        Storage::fake('local');
        $group = copyGroup(copySource()->project);
        $thread = AgentThread::query()->create(['task_group_id' => $group->id, 'driver' => 't3', 'runtime_key' => 'host', 'external_id' => 'retired', 'role' => 'implementer']);

        expect(copyRefusal(fn () => app(ReadAgentThreadAction::class)->execute($group, $thread, null)))->toBe('tasks.agent_transcript_unavailable 409');
    });
});
