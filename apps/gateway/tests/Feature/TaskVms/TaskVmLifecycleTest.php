<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskWait;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Models\Task;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

use function Pest\Laravel\mock;

const TVM_LIFE_ORIGIN = 'http://10.44.0.3:8317';
const TVM_LIFE_FINGERPRINT = RecordingTaskVmProvider::Fingerprint;

/** Clears a Route target in the database as the projector does; the remote steps belong to the projector's own tests. */
final class TaskVmLifecycleProjector implements InstanceRemovalProjector
{
    /** @var list<int> */
    public array $cleared = [];

    public function clearRouteTarget(InstanceRemovalMember $member): string
    {
        $this->cleared[] = (int) $member->route_id;
        DB::transaction(static function () use ($member): void {
            RouteTarget::query()->where('route_id', $member->route_id)->where('instance_id', $member->instance_id)->delete();
            Route::query()->whereKey($member->route_id)->delete();
        });

        return 'deleted';
    }

    public function withdrawPhpPool(InstanceRemovalMember $member): void {}

    public function cleanupRuntime(InstanceRemovalMember $member): void {}
}

/**
 * A cancelled group whose workspace stayed on its ready task VM, with a pending Route and a Process.
 *
 * @return array{0: TaskVm, 1: Instance, 2: Route, 3: Task}
 */
function tvm_life_stranded_workspace(bool $removalFailed, TaskOrchestration $orchestration = TaskOrchestration::External): array
{
    $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
    $group = tvm_life_group(TaskGroupStatus::Cancelled, $orchestration);
    $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
    $instance = Instance::query()->create([
        'project_id' => test()->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-'.$group->id,
        'source_layout' => 'checkout', 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public',
        'branch' => 'task-'.$group->id, 'starting_commit' => str_repeat('a', 40), 'task_workspace_routed' => true,
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => test()->project->id, 'node_id' => $node->id, 'domain' => 'task-'.$group->id.'.dlf.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $instance->processes()->create([
        'name' => 'vite', 'runtime' => ProcessRuntime::Systemd, 'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['vp', 'dev']], 'restart_policy' => 'always', 'status' => LifecycleStatus::Active,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    if ($removalFailed) {
        TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.');
    }

    return [$vm, $instance, $route, $group->fresh() ?? $group];
}

function tvm_life_node(string $name, string $address, string $user = 'orbit', ?RoleName $role = null): Node
{
    $node = Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user, 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    if ($role instanceof RoleName) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }

    return $node;
}

function tvm_life_settings(bool $enabled = true, int $maxVms = 2, ?int $clusterId = null): void
{
    TaskVmRecipeImages::ready(test()->host->id);
    TaskVmSettingsBuilder::bind([
        'enabled' => $enabled, 'devClusterId' => $clusterId, 'modelProxyOrigin' => TVM_LIFE_ORIGIN, 'piArtifactSha256' => str_repeat('c', 64),
        'hosts' => [TaskVmSettingsBuilder::host(test()->host->id, ['maxVms' => $maxVms])],
    ]);
}

/** @param list<array{0: int, 1: int}> $hosts node id and max_vms, in config order */
function tvm_life_hosts(array $hosts): void
{
    foreach ($hosts as [$hostId]) {
        TaskVmRecipeImages::ready($hostId);
    }
    $cidrs = ['10.251.77.0/24', '10.251.78.0/24', '10.251.79.0/24'];
    TaskVmSettingsBuilder::bind([
        'devClusterId' => null, 'modelProxyOrigin' => TVM_LIFE_ORIGIN,
        'hosts' => array_map(static fn (array $host, int $index): TaskVmHost => TaskVmSettingsBuilder::host($host[0], ['cidr' => $cidrs[$index], 'maxVms' => $host[1]]), $hosts, array_keys($hosts)),
    ]);
}

function tvm_life_group(TaskGroupStatus $status = TaskGroupStatus::Reserved, TaskOrchestration $orchestration = TaskOrchestration::External): Task
{
    return Task::topLevel()->create([
        'orchestration' => $orchestration, 'project_id' => test()->project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => $status, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
}

function tvm_life_vm(Task $group, TaskVmState $state = TaskVmState::Provisioning, ?Node $node = null, string $wireguardIp = '10.44.0.129'): TaskVm
{
    $vm = TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => test()->host->id, 'node_id' => $node?->id, 'provider' => 'incus',
        'name' => 'tvm-pending', 'state' => $state, 'wireguard_ip' => $node->wireguard_ip ?? $wireguardIp,
        'pi_token' => str_repeat('p', 64),
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->host = tvm_life_node('beast', '10.44.0.7', 'nckrtl');
    $this->provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $this->provider);
    tvm_life_settings();
});

describe('allocation', function (): void {
    it('captures one provisioning row without starting provider work', function (): void {
        Queue::fake();
        $group = tvm_life_group();

        $vm = app(AllocateTaskVmAction::class)->capture($group);
        $again = app(AllocateTaskVmAction::class)->capture($group);

        expect($again->id)->toBe($vm->id)
            ->and($vm->name)->toBe('tvm-'.$vm->id)
            ->and($vm->state)->toBe(TaskVmState::Provisioning)
            ->and($vm->host_node_id)->toBe($this->host->id)
            ->and($vm->wireguard_ip)->toBe('10.44.0.129')
            ->and($vm->pi_token)->toMatch('/\A[a-f0-9]{64}\z/');
        Queue::assertNothingPushed();
        expect(Operation::query()->count())->toBe(0)->and($this->provider->calls)->toBe([]);
    });

    it('gives each live task VM its own address and waits when every host is full', function (): void {
        Queue::fake();
        $first = app(AllocateTaskVmAction::class)->capture(tvm_life_group());
        $second = app(AllocateTaskVmAction::class)->capture(tvm_life_group());

        expect($second->wireguard_ip)->toBe('10.44.0.130')
            ->and(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))
            ->toThrow(TaskCapacityException::class, 'Task VM: no capacity.');

        $first->update(['state' => TaskVmState::Destroyed]);
        expect(app(AllocateTaskVmAction::class)->capture(tvm_life_group())->wireguard_ip)->toBe('10.44.0.129');
    });

    it('places each new task VM on the active host with the most free slots, and never more than max_vms', function (): void {
        Queue::fake();
        $shark = tvm_life_node('shark', '10.44.0.8', 'nckrtl');
        tvm_life_hosts([[$this->host->id, 2], [$shark->id, 3]]);
        $place = fn (): int => app(AllocateTaskVmAction::class)->capture(tvm_life_group())->host_node_id;

        // shark 3 free, beast 2 free -> shark; then 2/2: the tie keeps config order -> beast; then 1/2 -> shark; 1/1 -> beast; 0/1 -> shark.
        expect([$place(), $place(), $place(), $place(), $place()])
            ->toBe([$shark->id, $this->host->id, $shark->id, $this->host->id, $shark->id])
            ->and(fn () => $place())->toThrow(TaskCapacityException::class, 'Task VM: no capacity.')
            ->and(TaskVm::query()->live()->where('host_node_id', $this->host->id)->count())->toBe(2)
            ->and(TaskVm::query()->live()->where('host_node_id', $shark->id)->count())->toBe(3);
    });

    it('counts parked task VMs against a host but not failed ones, and frees a slot only once a VM is destroyed', function (): void {
        Queue::fake();
        $shark = tvm_life_node('shark', '10.44.0.8', 'nckrtl');
        tvm_life_hosts([[$this->host->id, 1], [$shark->id, 1]]);
        $parked = tvm_life_vm(tvm_life_group(TaskGroupStatus::WaitingForReview), TaskVmState::Parked);
        TaskVm::query()->create([
            'group_id' => tvm_life_group()->id, 'host_node_id' => $shark->id, 'provider' => 'incus', 'name' => 'tvm-failed',
            'state' => TaskVmState::Failed, 'wireguard_ip' => '10.44.0.140', 'pi_token' => str_repeat('p', 64),
        ]);

        expect(app(AllocateTaskVmAction::class)->capture(tvm_life_group())->host_node_id)->toBe($shark->id)
            ->and(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))
            ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::Capacity)->and($wait->getMessage())->toBe('Task VM: no capacity.'));

        $parked->update(['state' => TaskVmState::Destroyed]);
        expect(app(AllocateTaskVmAction::class)->capture(tvm_life_group())->host_node_id)->toBe($this->host->id);
    });

    it('gives a host whose max_vms dropped below its VMs no new VM and removes none', function (): void {
        Queue::fake();
        $shark = tvm_life_node('shark', '10.44.0.8', 'nckrtl');
        tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, wireguardIp: '10.44.0.129');
        tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, wireguardIp: '10.44.0.130');
        tvm_life_hosts([[$this->host->id, 1], [$shark->id, 1]]);

        expect(app(AllocateTaskVmAction::class)->capture(tvm_life_group())->host_node_id)->toBe($shark->id)
            ->and(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))->toThrow(TaskCapacityException::class, 'Task VM: no capacity.')
            ->and(TaskVm::query()->live()->where('host_node_id', $this->host->id)->count())->toBe(2);
    });

    it('sizes a VM by the test nodes its subtasks declare at the claim, and counts its slots', function (): void {
        Queue::fake();
        tvm_life_settings(maxVms: 4);
        $plain = tvm_life_group();
        Task::query()->create(['parent_id' => $plain->id, 'position' => 1, 'title' => 'Plain', 'brief' => 'No nodes', 'status' => TaskStatus::Todo]);
        Task::query()->create(['parent_id' => $plain->id, 'position' => 2, 'title' => 'Dropped', 'brief' => 'Cancelled', 'status' => TaskStatus::Cancelled, 'topology' => ['app-prod']]);
        $topology = tvm_life_group();
        Task::query()->create(['parent_id' => $topology->id, 'position' => 1, 'title' => 'Prod', 'brief' => 'Prod', 'status' => TaskStatus::Todo, 'topology' => ['app-prod', 'app-dev']]);
        Task::query()->create(['parent_id' => $topology->id, 'position' => 2, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-prod+ingress']]);

        expect(TaskTopology::forGroup($plain)->toArray())->toBe([])
            ->and(TaskTopology::forGroup($topology)->toArray())->toBe(['app-dev', 'app-prod+ingress']);

        $large = app(AllocateTaskVmAction::class)->capture($topology);
        $normal = app(AllocateTaskVmAction::class)->capture($plain);

        expect($large->refresh()->size)->toBe(TaskVmSize::Large)
            ->and($normal->refresh()->size)->toBe(TaskVmSize::Normal)
            ->and(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe([$this->host->id => 0])
            ->and(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::NoCapacity);

        $normal->update(['state' => TaskVmState::Destroyed]);
        $again = tvm_life_group();
        Task::query()->create(['parent_id' => $again->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);

        // One slot is free, so a medium VM for one test node waits, while a normal one still fits.
        expect(fn () => app(AllocateTaskVmAction::class)->capture($again))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Medium))
            ->and(app(AllocateTaskVmAction::class)->capture(tvm_life_group())->size)->toBe(TaskVmSize::Normal);
        Queue::assertNothingPushed();
        expect(Operation::query()->count())->toBe(0);
    });

    it('gives one test node a medium VM and more a large one, whatever roles they add', function (array $topology, TaskVmSize $size, int $slots): void {
        Queue::fake();
        tvm_life_settings(maxVms: 4);
        $group = tvm_life_group();
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Nodes', 'brief' => 'Nodes', 'status' => TaskStatus::Todo, 'topology' => $topology]);

        $vm = app(AllocateTaskVmAction::class)->capture($group)->refresh();

        expect($vm->size)->toBe($size)
            ->and(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe([$this->host->id => 4 - $slots]);
    })->with([
        'app-dev' => [['app-dev'], TaskVmSize::Medium, 2],
        'app-prod with a role' => [['app-prod+ingress'], TaskVmSize::Medium, 2],
        'two nodes' => [['app-dev', 'app-prod'], TaskVmSize::Large, 3],
    ]);

    it('gives a group of a medium Project a medium VM of two slots, and a large one for a topology', function (): void {
        Queue::fake();
        TaskVmSettingsBuilder::bind([
            'devClusterId' => null, 'modelProxyOrigin' => TVM_LIFE_ORIGIN, 'mediumProjectIds' => [$this->project->id],
            'hosts' => [TaskVmSettingsBuilder::host($this->host->id, ['maxVms' => 3])],
        ]);
        $topology = tvm_life_group();
        Task::query()->create(['parent_id' => $topology->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev', 'app-prod']]);

        $medium = app(AllocateTaskVmAction::class)->capture(tvm_life_group());

        expect($medium->refresh()->size)->toBe(TaskVmSize::Medium)
            ->and(TaskVmSize::forGroup($topology, [$this->project->id]))->toBe(TaskVmSize::Large)
            ->and(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe([$this->host->id => 1])
            ->and(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Medium));
    });

    it('allocates a new VM without the obsolete placement cache lock', function (): void {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('task-vms:placement', 30);
        expect($lock->get())->toBeTrue();

        try {
            $vm = app(AllocateTaskVmAction::class)->capture(tvm_life_group());
            expect($vm->state)->toBe(TaskVmState::Provisioning)
                ->and(TaskVm::query()->count())->toBe(1);
            Queue::assertNothingPushed();
            expect(Operation::query()->count())->toBe(0);
        } finally {
            $lock->release();
        }
    });

    it('skips an inactive host', function (): void {
        Queue::fake();
        $this->host->update(['status' => LifecycleStatus::Failed]);

        expect(fn () => app(AllocateTaskVmAction::class)->capture(tvm_life_group()))->toThrow(TaskCapacityException::class)
            ->and(TaskVm::query()->count())->toBe(0);
    });
});

describe('claim', function (): void {
    it('waits without a task VM while task VMs are off', function (): void {
        tvm_life_settings(enabled: false);

        expect(fn () => app(TaskVmWorkspace::class)->node(tvm_life_group()))
            ->toThrow(TaskVmException::class, 'Task VMs are not enabled on this Gateway.')
            ->and(TaskVm::query()->count())->toBe(0);
    });

    it('makes a subtask whose topology needs a larger VM wait with its cause', function (): void {
        $group = tvm_life_group(TaskGroupStatus::Running);
        $group->update(['taskable_type' => (new Instance)->getMorphClass(), 'taskable_id' => Instance::query()->create(['node_id' => $this->host->id, 'project_id' => $this->project->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/home/orbit/apps/orbit/workspace'])->id]);
        $vm = tvm_life_vm($group, TaskVmState::Ready);
        $vm->update(['node_id' => $this->host->id, 'recipe_identity' => TaskVmRecipeImages::ready($this->host->id)->recipe_identity]);
        $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);
        $admission = app(TaskTopologyAdmission::class);

        expect(fn () => $admission->prepare($group->refresh(), $subtask))
            ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::Topology)->and($wait->getMessage())->toBe(TaskVmTopologyAdmission::TooSmall));

        $vm->update(['size' => TaskVmSize::Medium]);
        Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Prod', 'brief' => 'Prod', 'status' => TaskStatus::Todo, 'topology' => ['app-prod']]);
        expect(fn () => $admission->prepare($group->refresh(), $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::TooSmall);
    });

    it('waits for a captured task VM and returns its Node only after native readiness', function (): void {
        Queue::fake();
        $group = tvm_life_group();

        app(AllocateTaskVmAction::class)->capture($group);

        expect(fn () => app(TaskVmWorkspace::class)->node($group))
            ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::TaskVm)->and($wait->getMessage())->toBe('Task VM: provisioning.'));

        $vm = TaskVm::query()->sole();
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.bootstrap_failed', 'error_message' => 'Cloud-init failed.']);
        expect(fn () => app(TaskVmWorkspace::class)->node($group))
            ->toThrow(TaskVmFailedException::class, "Task VM failed: {$vm->name}: task_vm.bootstrap_failed: Cloud-init failed. Cancel the group to destroy the VM.");

        $node = tvm_life_node('tvm-'.$vm->id, '10.44.0.129', role: RoleName::AppDev);
        $vm->update(['state' => TaskVmState::Ready, 'node_id' => $node->id]);
        expect(app(TaskVmWorkspace::class)->node($group)->id)->toBe($node->id);
    });
});

describe('retained task VM workspace removal', function (): void {
    beforeEach(function (): void {
        $this->gateway = tvm_life_node('gateway', '10.44.0.2', role: RoleName::Gateway);
        config(['swamp.run_token' => 'fixture.fake']);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-task-vm-resume', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
        app()->instance(WebSocketClient::class, $socket);
        $this->projector = new TaskVmLifecycleProjector;
        app()->instance(InstanceRemovalProjector::class, $this->projector);
        $keys = Mockery::mock(SshKeyProvider::class);
        $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
        app()->instance(SshKeyProvider::class, $keys);
    });

    it('keeps the group and its VM workspace when native destruction is unavailable', function (bool $earlierFailure): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Running);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id,
            'branch_override' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public',
            'branch' => 'task-'.$group->id, 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
        $group->taskable()->associate($instance);
        $group->save();
        if ($earlierFailure) {
            // The stuck state an earlier cancel left: the group still runs and asks for help, the VM still runs.
            TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'Sandbox workload nodes must be managed by the compute driver.');
        }
        app(TaskExtensionState::class)->enable();
        bind_task_node_reachability();
        // No SSH to the VM: the checkout goes with the VM, and the Project has no teardown steps.
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        app()->instance(InstanceRemover::class, mock(InstanceRemover::class)->shouldReceive('execute')->never()->getMock());
        Queue::fake();

        expect(fn () => app(CancelTaskGroupAction::class)->execute($group))
            ->toThrow(ResourceOperationException::class);

        $this->assertModelExists($instance);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Running)
            ->and($group->fresh()->taskable_id)->toBe($instance->id)
            ->and($vm->fresh()->state)->toBe(TaskVmState::Ready)
            ->and($vm->fresh()->pi_token)->not->toBeNull()
            ->and($this->provider->calls)->toBe([])
            ->and(Operation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with(['first cancel' => false, 'retry after an earlier failure' => true]);

    it('preserves approved work until native publication finishes before workspace removal', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $sha = str_repeat('c', 40);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))
            ->toThrow(TaskPullRequestException::class, 'finish native publication');

        $this->assertModelExists($instance);
        expect($task->fresh()->review_handled_comment_id)->toBeNull();
        expect($vm->fresh()->publish_state)->not->toBe('pushed');
        expect(Operation::query()->where('kind', 'task_vm.destroy')->exists())->toBeFalse();
        expect($this->provider->calls)->toBe([]);
    });

    it('reconciles a queued push that succeeded before VM loss without another removal poll', function (bool $reviewAndMerge): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        $group->project->update(['review_and_merge' => $reviewAndMerge, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $sha = str_repeat('c', 40);
        $approval = $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
        if ($reviewAndMerge) {
            TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        }
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.vm_missing', 'publish_state' => 'pushed', 'publish_commit' => $sha, 'publish_branch' => 'task-'.$group->id]);
        $this->provider->observation = null;
        Log::spy();
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);

        expect(TaskFinalReview::reconcileRemovalPush($group))->toBeNull();

        $this->assertModelExists($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Failed);
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('properties->reason', 'like', '%Reviewed work lost%')->exists())->toBeFalse();
        if ($reviewAndMerge) {
            expect(TaskReviewedCommit::query()->where('task_id', $group->id)->sole()->pushed_at)->not->toBeNull();
        } else {
            expect($task->fresh()->review_handled_comment_id)->toBe($approval->id);
        }
        expect($this->provider->calls)->toBe([]);
        Log::shouldHaveReceived('info')->once()->with('Pushed approved work before removing a task workspace.', ['task_group_id' => $group->id, 'commit_sha' => $sha, 'branch' => 'task-'.$group->id]);
    })->with(['per-subtask publication' => false, 'review and merge' => true]);

    it('waits without removal assistance when manual completion has a pending preservation push', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        app(TaskExtensionState::class)->enable();
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Settling]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->andThrow(TaskPullRequestException::pending('Push queued.'));
        app()->instance(TaskPullRequestPublisher::class, $publisher);

        app(CompleteTaskGroupAction::class)->execute($group);

        $this->assertModelExists($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
        expect($group->fresh()->status)->toBe(TaskGroupStatus::Completed);
        expect($group->fresh()->assistance_requested)->toBeFalse();
        expect($this->provider->calls)->toBe([]);
    });

    it('wakes a parked VM before attempting its preservation push', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $vm->update(['state' => TaskVmState::Parked]);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => str_repeat('c', 40)]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        Queue::fake();

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))
            ->toThrow(TaskVmException::class, 'parked');

        $this->assertModelExists($instance);
        expect(Operation::query()->where('kind', 'task_vm.resume')->where('target_id', $vm->id)->count())->toBe(1);
        Queue::assertNothingPushed();
        expect($this->provider->calls)->toBe([]);
    });

    it('reports a parked VM when manual cancellation or completion needs preservation', function (bool $complete): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        app(TaskExtensionState::class)->enable();
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Settling]);
        $vm->update(['state' => TaskVmState::Parked]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        bind_task_node_reachability(unreachable: true);
        Queue::fake();

        if ($complete) {
            app(CompleteTaskGroupAction::class)->execute($group);
        } else {
            expect(fn () => app(CancelTaskGroupAction::class)->execute($group))->toThrow(TaskVmException::class, 'parked');
        }

        $this->assertModelExists($instance);
        expect($group->fresh()->assistance_reason)->toContain('parked');
        expect(Operation::query()->where('kind', 'task_vm.resume')->where('target_id', $vm->id)->count())->toBe(1);
        Queue::assertNothingPushed();
    })->with(['cancel' => false, 'complete' => true]);

    it('archives proven transcripts and retains the workspace when native deletion is unavailable', function (TaskOrchestration $mode): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false, $mode);
        Storage::fake('local');
        $removal = app(RemoveTaskWorkspaceAction::class);
        app()->instance(ProjectLifecycleRunner::class, Mockery::mock(app(ProjectLifecycleRunner::class))->shouldReceive('run')->andReturn(true)->getMock());
        $provider = $this->provider;
        $readable = new class($provider) extends FakeAgentDriver
        {
            /** @var list<list<string>> */
            public array $providerCalls = [];

            public function __construct(private readonly RecordingTaskVmProvider $provider)
            {
                parent::__construct('example');
                $this->observation = new AgentObservation(AgentThreadState::Done, entries: [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved.', 'at' => '2026-10-14T18:00:00Z']]);
            }

            public function observe(AgentThread $thread): AgentObservation
            {
                $this->providerCalls[] = $this->provider->calls;

                return parent::observe($thread);
            }
        };
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$readable, new FakeAgentDriver('unreadable')]));
        $thread = static fn (string $driver, string $external): AgentThread => AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $vm->node_id, 'role' => 'reviewer', 'external_id' => $external,
            'driver' => $driver, 'runtime_key' => 'node:'.$vm->node_id,
        ]);
        $kept = $thread('example', 'session-one');
        $broken = $thread('unreadable', 'session-two');
        $pending = $thread('example', AgentThread::PendingPrefix.'not-started');
        // This actor completed under an earlier lease. A later unreadable transcript
        // does not make its proven completion uncertain.
        TaskWorkspaceLeaseRecord::query()->create([
            'instance_id' => $instance->id, 'group_id' => $group->id, 'kind' => 'reviewer',
            'owner' => 'thread:'.$broken->id, 'thread_id' => $broken->id,
            'status' => 'released', 'released_at' => now(),
        ]);
        Queue::fake();

        expect(fn () => $removal->remove($group))->toThrow(ResourceOperationException::class);

        expect($readable->providerCalls)->toBe([[], []])
            ->and($this->provider->calls)->toBe([])
            ->and(json_decode((string) Storage::disk('local')->get(ArchiveAgentTranscriptsAction::path($kept)), true))
            ->toMatchArray(['state' => 'done', 'entries' => [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved.', 'at' => '2026-10-14T18:00:00Z']]])
            ->and(Storage::disk('local')->exists(ArchiveAgentTranscriptsAction::path($broken)))->toBeFalse()
            ->and(Storage::disk('local')->exists(ArchiveAgentTranscriptsAction::path($pending)))->toBeFalse()
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue();
    })->with(TaskOrchestration::cases());

    it('keeps the VM and workspace when an unreadable actor has no authoritative completion', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([new FakeAgentDriver('unreadable')]));
        $thread = AgentThread::query()->create([
            'task_group_id' => $group->id, 'node_id' => $vm->node_id, 'role' => 'reviewer',
            'external_id' => 'unknown-session', 'driver' => 'unreadable', 'runtime_key' => 'node:'.$vm->node_id,
        ]);
        Queue::fake();

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))
            ->toThrow(TaskWorkspaceWaiting::class);

        expect($this->provider->calls)->toBe([])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue()
            ->and(TaskWorkspaceLeaseRecord::query()->where('active_instance_id', $instance->id)->sole()->thread_id)->toBe($thread->id)
            ->and(TaskWorkspaceLeaseRecord::query()->where('instance_id', $instance->id)->where('kind', 'teardown')->sole()->status)->toBe('queued');
        Queue::assertNothingPushed();
    });

    it('refuses unported VM cleanup without changing the provider or clearing credentials', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        Queue::fake();

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))
            ->toThrow(ResourceOperationException::class);

        $this->assertModelExists($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Ready)
            ->and($vm->fresh()->pi_token)->not->toBeNull()
            ->and($vm->fresh()->error_code)->toBeNull()
            ->and($this->provider->calls)->toBe([]);
        Queue::assertNothingPushed();
    });

});

describe(DestroyEndedTaskVmsAction::class, function (): void {});

function tvm_life_workspace(Task $group, Node $node): Instance
{
    return Instance::query()->create([
        'project_id' => test()->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public', 'branch' => 'task-'.$group->id,
        'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved,
    ]);
}

describe('failed VM claims', function (): void {
    it('keeps an ended group quiet when its VM fails', function (TaskGroupStatus $status): void {
        $group = tvm_life_group($status);
        $vm = tvm_life_vm($group, TaskVmState::Ready);
        $reason = TaskVmFailedException::stoppedReason('task_vm.vm_missing', 'The VM is gone.');

        expect(app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Ready], 'task_vm.vm_missing', 'The VM is gone.', $reason))->toBeTrue();

        expect($group->fresh())->status->toBe($status)->assistance_requested->toBeFalse()->assistance_reason->toBeNull();
        expect($vm->fresh()?->state)->toBe(TaskVmState::Failed);
    })->with([TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed]);

    it('keeps credentials when synchronous VM deletion is unavailable', function (): void {
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Ready);

        $token = $vm->pi_token;
        expect(fn () => app(DeleteTaskVmAction::class)->execute($vm))->toThrow(ResourceOperationException::class);
        expect($vm->fresh())->state->toBe(TaskVmState::Ready)->pi_token->toBe($token);
    });
});
