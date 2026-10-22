<?php

declare(strict_types=1);

use App\Actions\Tasks\ArchiveAgentTranscriptsAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DeleteTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Actions\TaskVms\FailTaskVmAction;
use App\Actions\TaskVms\WakeTaskVmAction;
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
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestPublisher;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskScheduler;
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
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Domain\TaskVms\VmObservation;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmTopologyAdmission;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\EnrollTaskVm;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\ResumeTaskVm;
use App\Models\Activity;
use App\Models\AgentThread;
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
use App\Models\TaskCheck;
use App\Models\TaskComment;
use App\Models\TaskReviewedCommit;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Providers\TaskVmServiceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeToolManagerMaterializer;
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
function tvm_life_stranded_workspace(bool $removalFailed, TaskOrchestration $orchestration = TaskOrchestration::Tick): array
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
        'enabled' => $enabled, 'devClusterId' => $clusterId, 'modelProxyOrigin' => TVM_LIFE_ORIGIN,
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

function tvm_life_group(TaskGroupStatus $status = TaskGroupStatus::Reserved, TaskOrchestration $orchestration = TaskOrchestration::Tick): Task
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
        Queue::assertPushedOn('task-vms', ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id && $job->connection === 'gateway');
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

        expect(TaskTopology::forGroup($plain)->toArray())->toBe([])
            ->and(TaskTopology::forGroup($topology)->toArray())->toBe(['app-dev', 'app-prod+ingress']);

        $large = app(AllocateTaskVmAction::class)->execute($topology);
        $normal = app(AllocateTaskVmAction::class)->execute($plain);

        expect($large->refresh()->size)->toBe(TaskVmSize::Large)
            ->and($normal->refresh()->size)->toBe(TaskVmSize::Normal)
            ->and(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe([$this->host->id => 0])
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::NoCapacity);

        $normal->update(['state' => TaskVmState::Destroyed]);
        $again = tvm_life_group();
        Task::query()->create(['parent_id' => $again->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev']]);

        // One slot is free, so a medium VM for one test node waits, while a normal one still fits.
        expect(fn () => app(AllocateTaskVmAction::class)->execute($again))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Medium))
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

        $medium = app(AllocateTaskVmAction::class)->execute(tvm_life_group());

        expect($medium->refresh()->size)->toBe(TaskVmSize::Medium)
            ->and(TaskVmSize::forGroup($topology, [$this->project->id]))->toBe(TaskVmSize::Large)
            ->and(app(TaskVmCapacity::class)->freeSlots(app(TaskVmCapacity::class)->activeHosts()))->toBe([$this->host->id => 1])
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::noCapacityFor(TaskVmSize::Medium));
    });

    it('allocates a new VM without the obsolete placement cache lock', function (): void {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('task-vms:placement', 30);
        expect($lock->get())->toBeTrue();

        try {
            $vm = app(AllocateTaskVmAction::class)->execute(tvm_life_group());
            expect($vm->state)->toBe(TaskVmState::Provisioning)
                ->and(TaskVm::query()->count())->toBe(1);
            Queue::assertPushed(ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id);
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
        $this->provider->observations = [new VmObservation(true, '10.251.77.20', str_repeat('b', 64))];
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        $node = Node::query()->where('name', $vm->name)->sole();
        expect($this->converged)->toBe([[
            'host' => '10.251.77.20', 'jump' => $this->host->id, 'wireguard_ip' => '10.44.0.129',
            'user' => 'orbit', 'fingerprint' => TVM_LIFE_FINGERPRINT, 'linked' => true,
        ]])
            ->and($vm->fresh()?->node_id)->toBe($node->id)
            ->and($vm->fresh()?->address)->toBe('10.251.77.20')
            ->and($vm->fresh()?->image_fingerprint)->toBe(str_repeat('b', 64))
            ->and($node->status)->toBe(LifecycleStatus::Active)
            ->and($node->user)->toBe('orbit')
            ->and($node->cluster_id)->toBe($this->cluster->id)
            ->and($node->roles()->pluck('role')->all())->toBe([RoleName::AppDev])
            ->and($this->provider->calls)->toBe(['observe', 'cloud-init', 'fingerprint']);
        Queue::assertPushedOn('task-vms', PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);
    });

    it('links a warm pool VM before convergence too, before any claim', function (): void {
        $vm = TaskVm::query()->create([
            'group_id' => null, 'host_node_id' => $this->host->id, 'provider' => 'incus', 'name' => 'tvm-pending',
            'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.129', 'pi_token' => null,
        ]);
        $vm->update(['name' => 'tvm-'.$vm->id]);

        app()->call([new EnrollTaskVm($vm->id, now()->getTimestamp()), 'handle']);

        expect($this->converged[0]['linked'])->toBeTrue()
            ->and($vm->fresh()?->group_id)->toBeNull();
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
        app(AiRouterState::class)->enable(1, 'cache', TVM_LIFE_ORIGIN, 'management-key', 'read-token', 'control-token');
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
        $vm->update(['model_key' => str_repeat('a', 64), 'ai_router_origin' => TVM_LIFE_ORIGIN]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect(array_values(array_unique($this->events->getArrayCopy())))->toBe(['key revoke: destroying', 'vm deletion: destroying', 'node removal: destroying'])
            ->and(Node::query()->find($node->id))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)
            ->and($vm->fresh()?->destroyed_at)->not->toBeNull()
            ->and($vm->fresh()?->pi_token)->toBeNull()
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
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('handles an ownership refusal as a removal failure when the ended groups VM is gone', function (): void {
        Queue::fake();
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev));
        $instance = tvm_life_workspace($vm->group, $vm->node);
        $this->provider->observations = [null];
        try {
            TaskVmWorkspaceGuard::assertWorkspace($vm->group, Instance::query()->make([
                'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
            ]));
            throw new RuntimeException('Expected ownership refusal.');
        } catch (TaskVmException $exception) {
            app(RemoveTaskWorkspaceAction::class)->recordFailure($vm->group, $exception);
        }

        app(DestroyEndedTaskVmsAction::class)->execute();
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe(['observe', 'destroy'])
            ->and($vm->fresh()->state)->toBe(TaskVmState::Destroyed)
            ->and($vm->group->fresh()->assistance_requested)->toBeFalse();
        $this->assertModelMissing($instance);
        Queue::assertPushed(DestroyTaskVm::class);
    });

    it('destroys the VM past an Instance its group does not own, and forgets that row with the Node', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Cancelled);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        // An Instance its group does not own, such as a row the backfill left without an owner because its group held
        // two Instances. The guard refuses such a row today.
        $leftover = Instance::withoutEvents(fn (): Instance => Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'branch_override' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]));

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe(['destroy'])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)
            ->and(Instance::query()->find($leftover->id))->toBeNull()
            ->and(Node::query()->find($node->id))->toBeNull();
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
        TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The teardown step failed.');
        $this->provider->observations = [null];

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($instance->fresh())->not->toBeNull()
            ->and($group->fresh()?->assistance_requested)->toBeTrue();
    })->with([TaskGroupStatus::Running, TaskGroupStatus::Reviewing]);

    it('deletes a VM that died under its workspace, forgets the workspace offline, and frees the Node', function (TaskGroupStatus $status): void {
        [$vm, $instance, $route, $group] = tvm_life_stranded_workspace(true);
        $group->update(['status' => $status]);
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
    })->with([TaskGroupStatus::Cancelled, TaskGroupStatus::Failed]);

    it('cancels a running group by deleting its VM with the workspace, then removes the Node in the queued destroy', function (bool $earlierFailure): void {
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

    it('sweeps an ended group through Project teardown before deleting its VM', function (TaskGroupStatus $status, TaskOrchestration $mode): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false, orchestration: $mode);
        $group->update(['status' => $status]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Failed start', 'brief' => 'Work', 'status' => TaskStatus::Failed]);
        $receipt = $task->comments()->create(['task_group_id' => $group->id, 'type' => 'ready_for_review', 'body' => 'Stored receipt', 'author' => 'implementer', 'posted_at' => now()]);
        $check = $task->checks()->create(['kind' => 'handoff', 'status' => 'failed', 'pid' => 42, 'process_started' => 'start', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'output' => 'Stored check output', 'started_at' => now()]);
        $runs = new ArrayObject;
        // The runner is final: a partial mock of an instance stands in for it, bound after the action's own dependencies.
        $scheduler = app(TaskScheduler::class);
        $lifecycle = app(ProjectLifecycleRunner::class);
        $runner = Mockery::mock($lifecycle);
        $runner->shouldReceive('run')->andReturnUsing(function (Instance $ran, LifecyclePhase $phase) use ($runs): bool {
            $runs->append([$ran->id, $phase, $this->provider->calls]);

            return true;
        });
        app()->instance(ProjectLifecycleRunner::class, $runner);
        Queue::fake();

        expect($scheduler->removeAbandonedWorkspaces())->toBe(1);

        expect($runs->getArrayCopy())->toBe([[$instance->id, LifecyclePhase::Teardown, []]])
            ->and($this->provider->calls)->toBe(['destroy'])
            ->and(Instance::query()->find($instance->id))->toBeNull();
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
        app()->instance(ProjectLifecycleRunner::class, $lifecycle);
        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh())->status->toBe($status)->taskable_id->toBeNull();
        expect(TaskComment::query()->findOrFail($receipt->id)->body)->toBe('Stored receipt');
        expect(TaskCheck::query()->findOrFail($check->id)->output)->toBe('Stored check output');
    })->with([TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed])->with(TaskOrchestration::cases());

    it('pushes the final-reviewed approval before an ended group loses its workspace and VM', function (TaskGroupStatus $status): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => $status]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved work', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $sha = str_repeat('c', 40);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Final reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $events = new ArrayObject;
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->withArgs(function (Task $pushed, string $commit) use ($group, $instance, $sha, $events): bool {
            $events->append('push');
            expect($this->provider->calls)->toBe([]);
            $this->assertModelExists($instance);

            return $pushed->id === $group->id && $commit === $sha;
        });
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        $scheduler = app(TaskScheduler::class);
        $runner = Mockery::mock(app(ProjectLifecycleRunner::class));
        $runner->shouldReceive('run')->andReturnUsing(function (Instance $ran, LifecyclePhase $phase) use ($events): bool {
            $events->append($phase->value);

            return true;
        });
        app()->instance(ProjectLifecycleRunner::class, $runner);
        Log::spy();
        Queue::fake();

        expect($scheduler->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($events->getArrayCopy())->toBe(['push', 'teardown']);
        expect($this->provider->calls)->toBe(['destroy']);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
        Log::shouldHaveReceived('info')->with('Pushed approved work before removing a task workspace.', Mockery::on(fn (array $context): bool => $context['task_group_id'] === $group->id && $context['commit_sha'] === $sha));
    })->with([TaskGroupStatus::Completed, TaskGroupStatus::Failed, TaskGroupStatus::Cancelled]);

    it('keeps a failed group VM and workspace while its approved push fails or waits', function (bool $pending): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved work', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $sha = str_repeat('c', 40);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Final reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->andThrow($pending ? TaskPullRequestException::pending('Push queued.') : new TaskPullRequestException('Push refused.'))->ordered();
        $publisher->shouldReceive('push')->once()->andReturnNull()->ordered();
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        Queue::fake();

        $scheduler = app(TaskScheduler::class);
        expect($scheduler->removeAbandonedWorkspaces())->toBe(0);

        $this->assertModelExists($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
        expect($this->provider->calls)->toBe([]);
        expect($group->fresh()->assistance_requested)->toBe(! $pending);
        if (! $pending) {
            expect($group->fresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure);
            expect($group->fresh()->assistance_reason)->toBe('Workspace removal failed: Push refused.');
        }
        Queue::assertNotPushed(DestroyTaskVm::class);

        $this->travel(61)->seconds();
        app()->instance(ProjectLifecycleRunner::class, Mockery::mock(app(ProjectLifecycleRunner::class))->shouldReceive('run')->andReturn(true)->getMock());
        expect($scheduler->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($this->provider->calls)->toBe(['destroy']);
        expect($group->fresh()->assistance_requested)->toBeFalse();
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    })->with(['failed' => false, 'pending' => true]);

    it('preserves the latest reviewed unpublished head despite a newer unreviewed approval', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Reviewed work', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $reviewed = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $reviewed, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Later work, final review requested changes.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => str_repeat('d', 40)]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->withArgs(fn (Task $pushed, string $sha): bool => $pushed->id === $group->id && $sha === $reviewed);
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        Queue::fake();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($this->provider->calls)->toBe(['destroy']);
    });

    it('starts a stopped VM through the wake path before preserving its reviewed head', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $sha = str_repeat('c', 40);
        $reviewed = TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->observations = [new VmObservation(false, null), new VmObservation(false, null)];
        Sleep::fake();
        Queue::fake();
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->withArgs(fn (Task $pushed, string $commit): bool => $pushed->id === $group->id && $commit === $sha && in_array('start', $this->provider->calls, true));
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
        mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
        app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);
        Http::fake(['http://10.44.0.129:3774/capabilities' => Http::response(['models' => []])]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelExists($instance);
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
        app()->call([new ResumeTaskVm($vm->id), 'handle']);
        expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
        app(RemoveTaskWorkspaceAction::class)->remove($group);
        expect($reviewed->fresh()->pushed_at)->not->toBeNull();
        $this->assertModelMissing($instance);
        expect($this->provider->calls)->toBe(['observe', 'observe', 'start', 'destroy']);
    });

    it('destroys a VM after its preservation start fails instead of waking it again', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $sha = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->observation = new VmObservation(false, null);
        $this->provider->startError = new TaskVmException('task_vm.start_failed', 'The provider could not start the VM.', 502);
        Sleep::fake();
        Queue::fake([ResumeTaskVm::class, DestroyTaskVm::class]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($vm->fresh()->state)->toBe(TaskVmState::Parked);
        $resume = new ResumeTaskVm($vm->id);
        try {
            app()->call([$resume, 'handle']);
            throw new LogicException('Expected start failure.');
        } catch (TaskVmException $exception) {
            $resume->failed($exception);
        }
        expect($vm->fresh()->state)->toBe(TaskVmState::Failed);
        expect(app(WakeTaskVmAction::class)->forVm($vm->fresh(), stopped: true))->toBeTrue();
        expect($vm->fresh()->state)->toBe(TaskVmState::Failed);
        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
        expect($group->fresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelMissing($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh()->assistance_reason)->toContain('Reviewed work lost', $sha, 'The provider could not start the VM.');
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('properties->reason', 'like', '%Reviewed work lost%')->sole()->properties->get('reason'))->toContain($sha);
        Queue::assertPushed(ResumeTaskVm::class, 1);
        expect($this->provider->calls)->toBe(['observe', 'observe', 'start', 'destroy']);
    });

    it('keeps one reason while a missing ready VM waits through removal sweeps', function (bool $enabled, LifecycleStatus $hostStatus, string $pause, TaskVmProviderKind $provider = TaskVmProviderKind::Incus): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        tvm_life_settings(enabled: $enabled);
        config(['task_vms.enabled' => $enabled]);
        $this->host->update(['status' => $hostStatus]);
        $vm->update(['provider' => $provider, 'host_node_id' => $provider->needsHostNode() ? $this->host->id : null]);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $sha = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->observation = null;
        Queue::fake([ResumeTaskVm::class]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->andThrow(new TaskPullRequestException('The task workspace is unavailable.'));
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        mock(TaskCheckRunner::class)->shouldNotReceive('snapshot');
        $this->freezeTime();

        for ($cycle = 0; $cycle < 3; $cycle++) {
            app()->call([new DestroyTaskVm($vm->id), 'handle']);
            expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
            $this->travel(31)->minutes();
        }
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelExists($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('description', 'assistance changed')->count())->toBe(1);
        expect($group->fresh()->assistance_kind)->toBe(AssistanceKind::RemovalFailure);
        expect($group->fresh()->assistance_reason)->toBe("Task VM [{$vm->name}] was not found on its host. Reviewed commit [{$sha}] has not been pushed. Workspace removal waits for the host sweep to confirm loss.".$pause);
        $publisher->shouldNotHaveReceived('push');
        Queue::assertNotPushed(ResumeTaskVm::class);
    })->with([
        'task VMs disabled' => [false, LifecycleStatus::Active, ' The Incus host sweep is paused because task VMs are disabled.'],
        'host inactive' => [true, LifecycleStatus::Failed, ' The Incus host sweep is paused because host [beast] is inactive.'],
        'sweep enabled' => [true, LifecycleStatus::Active, ''],
        'UpCloud with task VMs disabled' => [false, LifecycleStatus::Failed, '', TaskVmProviderKind::UpCloud],
    ]);

    it('retries the preservation push once the missing VM is found again', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        config(['task_vms.enabled' => true]);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $sha = str_repeat('c', 40);
        $reviewed = TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->withArgs(fn (Task $pushed, string $commit): bool => $pushed->id === $group->id && $commit === $sha);
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        $scheduler = app(TaskScheduler::class);
        Queue::fake();
        $this->provider->observation = null;

        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($scheduler->removeAbandonedWorkspaces())->toBe(0);
        $this->assertModelExists($instance);
        $this->provider->observation = new VmObservation(true, '10.251.77.20');
        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($vm->fresh()->error_code)->toBeNull();
        expect($scheduler->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($reviewed->fresh()->pushed_at)->not->toBeNull();
        expect($group->fresh()->assistance_requested)->toBeFalse();
        expect($this->provider->calls)->toBe(['observe', 'observe', 'destroy']);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('explains a paused sweep without resolving providers for an already missing VM', function (bool $enabled, LifecycleStatus $hostStatus, string $pause): void {
        [$vm, , , $group] = tvm_life_stranded_workspace(true);
        config(['task_vms.enabled' => true]);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $sha = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->observation = null;
        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        config(['task_vms.enabled' => $enabled]);
        $this->host->update(['status' => $hostStatus]);
        app()->register(TaskVmServiceProvider::class, true);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
        expect($group->fresh()->assistance_reason)->toContain($sha, $pause);
        expect($this->provider->calls)->toBe(['observe']);
    })->with([
        'task VMs disabled' => [false, LifecycleStatus::Active, 'task VMs are disabled'],
        'host inactive' => [true, LifecycleStatus::Failed, 'host [beast] is inactive'],
    ]);

    it('reports a failed external cleanup without assistance and eventually destroys a failed VM', function (): void {
        Queue::fake();
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false, TaskOrchestration::External);
        $scheduler = app(TaskScheduler::class);
        $lifecycle = app(ProjectLifecycleRunner::class);
        app()->instance(ProjectLifecycleRunner::class, Mockery::mock($lifecycle)->shouldReceive('run')->andThrow(new RuntimeException('Teardown failed.'))->getMock());
        expect($scheduler->removeAbandonedWorkspaces())->toBe(0)
            ->and($group->fresh()->assistance_requested)->toBeFalse()
            ->and($vm->fresh()->state)->toBe(TaskVmState::Ready)
            ->and(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(1);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);

        app()->instance(ProjectLifecycleRunner::class, $lifecycle);
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.vm_missing']);
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed)
            ->and(Instance::query()->find($instance->id))->toBeNull()
            ->and(Node::query()->find($vm->node_id))->toBeNull()
            ->and($group->fresh()->assistance_requested)->toBeFalse();
    });

    it('reports an external handoff branch cleanup failure without raising assistance', function (): void {
        Queue::fake();
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false, TaskOrchestration::External);
        $task = $group->tasks()->create(['title' => 'One', 'brief' => 'One.', 'status' => TaskStatus::Completed]);
        $task->checks()->create(['kind' => 'handoff', 'status' => 'passed', 'pid' => 42, 'process_started' => 'start', 'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'commit_sha' => str_repeat('a', 40), 'started_at' => now()]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('deleteHandoffBranch')->once()->andThrow(new TaskPullRequestException('GitHub unavailable.'));
        app()->instance(TaskPullRequestPublisher::class, $publisher);

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1)
            ->and(Instance::query()->find($instance->id))->toBeNull()
            ->and($group->fresh()->assistance_requested)->toBeFalse()
            ->and($vm->fresh()->state)->toBe(TaskVmState::Destroying);
    });

    it('keeps external assistance unchanged when VM destruction finds unpublished work', function (TaskVmState $state): void {
        Queue::fake();
        [$vm, , , $group] = tvm_life_stranded_workspace(true, TaskOrchestration::External);
        $before = $group->fresh()->assistance_reason;
        $events = Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->count();
        $task = $group->tasks()->create(['title' => 'One', 'brief' => 'One.', 'status' => TaskStatus::Completed]);
        $task->comments()->create([
            'task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer',
            'commit_sha' => str_repeat('c', 40), 'posted_at' => now(),
        ]);
        $vm->update(['state' => $state]);
        $this->provider->observation = null;

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($group->fresh()->assistance_reason)->toBe($before)
            ->and(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->count())->toBe($events)
            ->and($vm->fresh()->state)->toBe($state === TaskVmState::Failed ? TaskVmState::Destroyed : TaskVmState::Ready);
    })->with([TaskVmState::Ready, TaskVmState::Failed]);

    it('destroys a lost VM and records its lost reviewed SHA without clearing the evidence', function (TaskGroupStatus $status): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => $status]);
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.vm_missing']);
        $nodeId = $vm->node_id;
        $sha = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->observation = null;
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelMissing($instance);
        expect(Node::query()->find($nodeId))->toBeNull();
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh()->assistance_requested)->toBeTrue();
        expect($group->fresh()->assistance_reason)->toContain('lost', $sha);
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('description', 'assistance changed')->sole()->properties->get('reason'))->toContain('lost', $sha);
        expect($this->provider->calls)->toBe(['destroy']);
    })->with([TaskGroupStatus::Failed, TaskGroupStatus::Cancelled]);

    it('keeps the original loss reason when deletion fails and workspace removal retries', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(true);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.start_failed', 'error_message' => 'The provider could not start the VM.']);
        $sha = str_repeat('c', 40);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => $sha, 'source' => TaskReviewedCommitSource::OrbitPush]);
        $this->provider->onDestroy = static fn () => throw new RuntimeException('Delete failed.');

        expect(fn () => app()->call([new DestroyTaskVm($vm->id), 'handle']))->toThrow(RuntimeException::class, 'Delete failed.');
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroying);
        $lossReason = $group->fresh()->assistance_reason;
        expect($lossReason)->toContain($sha, 'task_vm.start_failed', 'The provider could not start the VM.');
        expect($vm->fresh()->error_message)->toBe('Delete failed.');
        mock(TaskCheckRunner::class)->shouldNotReceive('snapshot');
        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0);
        $this->provider->onDestroy = null;
        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelMissing($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh()->assistance_requested)->toBeTrue();
        expect($group->fresh()->assistance_reason)->toBe($lossReason);
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('properties->reason', 'like', '%Reviewed work lost%')->count())->toBe(1);
        expect($this->provider->calls)->toBe(['destroy', 'destroy']);
    });

    it('records an ordinary removal push before teardown so a later lost VM needs no push', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->update(['status' => TaskGroupStatus::Failed]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $sha = str_repeat('c', 40);
        $approval = $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => $sha]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldReceive('push')->once()->withArgs(fn (Task $pushed, string $commit): bool => $pushed->id === $group->id && $commit === $sha);
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        $removal = app(RemoveTaskWorkspaceAction::class);
        $lifecycle = app(ProjectLifecycleRunner::class);
        app()->instance(ProjectLifecycleRunner::class, Mockery::mock($lifecycle)->shouldReceive('run')->andThrow(new RuntimeException('Teardown failed.'))->getMock());
        try {
            $removal->remove($group);
            throw new LogicException('Expected teardown failure.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('Teardown failed.');
            $removal->recordFailure($group, $exception);
        }
        app()->instance(ProjectLifecycleRunner::class, $lifecycle);
        $this->assertModelExists($instance);
        expect($task->fresh()->review_handled_comment_id)->toBe($approval->id);
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.vm_missing', 'publish_state' => 'pushed', 'publish_commit' => $sha, 'publish_branch' => 'task-'.$group->id]);
        $this->provider->observation = null;

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelMissing($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh()->assistance_requested)->toBeFalse();
        expect($this->provider->calls)->toBe(['destroy']);
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

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        $this->assertModelMissing($instance);
        expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
        expect($group->fresh()->assistance_requested)->toBeFalse();
        expect(Activity::query()->where('subject_type', Task::class)->where('subject_id', $group->id)->where('properties->reason', 'like', '%Reviewed work lost%')->exists())->toBeFalse();
        if ($reviewAndMerge) {
            expect(TaskReviewedCommit::query()->where('task_id', $group->id)->sole()->pushed_at)->not->toBeNull();
        } else {
            expect($task->fresh()->review_handled_comment_id)->toBe($approval->id);
        }
        expect($this->provider->calls)->toBe(['destroy']);
        Log::shouldHaveReceived('info')->once()->with('Pushed approved work before removing a task workspace.', ['task_group_id' => $group->id, 'commit_sha' => $sha, 'branch' => 'task-'.$group->id]);
    })->with(['per-subtask publication' => false, 'review and merge' => true]);

    it('removes an ended incoming pull request workspace without pushing the author head', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed, 'pr_url' => 'https://github.com/acme/dlf/pull/42', 'pr_branch' => 'authors-change']);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::PullRequestReview]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        Queue::fake();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($this->provider->calls)->toBe(['destroy']);
    });

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
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
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
        Queue::assertPushed(ResumeTaskVm::class, fn (ResumeTaskVm $job): bool => $job->taskVmId === $vm->id);
    })->with(['cancel' => false, 'complete' => true]);

    it('removes a failed workspace without repushing an older head once a newer reviewed head was published', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $group->project->update(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group->update(['status' => TaskGroupStatus::Failed]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('c', 40), 'source' => TaskReviewedCommitSource::OrbitPush]);
        TaskReviewedCommit::query()->create(['task_id' => $group->id, 'sha' => str_repeat('d', 40), 'source' => TaskReviewedCommitSource::OrbitPush, 'pushed_at' => now()]);
        $task = $group->tasks()->create(['position' => 1, 'title' => 'Approved', 'brief' => 'Work', 'status' => TaskStatus::Completed]);
        $task->comments()->create(['task_group_id' => $group->id, 'type' => 'approved', 'body' => 'Reviewed.', 'author' => 'reviewer', 'posted_at' => now(), 'commit_sha' => str_repeat('c', 40)]);
        $publisher = Mockery::mock(TaskPullRequestPublisher::class);
        $publisher->shouldNotReceive('push');
        app()->instance(TaskPullRequestPublisher::class, $publisher);
        Queue::fake();

        expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(1);

        $this->assertModelMissing($instance);
        expect($this->provider->calls)->toBe(['destroy']);
    });

    it('copies the agent transcripts to the Gateway before it deletes the VM, and skips a thread it cannot read', function (TaskOrchestration $mode): void {
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
        $pending = $thread('example', TaskAgentSpawner::PendingPrefix.'not-started');
        // This actor completed under an earlier lease. A later unreadable transcript
        // does not make its proven completion uncertain.
        TaskWorkspaceLeaseRecord::query()->create([
            'instance_id' => $instance->id, 'group_id' => $group->id, 'kind' => 'reviewer',
            'owner' => 'thread:'.$broken->id, 'thread_id' => $broken->id,
            'status' => 'released', 'released_at' => now(),
        ]);
        Queue::fake();

        $removal->remove($group);

        expect($readable->providerCalls)->toBe([[], []])
            ->and($this->provider->calls)->toBe(['destroy'])
            ->and(json_decode((string) Storage::disk('local')->get(ArchiveAgentTranscriptsAction::path($kept)), true))
            ->toMatchArray(['state' => 'done', 'entries' => [['id' => 'm1', 'kind' => 'message', 'label' => 'assistant', 'text' => 'Approved.', 'at' => '2026-10-14T18:00:00Z']]])
            ->and(Storage::disk('local')->exists(ArchiveAgentTranscriptsAction::path($broken)))->toBeFalse()
            ->and(Storage::disk('local')->exists(ArchiveAgentTranscriptsAction::path($pending)))->toBeFalse()
            ->and(Instance::query()->find($instance->id))->toBeNull();
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

    it('keeps the workspace and records the error on the VM when the VM cannot be deleted', function (): void {
        [$vm, $instance, , $group] = tvm_life_stranded_workspace(false);
        $this->provider->onDestroy = static fn () => throw new TaskVmException('task_vm.delete_failed', 'The VM could not be deleted.', 502);
        Queue::fake();

        expect(fn () => app(RemoveTaskWorkspaceAction::class)->remove($group))->toThrow(TaskVmException::class);

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
        $failedGroup = tvm_life_vm(tvm_life_group(TaskGroupStatus::Failed), TaskVmState::Ready, wireguardIp: '10.44.0.134');
        $running = tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, tvm_life_node('tvm-b', '10.44.0.131'));
        $withWorkspace = tvm_life_vm($group = tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Ready, $node = tvm_life_node('tvm-c', '10.44.0.132'));
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'task_workspace_owner_id' => $group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);
        $claimed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Failed), TaskVmState::Provisioning, wireguardIp: '10.44.0.133');
        $claimed->group->update(['reserved_at' => now()]);
        $destroyed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Destroyed);

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(3);

        $queued = Queue::pushed(DestroyTaskVm::class)->map(fn (DestroyTaskVm $job): int => $job->taskVmId)->sort()->values()->all();
        expect($queued)->toBe([$ended->id, $failed->id, $failedGroup->id]);
    });

    it('queues destruction past an Instance on the Node that the ended group does not own', function (): void {
        Queue::fake();
        $vm = tvm_life_vm($group = tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, $node = tvm_life_node('tvm-a', '10.44.0.129'));
        Instance::withoutEvents(fn (): Instance => Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]));

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(1);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('queues destruction for a workspace whose removal failed after its group ended, so the job can check the VM', function (bool $removalFailed, TaskGroupStatus $status): void {
        Queue::fake();
        [$vm, , , $group] = tvm_life_stranded_workspace(false);
        $group->update(['status' => $status, 'pr_url' => $status === TaskGroupStatus::Settling ? 'https://github.com/acme/orbit/pull/42' : null]);
        if ($removalFailed) {
            TaskAssistance::apply($group, AssistanceKind::RemovalFailure, null, RemoveTaskWorkspaceAction::RemovalFailedPrefix.'The Node is unreachable.');
        }
        $queued = $removalFailed && $status !== TaskGroupStatus::Running;

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe($queued ? 1 : 0);
        if ($queued) {
            Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $vm->id);
        }
    })->with([
        'removal failed, cancelled' => [true, TaskGroupStatus::Cancelled],
        'removal failed, failed group' => [true, TaskGroupStatus::Failed],
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

    it('shows a recipe image wait on a new group while a host build fails', function (): void {
        Queue::fake();
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $build = TaskVmRecipeImages::ready($this->host->id);
        $build->update(['state' => 'failed']);
        app(TaskExtensionState::class)->enable();

        expect(app(TaskScheduler::class)->claimNext())->toBeNull();
        expect($group->refresh()->capacity_wait_reason)->toBe('Task VM: waiting for recipe '.substr($build->recipe_identity, 7, 12).' images on beast.');
        expect(TaskVm::query()->count())->toBe(0);
    });

    it('makes a todo group whose task VM failed ask for assistance at once, naming the VM, and gives it no other VM', function (): void {
        Queue::fake();
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $vm = tvm_life_vm($group, TaskVmState::Failed);
        $vm->update(['error_code' => 'task_vm.bootstrap_failed', 'error_message' => 'Cloud-init failed.']);
        app(TaskExtensionState::class)->enable();
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

    it('records assistance once when the failure action asks before the next claim', function (): void {
        Queue::fake();
        $group = tvm_life_group(TaskGroupStatus::Todo);
        $vm = tvm_life_vm($group, TaskVmState::Provisioning);
        app(TaskExtensionState::class)->enable();
        $reason = TaskVmFailedException::reason($vm->name, 'task_vm.bootstrap_failed', 'Cloud-init failed.');

        expect(app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Provisioning], 'task_vm.bootstrap_failed', 'Cloud-init failed.', $reason))->toBeTrue();
        expect(app(FailTaskVmAction::class)->execute($vm, [TaskVmState::Provisioning], 'task_vm.bootstrap_failed', 'Cloud-init failed.', $reason))->toBeFalse();
        app(TaskScheduler::class)->claimNext();
        app(TaskScheduler::class)->claimNext();

        expect($group->fresh())->assistance_requested->toBeTrue()->assistance_reason->toBe($reason);
    });

    it('never reserves a failed VM group and still claims a healthy group', function (): void {
        Queue::fake();
        $failed = tvm_life_group(TaskGroupStatus::Todo);
        $failed->update(['capacity_wait_reason' => 'Task VM: provisioning.']);
        tvm_life_vm($failed, TaskVmState::Failed);
        $healthy = tvm_life_group(TaskGroupStatus::Todo);
        $node = tvm_life_node('healthy', '10.44.0.130', role: RoleName::AppDev);
        tvm_life_vm($healthy, TaskVmState::Ready, $node);
        tvm_life_provision(static fn (InstanceProvisionIntent $intent): Instance => tvm_life_workspace($intent->group, $node));
        app(TaskExtensionState::class)->enable();
        DB::statement("CREATE TRIGGER reject_failed_vm_reservation BEFORE UPDATE OF status ON tasks WHEN NEW.id = {$failed->id} AND NEW.status = 'reserved' BEGIN SELECT RAISE(ABORT, 'failed VM was reserved'); END");

        expect(app(TaskScheduler::class)->claimNext()?->id)->toBe($healthy->id);
        expect(app(TaskScheduler::class)->claimNext())->toBeNull();

        expect($failed->fresh())->status->toBe(TaskGroupStatus::Todo)->reserved_at->toBeNull()
            ->capacity_wait_reason->toBeNull()->assistance_requested->toBeTrue();
    });

    it('clears the Pi token as soon as deletion removes the VM', function (): void {
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Ready);

        app(DeleteTaskVmAction::class)->execute($vm);

        expect($vm->fresh())->state->toBe(TaskVmState::Destroying)->pi_token->toBeNull();
    });
});

it('keeps an active external workspace even after its PR merged and cleanup was requested', function (): void {
    Queue::fake();
    [$vm, $instance, , $group] = tvm_life_stranded_workspace(true, TaskOrchestration::External);
    $group->update(['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/acme/dlf/pull/7', 'watched_pr_completion' => 'merged']);
    expect(app(TaskScheduler::class)->removeAbandonedWorkspaces())->toBe(0)
        ->and(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(0)
        ->and($vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and(Instance::query()->whereKey($instance->id)->exists())->toBeTrue();
});
