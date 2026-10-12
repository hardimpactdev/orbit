<?php

declare(strict_types=1);

use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Actions\TaskVms\ParkIdleTaskVmsAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacement;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\ParkTaskVm;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\AppDevFakeSshExecutor;

use function Pest\Laravel\mock;

/** A provider that records start, stop, and destroy. */
final class TaskVmParkProvider implements TaskVmProvider
{
    /** @var list<string> */
    public array $calls = [];

    public ?Throwable $startError = null;

    public bool $running = false;

    public function create(TaskVm $vm, string $userData): void
    {
        $this->calls[] = 'create';
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        $this->calls[] = 'observe';

        return new VmObservation($this->running, null);
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        return true;
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        return 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    }

    public function imageManifest(TaskVm $vm): ?string
    {
        return null;
    }

    public function start(TaskVm $vm): void
    {
        $this->calls[] = 'start';
        if ($this->startError instanceof Throwable) {
            throw $this->startError;
        }
    }

    public function stop(TaskVm $vm): void
    {
        $this->calls[] = 'stop';
    }

    public ?Closure $onDestroy = null;

    public function destroy(TaskVm $vm): void
    {
        $this->calls[] = 'destroy';
        if ($this->onDestroy instanceof Closure) {
            ($this->onDestroy)();
        }
    }
}

function tvm_park_settings(int $parkAfterMinutes = 30): void
{
    app()->instance(TaskVmSettings::class, new TaskVmSettings(
        enabled: true, devClusterId: null, wireguardRange: '10.44.0.128/25',
        hosts: [new TaskVmHost(test()->host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '20GiB')],
        modelProxyOrigin: 'http://10.44.0.3:8317', piArtifactPath: null, piArtifactSha256: null, piModels: [],
        parkAfterMinutes: $parkAfterMinutes,
    ));
}

/**
 * A group that waits for review with a pull request and one completed subtask, settled `$minutesAgo` minutes ago,
 * and its ready task VM with an enrolled Node.
 *
 * @param  array<string, mixed>  $group
 * @return array{0: Task, 1: TaskVm}
 */
function tvm_park_waiting(int $minutesAgo = 31, array $group = [], string $wireguardIp = '10.44.0.129'): array
{
    $task = Task::topLevel()->create([
        'project_id' => test()->project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => TaskGroupStatus::WaitingForReview,
        'execution_mode' => TaskExecutionMode::Managed, 'task_compute' => TaskCompute::Vm, 'pr_url' => 'https://github.com/acme/dlf/pull/7',
        'settled_at' => now()->subMinutes($minutesAgo + 10), ...$group,
    ]);
    Task::query()->create([
        'parent_id' => $task->id, 'position' => 1, 'title' => 'Step', 'brief' => 'Do it',
        'status' => TaskStatus::Completed, 'settled_at' => now()->subMinutes($minutesAgo),
    ]);
    $node = Node::query()->create([
        'name' => 'tvm-node-'.$task->id, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $wireguardIp, 'wireguard_ip' => $wireguardIp, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $vm = TaskVm::query()->create([
        'group_id' => $task->id, 'host_node_id' => test()->host->id, 'node_id' => $node->id, 'provider' => 'incus',
        'name' => 'tvm-pending-'.$task->id, 'state' => TaskVmState::Ready, 'wireguard_ip' => $wireguardIp, 'pi_token' => str_repeat('p', 64),
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    // The VM has been ready as long as the group waited.
    TaskVm::query()->whereKey($vm->id)->update(['updated_at' => now()->subMinutes($minutesAgo + 10)]);

    return [$task->fresh() ?? $task, $vm->fresh() ?? $vm];
}

function tvm_park_workspace(Task $group, TaskVm $vm): Instance
{
    $instance = Instance::query()->create([
        'project_id' => test()->project->id, 'node_id' => $vm->node_id, 'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id,
        'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public',
        'branch' => 'task-'.$group->id, 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $instance;
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    $this->provider = new TaskVmParkProvider;
    app()->instance(TaskVmProvider::class, $this->provider);
    tvm_park_settings();
});

describe('park', function (): void {
    it('parks the ready task VM of a group that has waited for review longer than park_after_minutes', function (): void {
        Queue::fake();
        [, $vm] = tvm_park_waiting(minutesAgo: 31);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(1)
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Parked);
        Queue::assertPushedOn('task-vms', ParkTaskVm::class, fn (ParkTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('keeps every VM running whose group still works, previews, has no pull request, or settled recently', function (): void {
        Queue::fake();
        $recent = tvm_park_waiting(minutesAgo: 10, wireguardIp: '10.44.0.130')[1];
        $preview = tvm_park_waiting(group: ['preview' => true], wireguardIp: '10.44.0.131')[1];
        $unpublished = tvm_park_waiting(group: ['pr_url' => null], wireguardIp: '10.44.0.132')[1];
        $running = tvm_park_waiting(group: ['status' => TaskGroupStatus::Running], wireguardIp: '10.44.0.133')[1];
        $merged = tvm_park_waiting(group: ['watched_pr_completion' => 'merged'], wireguardIp: '10.44.0.134')[1];
        [$fixing, $fixingVm] = tvm_park_waiting(wireguardIp: '10.44.0.135');
        Task::query()->create(['parent_id' => $fixing->id, 'position' => 2, 'title' => 'Fix', 'brief' => 'Fix', 'status' => TaskStatus::Todo]);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0);
        foreach ([$recent, $preview, $unpublished, $running, $merged, $fixingVm] as $vm) {
            expect($vm->fresh()?->state)->toBe(TaskVmState::Ready);
        }
        Queue::assertNotPushed(ParkTaskVm::class);
    });

    it('never parks a group whose workspace removal or merge cleanup failed, so destroy and park do not loop', function (string $prefix): void {
        Queue::fake();
        Sleep::fake();
        $this->provider->running = true;
        [$group, $vm] = tvm_park_waiting(group: ['assistance_requested' => true, 'assistance_reason' => $prefix.'disk full']);
        tvm_park_workspace($group, $vm);

        foreach ([1, 2, 3] as $tick) {
            app(DestroyEndedTaskVmsAction::class)->execute();
            expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0);
            app()->call([new DestroyTaskVm($vm->id), 'handle']);
            expect($vm->fresh()?->state)->toBe(TaskVmState::Ready);
        }
        Queue::assertNotPushed(ParkTaskVm::class);
        Queue::assertNotPushed(ResumeTaskVm::class);
    })->with([RemoveTaskWorkspaceAction::MergeCleanupFailedPrefix, RemoveTaskWorkspaceAction::RemovalFailedPrefix]);

    it('keeps a VM that was started again running for park_after_minutes before it parks again', function (): void {
        Queue::fake();
        [, $vm] = tvm_park_waiting(minutesAgo: 120);
        $vm->update(['state' => TaskVmState::Parked]);
        $vm->update(['state' => TaskVmState::Ready]);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0);

        $this->travel(31)->minutes();
        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(1)
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Parked);
    });

    it('honours park_after_minutes', function (): void {
        Queue::fake();
        tvm_park_settings(parkAfterMinutes: 120);
        [, $vm] = tvm_park_waiting(minutesAgo: 90);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0)
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('reads no task VM setting while no ready task VM waits for review', function (): void {
        app()->bind(TaskVmSettings::class, static fn () => throw new TaskVmException('task_vm.invalid_config', 'unread', 500));
        tvm_park_waiting(group: ['status' => TaskGroupStatus::Running]);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0);
    });

    it('stops a parked VM and leaves a woken one running', function (): void {
        [, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked, 'error_code' => 'task_vm.host_command_failed', 'error_message' => 'earlier']);

        app()->call([new ParkTaskVm($vm->id), 'handle']);
        expect($this->provider->calls)->toBe(['stop'])
            ->and($vm->fresh()?->error_code)->toBeNull();

        $vm->update(['state' => TaskVmState::Ready]);
        app()->call([new ParkTaskVm($vm->id), 'handle']);
        expect($this->provider->calls)->toBe(['stop']);
    });

    it('keeps a VM parked with the error when it cannot be stopped', function (): void {
        [, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);

        (new ParkTaskVm($vm->id))->failed(new TaskVmException('task_vm.host_command_failed', 'incus stop failed', 502));

        expect($vm->fresh())->state->toBe(TaskVmState::Parked)->error_code->toBe('task_vm.host_command_failed');
    });
});

describe('resume', function (): void {
    beforeEach(function (): void {
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
        mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
        $this->ssh = new AppDevFakeSshExecutor;
        app()->instance(SshExecutor::class, $this->ssh);
        Sleep::fake(syncWithCarbon: true);
    });

    it('starts the VM, waits for the Node agent and Pi, and marks it ready', function (): void {
        Http::fake(['http://10.44.0.129:3774/capabilities' => Http::sequence()->push([], 503)->push(['models' => []], 200)]);
        [, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);

        app()->call([new ResumeTaskVm($vm->id), 'handle']);

        expect($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($this->provider->calls)->toBe(['start'])
            ->and($this->ssh->commands[0]->arguments)->toBe(['systemctl', 'is-active', '--quiet', 'orbit-agent.service'])
            ->and($this->ssh->connections[0]->host)->toBe('10.44.0.129');
        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.str_repeat('p', 64)));
        Sleep::assertSleptTimes(1);
    });

    it('waits while the Node agent is not active', function (): void {
        Http::fake(['*' => Http::response([], 200)]);
        $this->ssh = new AppDevFakeSshExecutor([new CommandResult(3, '', '', 1, false)]);
        app()->instance(SshExecutor::class, $this->ssh);
        [, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);

        app()->call([new ResumeTaskVm($vm->id), 'handle']);

        expect($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($this->ssh->commands)->toHaveCount(2);
        Http::assertSentCount(1);
    });

    it('runs again 15 seconds later when the VM does not answer within one run', function (): void {
        Http::fake(['*' => Http::response([], 503)]);
        [, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);
        $job = (new ResumeTaskVm($vm->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(15);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Parked);
    });

    it('fails the row and asks for assistance after the last run', function (): void {
        Http::fake(['*' => Http::response([], 503)]);
        [$group, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);
        $job = new ResumeTaskVm($vm->id);
        $job->setJob(Mockery::mock(Job::class)->shouldReceive('attempts')->andReturn(ResumeTaskVm::Runs)->getMock());

        expect(fn () => app()->call([$job, 'handle']))->toThrow(TaskVmException::class, 'did not answer after its start');

        $job->failed(new TaskVmException('task_vm.resume_timeout', 'Task VM did not answer.', 504));
        expect($vm->fresh())->state->toBe(TaskVmState::Failed)->error_code->toBe('task_vm.resume_timeout')
            ->and($group->fresh())->assistance_requested->toBeTrue()
            ->assistance_reason->toStartWith('Task VM failed: task_vm.resume_timeout: ');
    });

    it('does nothing for a row that is not parked', function (): void {
        [, $vm] = tvm_park_waiting();

        app()->call([new ResumeTaskVm($vm->id), 'handle']);
        (new ResumeTaskVm($vm->id))->failed(new RuntimeException('late'));

        expect($this->provider->calls)->toBe([])->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });
});

describe('wake', function (): void {
    it('holds new work of a parked group, queues the start, and lets it go once the VM is ready', function (): void {
        Queue::fake();
        [$group, $vm] = tvm_park_waiting();
        $vm->update(['state' => TaskVmState::Parked]);

        expect(app(WakeTaskVmAction::class)->forGroup($group))->toBeFalse()
            ->and($group->fresh()?->capacity_wait_reason)->toBe(WakeTaskVmAction::Resuming);
        Queue::assertPushedOn('task-vms', ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);

        $vm->update(['state' => TaskVmState::Ready]);
        expect(app(WakeTaskVmAction::class)->forGroup($group->fresh() ?? $group))->toBeTrue()
            ->and($group->fresh()?->capacity_wait_reason)->toBeNull();
    });

    it('lets shared groups and groups without a task VM go on', function (): void {
        Queue::fake();
        $shared = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'S', 'brief' => 'S', 'status' => TaskGroupStatus::WaitingForReview, 'task_compute' => TaskCompute::Shared]);
        $fresh = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'V', 'brief' => 'V', 'status' => TaskGroupStatus::WaitingForReview, 'task_compute' => TaskCompute::Vm]);

        expect(app(WakeTaskVmAction::class)->forGroup($shared))->toBeTrue()
            ->and(app(WakeTaskVmAction::class)->forGroup($fresh))->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('refuses workspace commands on a parked VM with task_vm.parked', function (): void {
        [$group, $vm] = tvm_park_waiting();
        $workspace = tvm_park_workspace($group, $vm);
        $vm->update(['state' => TaskVmState::Parked]);

        expect(fn () => TaskVmPlacement::assertWorkspace($group, $workspace))->toThrow(TaskVmException::class, 'is parked')
            ->and(TaskVmPlacement::allowsWorkspace($group, $workspace))->toBeFalse();
    });

    it('keeps the workspace of a parked VM and starts the VM instead of removing it', function (): void {
        Queue::fake();
        mock(InstanceRemover::class)->shouldReceive('execute')->never();
        [$group, $vm] = tvm_park_waiting();
        $workspace = tvm_park_workspace($group, $vm);
        $vm->update(['state' => TaskVmState::Parked]);

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($workspace))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.parked'));
        expect(Instance::query()->whereKey($workspace->id)->exists())->toBeTrue();
        Queue::assertPushed(ResumeTaskVm::class);
    });

    it('removes the workspace on completion under the group admission lock that parking takes', function (): void {
        app(TaskExtensionState::class)->enable();
        Queue::fake();
        // A manual complete swallows removal failures, so record what the VM deletion saw.
        mock(InstanceRemover::class)->shouldReceive('execute')->never();
        $this->provider->onDestroy = function (): void {
            $this->lockedDuringRemoval = $this->locked;
        };
        $this->locked = [];
        $this->lockedDuringRemoval = null;
        $lock = new class($this) implements TaskExecutionLock
        {
            public function __construct(private object $test) {}

            public function synchronized(int $groupId, Closure $operation): mixed
            {
                $this->test->locked[] = $groupId;
                try {
                    return $operation();
                } finally {
                    array_pop($this->test->locked);
                }
            }
        };
        app()->instance(TaskExecutionLock::class, $lock);
        [$this->group, $vm] = tvm_park_waiting();
        tvm_park_workspace($this->group, $vm);

        app(CompleteTaskGroupAction::class)->execute($this->group);

        expect($this->group->fresh()?->status)->toBe(TaskGroupStatus::Completed)
            ->and($this->lockedDuringRemoval)->toBe([$this->group->id]);
    });

    it('lets the ended-group sweep wait for a parked VM without a failure', function (): void {
        Queue::fake();
        mock(InstanceRemover::class)->shouldReceive('execute')->never();
        [$group, $vm] = tvm_park_waiting(group: ['status' => TaskGroupStatus::Completed]);
        $workspace = tvm_park_workspace($group, $vm);
        $vm->update(['state' => TaskVmState::Parked]);

        app(TaskScheduler::class)->removeAbandonedWorkspaces();

        expect(Instance::query()->whereKey($workspace->id)->exists())->toBeTrue()
            ->and($group->fresh())->assistance_requested->toBeFalse()->assistance_reason->toBeNull();
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('lets destroy start a parked VM that still holds a workspace, never delete it', function (): void {
        Queue::fake();
        [$group, $vm] = tvm_park_waiting(group: ['status' => TaskGroupStatus::Completed, 'assistance_reason' => RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.']);
        tvm_park_workspace($group, $vm);
        $vm->update(['state' => TaskVmState::Parked]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Parked);
        Queue::assertPushed(ResumeTaskVm::class);
    });
});

describe('tick', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        app(TaskExtensionState::class)->enable();
        Http::fake(['*' => Http::response([], 200)]);
    });

    it('parks idle task VMs on tasks:tick', function (): void {
        $watcher = Mockery::mock(TaskPullRequestWatcher::class);
        $watcher->shouldReceive('health')->andReturnNull();
        app()->instance(TaskPullRequestWatcher::class, $watcher);
        [, $vm] = tvm_park_waiting();

        Artisan::call('tasks:tick');

        expect($vm->fresh()?->state)->toBe(TaskVmState::Parked);
        Queue::assertPushed(ParkTaskVm::class, fn (ParkTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('starts a parked VM before the merged-group cleanup and records no cleanup failure', function (): void {
        mock(InstanceRemover::class)->shouldReceive('execute')->never();
        $watcher = Mockery::mock(TaskPullRequestWatcher::class);
        $watcher->shouldReceive('health')->andReturn(new TaskPullRequestHealth('merged'));
        app()->instance(TaskPullRequestWatcher::class, $watcher);
        [$group, $vm] = tvm_park_waiting();
        $workspace = tvm_park_workspace($group, $vm);
        $vm->update(['state' => TaskVmState::Parked]);

        app(TaskScheduler::class)->tick();

        expect(Instance::query()->whereKey($workspace->id)->exists())->toBeTrue()
            ->and($group->fresh())->status->toBe(TaskGroupStatus::WaitingForReview)->assistance_reason->toBeNull();
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
    });
});
