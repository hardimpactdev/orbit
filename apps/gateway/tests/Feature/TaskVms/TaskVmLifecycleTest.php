<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
use App\Domain\Tasks\TaskTopologyAdmission;
use App\Domain\Tasks\TaskWait;
use App\Domain\Tasks\TaskWorkspaceTopology;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFailedException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceTopology;
use App\Infrastructure\TaskVms\TaskVmBakedPrograms;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\EnrollTaskVm;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeToolManagerMaterializer;
use Tests\Support\RecordingTaskVmProvider;
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
function tvm_life_stranded_workspace(bool $removalFailed): array
{
    $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
    $group = tvm_life_group(TaskGroupStatus::Cancelled);
    $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
    $instance = Instance::query()->create([
        'project_id' => test()->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id,
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
        TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.');
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
    TaskVmSettingsBuilder::bind([
        'enabled' => $enabled, 'devClusterId' => $clusterId, 'modelProxyOrigin' => TVM_LIFE_ORIGIN,
        'hosts' => [TaskVmSettingsBuilder::host(test()->host->id, ['maxVms' => $maxVms])],
    ]);
}

/** @param list<array{0: int, 1: int}> $hosts node id and max_vms, in config order */
function tvm_life_hosts(array $hosts): void
{
    $cidrs = ['10.251.77.0/24', '10.251.78.0/24', '10.251.79.0/24'];
    TaskVmSettingsBuilder::bind([
        'devClusterId' => null, 'modelProxyOrigin' => TVM_LIFE_ORIGIN,
        'hosts' => array_map(static fn (array $host, int $index): TaskVmHost => TaskVmSettingsBuilder::host($host[0], ['cidr' => $cidrs[$index], 'maxVms' => $host[1]]), $hosts, array_keys($hosts)),
    ]);
}

function tvm_life_group(TaskGroupStatus $status = TaskGroupStatus::Reserved): Task
{
    return Task::topLevel()->create([
        'project_id' => test()->project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => $status,
        'task_compute' => TaskCompute::Vm, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
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
    it('records a provisioning row named after its id and queues its creation once', function (): void {
        Queue::fake();
        $group = tvm_life_group();

        $vm = app(AllocateTaskVmAction::class)->execute($group);
        $again = app(AllocateTaskVmAction::class)->execute($group);

        expect($again->id)->toBe($vm->id)
            ->and($vm->name)->toBe('tvm-'.$vm->id)
            ->and($vm->state)->toBe(TaskVmState::Provisioning)
            ->and($vm->host_node_id)->toBe($this->host->id)
            ->and($vm->wireguard_ip)->toBe('10.44.0.129')
            ->and($vm->pi_token)->toMatch('/\A[a-f0-9]{64}\z/');
        Queue::assertPushedOn('task-vms', ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id && $job->connection === 'task-vms');
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    });

    it('gives each live task VM its own address and waits when every host is full', function (): void {
        Queue::fake();
        $first = app(AllocateTaskVmAction::class)->execute(tvm_life_group());
        $second = app(AllocateTaskVmAction::class)->execute(tvm_life_group());

        expect($second->wireguard_ip)->toBe('10.44.0.130')
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))
            ->toThrow(TaskCapacityException::class, 'Task VM: no capacity.');

        $first->update(['state' => TaskVmState::Destroyed]);
        expect(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->wireguard_ip)->toBe('10.44.0.129');
    });

    it('places each new task VM on the active host with the most free slots, and never more than max_vms', function (): void {
        Queue::fake();
        $shark = tvm_life_node('shark', '10.44.0.8', 'nckrtl');
        tvm_life_hosts([[$this->host->id, 2], [$shark->id, 3]]);
        $place = fn (): int => app(AllocateTaskVmAction::class)->execute(tvm_life_group())->host_node_id;

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

        expect(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->host_node_id)->toBe($shark->id)
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))
            ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::Capacity)->and($wait->getMessage())->toBe('Task VM: no capacity.'));

        $parked->update(['state' => TaskVmState::Destroyed]);
        expect(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->host_node_id)->toBe($this->host->id);
    });

    it('gives a host whose max_vms dropped below its VMs no new VM and removes none', function (): void {
        Queue::fake();
        $shark = tvm_life_node('shark', '10.44.0.8', 'nckrtl');
        tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, wireguardIp: '10.44.0.129');
        tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, wireguardIp: '10.44.0.130');
        tvm_life_hosts([[$this->host->id, 1], [$shark->id, 1]]);

        expect(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->host_node_id)->toBe($shark->id)
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class, 'Task VM: no capacity.')
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

        expect(TaskTopology::forGroup($plain))->toBe([])
            ->and(TaskTopology::forGroup($topology))->toBe(['app-dev', 'app-prod+ingress']);

        $large = app(AllocateTaskVmAction::class)->execute($topology);
        $normal = app(AllocateTaskVmAction::class)->execute($plain);

        expect($large->refresh()->size)->toBe(TaskVmSize::Large)
            ->and($normal->refresh()->size)->toBe(TaskVmSize::Normal)
            ->and(app(AllocateTaskVmAction::class)->freeSlots())->toBe([$this->host->id => 0])
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::NoCapacity);

        $normal->update(['state' => TaskVmState::Destroyed]);
        $again = tvm_life_group();
        Task::query()->create(['parent_id' => $again->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);

        // One slot is free, so a medium VM for one test node waits, while a normal one still fits.
        expect(fn () => app(AllocateTaskVmAction::class)->execute($again))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::noCapacityFor(TaskVmSize::Medium))
            ->and(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->size)->toBe(TaskVmSize::Normal);
        Queue::assertPushed(ProvisionTaskVm::class, 3);
    });

    it('gives one test node a medium VM and more a large one, whatever roles they add', function (array $topology, TaskVmSize $size, int $slots): void {
        Queue::fake();
        tvm_life_settings(maxVms: 4);
        $group = tvm_life_group();
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Nodes', 'brief' => 'Nodes', 'status' => TaskStatus::Todo, 'topology' => $topology]);

        $vm = app(AllocateTaskVmAction::class)->execute($group)->refresh();

        expect($vm->size)->toBe($size)
            ->and(app(AllocateTaskVmAction::class)->freeSlots())->toBe([$this->host->id => 4 - $slots]);
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

        $medium = app(AllocateTaskVmAction::class)->execute(tvm_life_group());

        expect($medium->refresh()->size)->toBe(TaskVmSize::Medium)
            ->and(TaskVmSize::forGroup($topology, [$this->project->id]))->toBe(TaskVmSize::Large)
            ->and(app(AllocateTaskVmAction::class)->freeSlots())->toBe([$this->host->id => 1])
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class, AllocateTaskVmAction::noCapacityFor(TaskVmSize::Medium));
    });

    it('waits while another placement holds the lock and records nothing', function (): void {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('task-vms:placement', 30);
        expect($lock->get())->toBeTrue();

        try {
            expect(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))
                ->toThrow(TaskCapacityException::class, 'Task VM: another placement is running.')
                ->and(TaskVm::query()->count())->toBe(0);
        } finally {
            $lock->release();
        }
    });

    it('skips an inactive host', function (): void {
        Queue::fake();
        $this->host->update(['status' => LifecycleStatus::Failed]);

        expect(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class)
            ->and(TaskVm::query()->count())->toBe(0);
    });
});

describe('claim', function (): void {
    it('waits without a task VM while task VMs are off', function (): void {
        tvm_life_settings(enabled: false);

        expect(fn () => app(TaskVmWorkspace::class)->node(tvm_life_group()))
            ->toThrow(TaskCapacityException::class, 'Task VM: task VMs are not enabled on this Gateway.')
            ->and(TaskVm::query()->count())->toBe(0);
    });

    it('makes a subtask whose topology needs a larger VM wait with its cause', function (): void {
        $group = tvm_life_group(TaskGroupStatus::Running);
        $group->update(['taskable_type' => (new Instance)->getMorphClass(), 'taskable_id' => Instance::query()->create(['node_id' => $this->host->id, 'project_id' => $this->project->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/orbit/workspace'])->id]);
        $vm = tvm_life_vm($group, TaskVmState::Ready);
        $vm->update(['node_id' => $this->host->id]);
        $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);
        $admission = app(TaskTopologyAdmission::class);

        expect(fn () => $admission->prepare($group->refresh(), $subtask))
            ->toThrow(fn (TaskCapacityException $wait) => expect($wait->wait)->toBe(TaskWait::Topology)->and($wait->getMessage())->toBe(TaskVmTopologyAdmission::TooSmall));

        $vm->update(['size' => TaskVmSize::Medium]);
        Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Prod', 'brief' => 'Prod', 'status' => TaskStatus::Todo, 'topology' => ['app-prod']]);
        expect(fn () => $admission->prepare($group->refresh(), $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::TooSmall);
    });

    it('makes a shared group\'s subtask with a topology wait', function (): void {
        $group = Task::topLevel()->create([
            'project_id' => $this->project->id, 'title' => 'Shared', 'brief' => 'Work', 'status' => TaskGroupStatus::Running,
            'task_compute' => TaskCompute::Shared, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        ]);
        $subtask = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);

        expect(fn () => app(TaskTopologyAdmission::class)->prepare($group->refresh(), $subtask))->toThrow(TaskCapacityException::class, TaskVmTopologyAdmission::SharedGroup);
    });

    it('allocates on the first claim and waits until the task VM is ready', function (): void {
        Queue::fake();
        $group = tvm_life_group();

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

describe(ProvisionTaskVm::class, function (): void {
    it('launches the VM with the Gateway key in its user-data, then queues enrollment', function (): void {
        Queue::fake();
        mock(SshKeyProvider::class)->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway');
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new ProvisionTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe(['create'])
            ->and($this->provider->userData)->toStartWith("#cloud-config\n")->toContain('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway');
        Queue::assertPushedOn('task-vms', EnrollTaskVm::class, fn (EnrollTaskVm $job): bool => $job->taskVmId === $vm->id && abs($job->launchedAt - now()->getTimestamp()) <= 5);
    });

    it('logs the wait for the worker as its own step, apart from the launch', function (): void {
        Queue::fake();
        Log::spy();
        mock(SshKeyProvider::class)->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway');
        $this->freezeTime();
        $vm = tvm_life_vm(tvm_life_group());
        $this->travel(150)->seconds();
        $this->provider->onCreate = fn () => $this->travel(4)->seconds();

        app()->call([new ProvisionTaskVm($vm->id), 'handle']);

        // The row's time has whole seconds, so the queue step may count a fraction more.
        foreach (['queue' => 150.0, 'create' => 4.0] as $step => $seconds) {
            Log::shouldHaveReceived('info')->with('Task VM step done.', Mockery::on(static fn (array $context): bool => $context['task_vm'] === $vm->name
                && $context['step'] === $step && $context['seconds'] >= $seconds && $context['seconds'] <= $seconds + 1));
        }
    });

    it('never creates an enrolled or settled VM again', function (TaskVmState $state, bool $enrolled): void {
        Queue::fake();
        $group = tvm_life_group();
        $vm = tvm_life_vm($group, $state, $enrolled ? tvm_life_node('tvm-node', '10.44.0.140', role: RoleName::AppDev) : null);

        app()->call([new ProvisionTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([]);
        Queue::assertNotPushed(ProvisionTaskVm::class);
    })->with([
        'enrolled' => [TaskVmState::Provisioning, true],
        'failed' => [TaskVmState::Failed, false],
        'destroying' => [TaskVmState::Destroying, false],
    ]);

    it('marks only a provisioning row failed with the error code, and asks for assistance on its group', function (): void {
        $vm = tvm_life_vm($group = tvm_life_group());
        $destroying = tvm_life_vm(tvm_life_group(), TaskVmState::Destroying, wireguardIp: '10.44.0.131');
        $failure = new TaskVmException('task_vm.host_command_failed', '`incus launch` failed.', 502);

        (new ProvisionTaskVm($vm->id))->failed($failure);
        (new ProvisionTaskVm($destroying->id))->failed($failure);
        (new EnrollTaskVm($vm->id, now()->getTimestamp()))->failed(new RuntimeException('boom'));

        expect($vm->fresh()?->state)->toBe(TaskVmState::Failed)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.host_command_failed')
            ->and($vm->fresh()?->error_message)->toBe('`incus launch` failed.')
            ->and($destroying->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($destroying->fresh()?->error_code)->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeTrue()
            ->and($group->fresh()?->assistance_kind)->toBe(AssistanceKind::Failure)
            ->and($group->fresh()?->assistance_reason)->toBe("Task VM failed: {$vm->name}: task_vm.host_command_failed: `incus launch` failed. Cancel the group to destroy the VM.")
            ->and($destroying->group->fresh()?->assistance_requested)->toBeFalse();
    });

    it('retries a failed create once and is unique for its task VM', function (): void {
        $job = new ProvisionTaskVm(7);

        expect($job->tries)->toBe(2)->and($job->uniqueId())->toBe('7')->and($job->queue)->toBe('task-vms')
            ->and($job->failOnTimeout)->toBeTrue()
            ->and((new EnrollTaskVm(7, 0))->tries)->toBe(60)
            ->and((new EnrollTaskVm(7, 0))->failOnTimeout)->toBeTrue()
            ->and((new PrepareTaskVmRuntime(7))->failOnTimeout)->toBeTrue();
    });
});

describe(EnrollTaskVm::class, function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Sleep::fake();
        $this->converged = [];
        app()->instance(NodeConverger::class, new class($this->converged) implements NodeConverger
        {
            /** @param list<array<string, mixed>> $converged */
            public function __construct(private array &$converged) {}

            public function converge(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint = null, bool $rolelessOperator = false): NodeObservation
            {
                $this->converged[] = [
                    'host' => $node->public_ssh_host, 'jump' => $node->ssh_jump_node_id, 'wireguard_ip' => $node->wireguard_ip,
                    'user' => $identity->bootstrapUser, 'fingerprint' => $expectedSshHostFingerprint,
                    'linked' => TaskVm::query()->where('node_id', $node->id)->exists(),
                    'baked' => app(TaskVmBakedPrograms::class)->holds($node, 'bootstrap', new RemoteCommand(['bootstrap'])),
                ];

                return new NodeObservation('x86_64');
            }
        });
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
        app()->instance(MetricsFleetReconciler::class, Mockery::mock(MetricsFleetReconciler::class)->shouldReceive('reconcile')->getMock());
        $this->cluster = Cluster::query()->create(['name' => 'development', 'tld' => 'test', 'state' => ClusterState::Active]);
        tvm_life_settings(clusterId: $this->cluster->id);
    });

    it('polls while cloud-init runs, counting the boot limit from launch, not from the row', function (): void {
        $this->provider->ready = false;
        $vm = tvm_life_vm(tvm_life_group());
        $vm->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $job = (new EnrollTaskVm($vm->id, now()->subMinutes(2)->getTimestamp()))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(15);
        expect($this->converged)->toBe([])->and(Node::query()->where('name', $vm->name)->exists())->toBeFalse()
            ->and(array_count_values($this->provider->calls)['cloud-init'])->toBe(121);
        Sleep::assertSleptTimes(120);
    });

    it('enrolls in the same run when cloud-init finishes during its polls', function (): void {
        // Each poll reads the VM, then once more after cloud-init is done, until the host reports the address.
        $this->provider->observations = array_fill(0, 4, new VmObservation(true, null));
        $vm = tvm_life_vm(tvm_life_group());
        $job = (new EnrollTaskVm($vm->id, now()->getTimestamp()))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        Sleep::assertSleptTimes(2);
        expect($vm->fresh()?->node_id)->not->toBeNull();
    });

    it('enrolls without a fleet Metrics reconcile and logs each step', function (): void {
        app()->instance(MetricsFleetReconciler::class, Mockery::mock(MetricsFleetReconciler::class)->shouldNotReceive('reconcile')->getMock());
        Log::spy();
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->subSeconds(14)->getTimestamp()), 'handle']);

        $steps = [];
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use (&$steps, $vm): bool {
            if ($message === 'Task VM step done.' && $context['task_vm'] === $vm->name) {
                $steps[] = $context['step'];
            }

            return true;
        });
        expect($vm->fresh()?->node_id)->not->toBeNull()
            ->and($steps)->toBe(['enroll-queue', 'boot', 'node-converge', 'apt-tool-manager', 'roles', 'router-lan-ingress', 'dns-selection', 'activate', 'enrolled']);
    });

    it('enrolls the VM as its app-dev Node through the host, linked before any convergence', function (): void {
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        $node = Node::query()->where('name', $vm->name)->sole();
        expect($this->converged)->toBe([[
            'host' => '10.251.77.20', 'jump' => $this->host->id, 'wireguard_ip' => '10.44.0.129',
            'user' => 'orbit', 'fingerprint' => TVM_LIFE_FINGERPRINT, 'linked' => true, 'baked' => false,
        ]])
            ->and($vm->fresh()?->node_id)->toBe($node->id)
            ->and($vm->fresh()?->address)->toBe('10.251.77.20')
            ->and($node->status)->toBe(LifecycleStatus::Active)
            ->and($node->user)->toBe('orbit')
            ->and($node->cluster_id)->toBe($this->cluster->id)
            ->and($node->roles()->pluck('role')->all())->toBe([RoleName::AppDev])
            ->and($this->provider->calls)->toBe(['observe', 'cloud-init', 'fingerprint', 'manifest']);
        Queue::assertPushedOn('task-vms', PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);
    });

    it('loads the image manifest for the enrollment and drops it afterwards', function (): void {
        $vm = tvm_life_vm(tvm_life_group());
        $this->provider->manifest = TaskVmBakedPrograms::manifest(['bootstrap' => new RemoteCommand(['bootstrap'])]);

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        $node = Node::query()->where('name', $vm->name)->sole();
        expect($this->converged[0]['baked'])->toBeTrue()
            ->and(app(TaskVmBakedPrograms::class)->holds($node, 'bootstrap', new RemoteCommand(['bootstrap'])))->toBeFalse();
    });

    it('skips the baked programs for a warm pool VM too, which enrolls before any claim', function (): void {
        $vm = TaskVm::query()->create([
            'group_id' => null, 'host_node_id' => $this->host->id, 'provider' => 'incus', 'name' => 'tvm-pending',
            'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.129', 'pi_token' => null,
        ]);
        $vm->update(['name' => 'tvm-'.$vm->id]);
        $this->provider->manifest = TaskVmBakedPrograms::manifest(['bootstrap' => new RemoteCommand(['bootstrap'])]);

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        expect($this->converged[0]['baked'])->toBeTrue()
            ->and($vm->fresh()?->group_id)->toBeNull();
    });

    it('enrolls with every program and a warning when the image has no manifest', function (): void {
        Log::spy();
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        expect($this->converged[0]['baked'])->toBeFalse();
        Log::shouldHaveReceived('warning')->once()->with('Task VM image has no usable manifest; enrollment runs every program.', ['task_vm' => $vm->name, 'reason' => 'missing']);
    });

    it('checks cloud-init before the host reports the guest address', function (): void {
        $this->provider->observations = [new VmObservation(true, null), new VmObservation(true, '10.251.77.20')];
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        expect($vm->fresh()?->address)->toBe('10.251.77.20')
            ->and(array_slice($this->provider->calls, 0, 3))->toBe(['observe', 'cloud-init', 'observe']);
    });

    it('tolerates one stopped reading during a guest reboot', function (): void {
        $this->provider->observations = [new VmObservation(false, null), new VmObservation(true, '10.251.77.20')];
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        Sleep::assertSleptTimes(1);
        expect($vm->fresh()?->node_id)->not->toBeNull();
    });

    it('fails at once for a missing or stopped VM, a cloud-init error, or a slow boot', function (Closure $arrange, string $code): void {
        $vm = tvm_life_vm(tvm_life_group());
        $launchedAt = $arrange($this->provider, $vm);
        $job = (new EnrollTaskVm($vm->id, is_int($launchedAt) ? $launchedAt : now()->getTimestamp()))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailedWith(fn (TaskVmException $exception): bool => $exception->errorCode === $code);
        expect($this->converged)->toBe([]);
    })->with([
        'absent' => [fn (RecordingTaskVmProvider $provider) => $provider->observations = [null], 'task_vm.vm_not_running'],
        'stopped' => [fn (RecordingTaskVmProvider $provider) => $provider->observations = [new VmObservation(false, null), new VmObservation(false, null)], 'task_vm.vm_not_running'],
        'cloud-init error' => [fn (RecordingTaskVmProvider $provider) => $provider->cloudInitOutput = '{"status":"error","errors":["apt failed"]}', 'task_vm.bootstrap_failed'],
        'slow boot' => [function (RecordingTaskVmProvider $provider): int {
            $provider->ready = false;

            return now()->subMinutes(11)->getTimestamp();
        }, 'task_vm.bootstrap_timeout'],
    ]);

    it('only queues the runtime for a VM whose Node is already active', function (): void {
        $vm = tvm_life_vm(tvm_life_group(), node: tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev));

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        expect($this->provider->calls)->toBe([])->and($this->converged)->toBe([]);
        Queue::assertPushed(PrepareTaskVmRuntime::class);
    });
});

describe(PrepareTaskVmRuntime::class, function (): void {
    it('refuses a task VM without an active Node', function (): void {
        $vm = tvm_life_vm(tvm_life_group());

        expect(fn () => app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.not_enrolled'));
        expect($vm->fresh()?->state)->toBe(TaskVmState::Provisioning);
    });
});

describe(DestroyTaskVm::class, function (): void {
    beforeEach(function (): void {
        $this->gateway = tvm_life_node('gateway', '10.44.0.2', role: RoleName::Gateway);
        $this->events = new ArrayObject;
        $events = $this->events;
        $services = Mockery::mock(ServiceMetricsRuntime::class);
        $services->shouldReceive('snapshot')->andReturn('{}');
        $services->shouldReceive('converge');
        $services->shouldReceive('restore');
        app()->instance(ServiceMetricsRuntime::class, $services);
        app()->instance(MetricsAccessRevoker::class, new class implements MetricsAccessRevoker
        {
            public function revoke(): void {}
        });
        app()->instance(NodeReachabilityProbe::class, new class implements NodeReachabilityProbe
        {
            public function degradation(Node $node): ?ExporterDegradationReason
            {
                return ExporterDegradationReason::Unreachable;
            }
        });
        app()->instance(PrivateDnsManager::class, new class($events) implements PrivateDnsManager
        {
            public function __construct(private ArrayObject $events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events->append('node removal: '.TaskVm::query()->sole()->state->value);
            }
        });
        $this->projector = new TaskVmLifecycleProjector;
        app()->instance(InstanceRemovalProjector::class, $this->projector);
        $this->provider->onDestroy = static function (TaskVm $vm) use ($events): void {
            $events->append('vm deletion: '.$vm->fresh()?->state->value);
        };
        app(ProxyCliState::class)->enable(1, 'cache', TVM_LIFE_ORIGIN, 'management-key', 'read-token', 'control-token');
        $keys = new ArrayObject([str_repeat('a', 64)]);
        Http::fake([TVM_LIFE_ORIGIN.'/*' => function (Request $request) use ($events, $keys) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v0/management/api-keys') {
                if ($request->method() === 'PATCH') {
                    $kept = array_values(array_filter($keys->getArrayCopy(), static fn (string $key): bool => $key !== $request['old']));
                    $keys->exchangeArray($request['new'] === '' ? $kept : [...$kept, $request['new']]);
                    if ($request['new'] === '') {
                        $events->append('key revoke: '.TaskVm::query()->sole()->state->value);
                    }
                }

                return Http::response(['api-keys' => $keys->getArrayCopy()]);
            }
            $token = substr($request->header('Authorization')[0] ?? '', 7);

            return Http::response([], in_array($token, $keys->getArrayCopy(), true) ? 200 : 401);
        }]);
    });

    it('revokes the key, deletes the VM and removes the Node before the row is destroyed', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $node->processes()->create([
            'name' => 'pi-server', 'runtime' => ProcessRuntime::Systemd, 'working_directory' => '/home/orbit',
            'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve']], 'restart_policy' => 'always',
            'status' => LifecycleStatus::Active,
        ]);
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, $node);
        $vm->update(['model_key' => str_repeat('a', 64), 'model_proxy_origin' => TVM_LIFE_ORIGIN]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect(array_values(array_unique($this->events->getArrayCopy())))->toBe(['key revoke: destroying', 'vm deletion: destroying', 'node removal: destroying'])
            ->and(Node::query()->find($node->id))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)
            ->and($vm->fresh()?->destroyed_at)->not->toBeNull()
            ->and($vm->fresh()?->model_key)->toBeNull()
            ->and($vm->fresh()?->node_id)->toBeNull();
    });

    it('removes the Node without probing the deleted VM and without a Metrics pass', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, $node);
        // Each probe would wait out the SSH connect timeout against the dead address, and each Metrics pass would
        // walk the whole fleet, though a task VM Node never hosts an exporter.
        app()->instance(NodeReachabilityProbe::class, new class implements NodeReachabilityProbe
        {
            public function degradation(Node $node): ?ExporterDegradationReason
            {
                throw new RuntimeException('The deleted VM was probed.');
            }
        });
        $metrics = Mockery::mock(MetricsFleetReconciler::class);
        $metrics->shouldNotReceive('retire');
        $metrics->shouldNotReceive('reconcile');
        app()->instance(MetricsFleetReconciler::class, $metrics);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect(Node::query()->find($node->id))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed);
    });

    it('waits while an Instance is left on the Node', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Cancelled);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('keeps the row destroying with the error when a step fails, and retries until it succeeds', function (): void {
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed);
        $this->provider->onDestroy = static fn () => throw new TaskVmException('task_vm.host_command_failed', '`incus delete` failed.', 502);

        expect(fn () => app()->call([new DestroyTaskVm($vm->id), 'handle']))->toThrow(TaskVmException::class);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.host_command_failed')
            ->and((new DestroyTaskVm($vm->id))->tries)->toBe(0);

        $this->provider->onDestroy = null;
        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)->and($vm->fresh()?->error_code)->toBeNull();
    });

    it('queues one destroy job at a time for a task VM', function (): void {
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed);

        DestroyTaskVm::dispatch($vm->id);
        DestroyTaskVm::dispatch($vm->id);
        app(DestroyEndedTaskVmsAction::class)->execute();

        expect(DB::table('jobs')->count())->toBe(1);
    });

    it('leaves a workspace to normal removal while that removal has not failed, or while the VM runs', function (bool $removalFailed): void {
        [$vm, $instance] = tvm_life_stranded_workspace($removalFailed);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe($removalFailed ? ['observe'] : [])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($instance->fresh())->not->toBeNull();
    })->with(['removal not failed' => false, 'VM still runs' => true]);

    it('keeps the workspace of a group that still runs after its removal failed, even when the VM is gone', function (TaskGroupStatus $status): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->update(['status' => $status]);
        TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The teardown step failed.');
        $this->provider->observations = [null];

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($instance->fresh())->not->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeTrue();
    })->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing]);

    it('deletes a VM that died under its workspace, forgets the workspace offline, and frees the Node', function (): void {
        [$vm, $instance, $route, $group] = tvm_life_stranded_workspace(true);
        $this->provider->observations = [null];

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $member = InstanceRemovalMember::query()->sole();
        expect($this->provider->calls)->toBe(['observe', 'destroy'])
            ->and($this->projector->cleared)->toBe([$route->id])
            ->and(Instance::query()->find($instance->id))->toBeNull()
            ->and(Route::query()->find($route->id))->toBeNull()
            ->and(Process::query()->count())->toBe(0)
            ->and($member->row_deleted_at)->not->toBeNull()
            ->and($member->source_identity)->toBe('task-vm:'.$vm->name)
            ->and($member->removal->status)->toBe(InstanceRemovalStatus::Completed)
            ->and(Node::query()->find($vm->node_id ?? 0))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)
            ->and($group->fresh()?->taskable_id)->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeFalse();
    });

    it('cancels a running group by deleting its VM with the workspace, then removes the Node in the queued destroy', function (bool $earlierFailure): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Running);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id,
            'branch_override' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public',
            'branch' => 'task-'.$group->id, 'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved]);
        $group->taskable()->associate($instance);
        $group->save();
        if ($earlierFailure) {
            // The stuck state an earlier cancel left: the group still runs and asks for help, the VM still runs.
            TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'Sandbox workload nodes must be managed by the compute driver.');
        }
        app(TaskExtensionState::class)->enable();
        bind_task_node_reachability();
        // No SSH to the VM: the checkout goes with the VM, and the Project has no teardown steps.
        mock(SshExecutor::class)->shouldReceive('execute')->never();
        app()->instance(TaskWorkspaceTopology::class, app(RemoteTaskWorkspaceTopology::class));
        app()->instance(InstanceRemover::class, mock(InstanceRemover::class)->shouldReceive('execute')->never()->getMock());
        Queue::fake();

        $cancelled = app(CancelTaskGroupAction::class)->execute($group);

        $member = InstanceRemovalMember::query()->sole();
        expect($cancelled->status)->toBe(TaskGroupStatus::Cancelled)
            ->and($cancelled->taskable_id)->toBeNull()
            ->and($cancelled->assistance_requested)->toBeFalse()
            ->and(Instance::query()->find($instance->id))->toBeNull()
            ->and($member->source_identity)->toBe('task-vm:'.$vm->name)
            ->and($member->removal->status)->toBe(InstanceRemovalStatus::Completed)
            ->and($this->provider->calls)->toBe(['destroy'])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroying);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);

        bind_task_node_reachability(unreachable: true);
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        // Deleting an absent VM again is harmless; the destroy then removes the Node.
        expect($this->provider->calls)->toBe(['destroy', 'destroy'])
            ->and(Node::query()->find($node->id))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed);
    })->with(['first cancel' => false, 'retry after an earlier failure' => true]);

    it('runs the Project teardown in a ready VM before it deletes the VM, once', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $runs = new ArrayObject;
        // The runner is final: a partial mock of an instance stands in for it, bound after the action's own dependencies.
        $removal = app(RemoveTaskWorkspaceAction::class);
        $runner = Mockery::mock(app(ProjectLifecycleRunner::class));
        $runner->shouldReceive('run')->andReturnUsing(function (Instance $ran, LifecyclePhase $phase) use ($runs): bool {
            $runs->append([$ran->id, $phase, $this->provider->calls]);

            return true;
        });
        app()->instance(ProjectLifecycleRunner::class, $runner);
        Queue::fake();

        $removal->remove($instance);

        expect($runs->getArrayCopy())->toBe([[$instance->id, LifecyclePhase::Teardown, []]])
            ->and($this->provider->calls)->toBe(['destroy'])
            ->and(Instance::query()->find($instance->id))->toBeNull();
    });

    it('keeps the workspace and records the error on the VM when the VM cannot be deleted', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $this->provider->onDestroy = static fn () => throw new TaskVmException('task_vm.delete_failed', 'The VM could not be deleted.', 502);
        Queue::fake();

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($instance))->toThrow(TaskVmException::class);

        expect(Instance::query()->find($instance->id))->not->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.delete_failed');
        Queue::assertNotPushed(DestroyTaskVm::class);
    });
});

describe(DestroyEndedTaskVmsAction::class, function (): void {
    it('queues destruction only for ended groups whose workspace is gone and whose claim is over', function (): void {
        Queue::fake();
        $ended = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, tvm_life_node('tvm-a', '10.44.0.129'));
        $failed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed, wireguardIp: '10.44.0.130');
        $running = tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, tvm_life_node('tvm-b', '10.44.0.131'));
        $withWorkspace = tvm_life_vm($group = tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Ready, $node = tvm_life_node('tvm-c', '10.44.0.132'));
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);
        $claimed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Provisioning, wireguardIp: '10.44.0.133');
        $claimed->group->update(['reserved_at' => now()]);
        $destroyed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Destroyed);

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(2);

        $queued = Queue::pushed(DestroyTaskVm::class)->map(fn (DestroyTaskVm $job): int => $job->taskVmId)->sort()->values()->all();
        expect($queued)->toBe([$ended->id, $failed->id])
            ->and([$running->id, $withWorkspace->id, $claimed->id, $destroyed->id])->not->toContain(...$queued);
    });

    it('queues destruction for a workspace whose removal failed after its group ended, so the job can check the VM', function (bool $removalFailed, TaskGroupStatus $status): void {
        Queue::fake();
        [$vm, , , $group] = tvm_life_stranded_workspace(false);
        $group->update(['status' => $status]);
        if ($removalFailed) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.');
        }
        $queued = $removalFailed && $status !== TaskGroupStatus::Running;

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe($queued ? 1 : 0);
        if ($queued) {
            Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
        }
    })->with([
        'removal failed, cancelled' => [true, TaskGroupStatus::Cancelled],
        'merge cleanup failed, settling' => [true, TaskGroupStatus::Settling],
        'removal failed, group still runs' => [true, TaskGroupStatus::Running],
        'removal pending' => [false, TaskGroupStatus::Cancelled],
    ]);

    it('queues destruction on the next tick for a group that completes soon after its claim', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        app(TaskExtensionState::class)->enable();
        tvm_life_provision(static fn (InstanceProvisionIntent $intent): Instance => tvm_life_workspace($intent->group, $node));

        expect(app(TaskScheduler::class)->claimNext()?->status)->toBe(TaskGroupStatus::Running);

        $this->travel(10)->minutes();
        $group->refresh()->taskable?->delete();
        $group->update(['status' => TaskGroupStatus::Completed, 'taskable_type' => null, 'taskable_id' => null]);
        Queue::fake();

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(1);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('makes a todo group whose task VM failed ask for assistance at once, naming the VM, and gives it no other VM', function (): void {
        Queue::fake();
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $vm = tvm_life_vm($group, TaskVmState::Failed);
        $vm->update(['error_code' => 'task_vm.bootstrap_failed', 'error_message' => 'Cloud-init failed.']);
        app(TaskExtensionState::class)->enable();
        mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once();
        $reason = "Task VM failed: {$vm->name}: task_vm.bootstrap_failed: Cloud-init failed. Cancel the group to destroy the VM.";

        foreach ([1, 2] as $claim) {
            expect(app(TaskScheduler::class)->claimNext())->toBeNull();
            $fresh = $group->fresh();
            expect($fresh?->status)->toBe(TaskGroupStatus::Todo)
                ->and($fresh?->reserved_at)->toBeNull()
                ->and($fresh?->capacity_wait_reason)->toBeNull()
                ->and($fresh?->assistance_requested)->toBeTrue()
                ->and($fresh?->assistance_kind)->toBe(AssistanceKind::Failure)
                ->and($fresh?->assistance_reason)->toBe($reason)
                ->and(TaskVm::query()->sole()->state)->toBe(TaskVmState::Failed);
        }
        Queue::assertNotPushed(ProvisionTaskVm::class);
    });

    it('waits for a claim in flight when its group is cancelled, and deletes the VM with the workspace once the claim ends', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        app(TaskExtensionState::class)->enable();
        bind_task_node_reachability();
        app()->instance(InstanceRemover::class, new class implements InstanceRemover
        {
            public function execute(Instance $instance, bool $force): InstanceRemoval
            {
                $instance->delete();

                return new InstanceRemoval;
            }
        });
        Queue::fake();
        $whileClaiming = [];
        tvm_life_provision(static function (InstanceProvisionIntent $intent) use ($node, &$whileClaiming): Instance {
            app(CancelTaskGroupAction::class)->execute($intent->group);
            $whileClaiming[] = app(DestroyEndedTaskVmsAction::class)->execute();
            $workspace = tvm_life_workspace($intent->group, $node);
            $whileClaiming[] = app(DestroyEndedTaskVmsAction::class)->execute();

            return $workspace;
        });

        expect(app(TaskScheduler::class)->claimNext())->toBeNull()
            ->and($whileClaiming)->toBe([0, 0])
            ->and($group->fresh()?->status)->toBe(TaskGroupStatus::Cancelled)
            ->and(Instance::query()->where('node_id', $node->id)->exists())->toBeFalse()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($this->provider->calls)->toBe(['destroy']);
        Queue::assertPushed(DestroyTaskVm::class, 1);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(1);
    });
});

/** Binds a workspace provisioner for claims that runs the given closure. */
function tvm_life_provision(Closure $provision): void
{
    app()->instance(InstanceProvisioning::class, new class($provision) implements InstanceProvisioning
    {
        public function __construct(private Closure $provision) {}

        public function provision(InstanceProvisionIntent $intent): ?Instance
        {
            return ($this->provision)($intent);
        }
    });
}

function tvm_life_workspace(Task $group, Node $node): Instance
{
    return Instance::query()->create([
        'project_id' => test()->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id, 'root' => 'public', 'branch' => 'task-'.$group->id,
        'starting_commit' => str_repeat('a', 40), 'status' => InstanceState::SourceResolved,
    ]);
}

describe('worker', function (): void {
    it('runs from the Gateway scheduler, in the foreground before the template worker, only while a task VM job waits', function (): void {
        Artisan::call('schedule:list');
        $events = app(Schedule::class)->events();
        $worker = collect($events)->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

        expect($worker)->not->toBeNull()
            ->and($worker->command)->toEndWith("'artisan' queue:work task-vms --queue=task-vms --stop-when-empty --max-time=120 --timeout=1500")
            ->and($worker)->toBe($events[count($events) - 2])
            ->and($worker->expression)->toBe('* * * * *')
            ->and($worker->repeatSeconds)->toBe(1)
            ->and($worker->withoutOverlapping)->toBeTrue()
            ->and($worker->expiresAt)->toBe(30)
            ->and($worker->runInBackground)->toBeFalse()
            ->and($worker->filtersPass(app()))->toBeFalse();

        DestroyTaskVm::dispatch(tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed)->id);

        expect($worker->filtersPass(app()))->toBeTrue()
            ->and(DB::table('jobs')->sole()->queue)->toBe('task-vms');
    });

    it('starts only for a task VM job that a worker would take now', function (): void {
        $this->freezeSecond();
        Artisan::call('schedule:list');
        $worker = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));
        DestroyTaskVm::dispatch(tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed)->id)->delay(now()->addSeconds(60));
        $job = DB::table('jobs')->sole();

        // A delayed retry or release starts no worker until it is due.
        expect($worker->filtersPass(app()))->toBeFalse();

        $this->travel(60)->seconds();
        expect($worker->filtersPass(app()))->toBeTrue();

        // A running worker reserved it; a reservation older than retry_after counts as abandoned.
        DB::table('jobs')->where('id', $job->id)->update(['reserved_at' => now()->getTimestamp()]);
        expect($worker->filtersPass(app()))->toBeFalse();

        $this->travel(1800)->seconds();
        expect($worker->filtersPass(app()))->toBeTrue();
    });
});
