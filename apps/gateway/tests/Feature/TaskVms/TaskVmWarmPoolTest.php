<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Actions\TaskVms\ParkIdleTaskVmsAction;
use App\Actions\TaskVms\RefillTaskVmPoolAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmImagePeers;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\EnrollTaskVm;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Jobs\TaskVms\RecoverLegacyWarmClaim;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeIncusFleet;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\RecordingTaskVmProvider;
use Tests\Support\TaskVmSettingsBuilder;

const TVM_POOL_CURRENT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const TVM_POOL_OLD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const TVM_POOL_ORIGIN = 'http://10.44.0.3:8317';

function tvm_pool_node(string $name, string $address, string $user = 'orbit'): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user, 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

/** @param list<array{0: Node, 1: int, 2: int}> $hosts each host Node, max_vms and warm_vms, in config order */
function tvm_pool_hosts(array $hosts, bool $enabled = true): void
{
    TaskVmSettingsBuilder::bind([
        'enabled' => $enabled, 'devClusterId' => null, 'modelProxyOrigin' => TVM_POOL_ORIGIN,
        'hosts' => array_map(static fn (array $host, int $index): TaskVmHost => TaskVmSettingsBuilder::host(
            $host[0]->id, ['cidr' => '10.251.'.(77 + $index).'.0/24', 'maxVms' => $host[1], 'warmVms' => $host[2]],
        ), $hosts, array_keys($hosts)),
    ]);
}

function tvm_pool_group(TaskGroupStatus $status = TaskGroupStatus::Reserved): Task
{
    return Task::topLevel()->create([
        'project_id' => test()->project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => $status, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
}

/** A pool row, with an enrolled Node unless it is still being created. Its instance runs on the host's fake Incus. */
function tvm_pool_vm(TaskVmState $state = TaskVmState::Warm, ?Node $host = null, ?string $image = TVM_POOL_CURRENT, bool $verified = true): TaskVm
{
    $host ??= test()->host;
    $count = TaskVm::query()->count();
    $vm = TaskVm::query()->create([
        'group_id' => null, 'host_node_id' => $host->id, 'provider' => 'incus', 'name' => 'tvm-pending-'.$count,
        'state' => $state, 'wireguard_ip' => '10.44.0.'.(129 + $count), 'warmed_at' => $state === TaskVmState::Warm ? now() : null,
        'image_fingerprint' => $state === TaskVmState::Warm && $verified ? $image : null,
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);
    if ($state !== TaskVmState::Provisioning) {
        $node = tvm_pool_node($vm->name, $vm->wireguard_ip);
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
        $vm->update(['node_id' => $node->id]);
    }
    test()->fleet->host((string) $host->wireguard_ip)->instances[$vm->name] = $image;

    return $vm->refresh();
}

function tvm_pool_refill(): array
{
    return app(RefillTaskVmPoolAction::class)->execute();
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main']);
    $this->host = tvm_pool_node('beast', '10.44.0.7', 'nckrtl');
    $this->provider = new RecordingTaskVmProvider;
    app()->instance(TaskVmProvider::class, $this->provider);
    $this->fleet = new FakeIncusFleet;
    $this->fleet->host('10.44.0.7')->images = [TVM_POOL_CURRENT => ['orbit-task-base-20261011030000', TaskVmHost::BaseImage], TVM_POOL_OLD => ['orbit-task-base-20261010030000']];
    app()->instance(SshExecutor::class, $this->fleet);
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway';
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    tvm_pool_hosts([[$this->host, 3, 1]]);
});

describe('refill', function (): void {
    it('records pool rows up to warm_vms with no group, token or key, and queues their creation', function (): void {
        Queue::fake();
        tvm_pool_hosts([[$this->host, 3, 2]]);

        expect(tvm_pool_refill())->toBe(['created' => 2, 'destroying' => 0]);

        $pool = TaskVm::query()->orderBy('id')->get();
        expect($pool)->toHaveCount(2)
            ->and($pool->pluck('group_id')->all())->toBe([null, null])
            ->and($pool->pluck('pi_token')->all())->toBe([null, null])
            ->and($pool->pluck('model_key')->all())->toBe([null, null])
            ->and($pool->pluck('claimed_at')->all())->toBe([null, null])
            ->and($pool->pluck('state')->all())->toBe([TaskVmState::Provisioning, TaskVmState::Provisioning])
            ->and($pool->pluck('name')->all())->toBe(['tvm-'.$pool[0]->id, 'tvm-'.$pool[1]->id])
            ->and($pool->pluck('wireguard_ip')->all())->toBe(['10.44.0.129', '10.44.0.130']);
        Queue::assertPushed(ProvisionTaskVm::class, 2);

        // Starting pool VMs count: the next run adds none.
        expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 0])
            ->and(TaskVm::query()->count())->toBe(2);
    });

    it('never takes more slots than the host has free, warm VMs included', function (): void {
        Queue::fake();
        tvm_pool_hosts([[$this->host, 2, 2]]);
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        tvm_pool_refill();
        tvm_pool_refill();

        expect(TaskVm::query()->live()->count())->toBe(2)
            ->and(TaskVm::query()->live()->pool()->count())->toBe(1)
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_pool_group()))
            ->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::WarmStarting);
    });

    it('drains the pool while task VMs are disabled, and keeps VMs that are still starting', function (): void {
        Queue::fake();
        $warm = tvm_pool_vm();
        $starting = tvm_pool_vm(TaskVmState::Provisioning);
        tvm_pool_hosts([[$this->host, 3, 2]], enabled: false);

        expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 1])
            ->and($warm->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($starting->fresh()?->state)->toBe(TaskVmState::Provisioning);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $warm->id);
        Queue::assertNotPushed(ProvisionTaskVm::class);

        // Once it is warm, the next run destroys it too.
        $starting->update(['state' => TaskVmState::Warm, 'warmed_at' => now()]);
        tvm_pool_refill();
        expect($starting->fresh()?->state)->toBe(TaskVmState::Destroying);
    });

    it('destroys warm VMs over a lowered warm_vms, oldest first', function (): void {
        Queue::fake();
        $old = tvm_pool_vm();
        $new = tvm_pool_vm();
        tvm_pool_hosts([[$this->host, 3, 1]]);

        tvm_pool_refill();

        expect($old->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($new->fresh()?->state)->toBe(TaskVmState::Warm);
    });

    it('records the image of a new warm VM, and replaces warm VMs from an older image or whose instance is gone', function (): void {
        Queue::fake();
        $fresh = tvm_pool_vm(verified: false);
        $stale = tvm_pool_vm(image: TVM_POOL_OLD);
        $gone = tvm_pool_vm();
        unset($this->fleet->host('10.44.0.7')->instances[$gone->name]);
        tvm_pool_hosts([[$this->host, 4, 3]]);

        tvm_pool_refill();

        expect($fresh->fresh()?->image_fingerprint)->toBe(TVM_POOL_CURRENT)
            ->and($fresh->fresh()?->state)->toBe(TaskVmState::Warm)
            ->and($stale->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($gone->fresh()?->state)->toBe(TaskVmState::Destroying);
        Queue::assertPushed(DestroyTaskVm::class, 2);
        // Two slots are still destroying, so one new VM replaces them for now.
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    });

    it('queues the new VM before the destroy of the one it replaces, within max_vms', function (): void {
        Queue::fake();
        $stale = tvm_pool_vm(image: TVM_POOL_OLD);
        tvm_pool_hosts([[$this->host, 2, 1]]);

        tvm_pool_refill();

        // The destroying row still holds its slot: one VM starts, and the host stays within its 2 VMs.
        $pushed = collect(Queue::pushedJobs())->flatten(1)->map(static fn (array $entry): object => $entry['job'])
            ->filter(static fn (object $job): bool => $job instanceof ProvisionTaskVm || $job instanceof DestroyTaskVm)->values();
        expect($pushed->map(static fn (object $job): string => $job::class)->all())->toBe([ProvisionTaskVm::class, DestroyTaskVm::class])
            ->and($pushed[1]->taskVmId)->toBe($stale->id)
            ->and(TaskVm::query()->live()->where('host_node_id', $this->host->id)->count())->toBe(2);

    });

    it('records no new VM on a host whose only slot a destroying VM still holds', function (): void {
        Queue::fake();
        $stale = tvm_pool_vm(image: TVM_POOL_OLD);
        tvm_pool_hosts([[$this->host, 1, 1]]);

        tvm_pool_refill();

        expect($stale->fresh()?->state)->toBe(TaskVmState::Destroying);
        Queue::assertNotPushed(ProvisionTaskVm::class);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $stale->id);
    });

    it('records no new VM on a host whose only slot a failed pool VM held until the same refill destroys it', function (): void {
        Queue::fake();
        $failed = tvm_pool_vm(TaskVmState::Failed);
        tvm_pool_hosts([[$this->host, 1, 1]]);

        expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 1])
            ->and($failed->fresh()?->state)->toBe(TaskVmState::Destroying);
        Queue::assertNotPushed(ProvisionTaskVm::class);
    });

    it('replaces warm VMs once a new base image is promoted', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        tvm_pool_refill();
        expect($vm->fresh()?->state)->toBe(TaskVmState::Warm);

        $this->fleet->host('10.44.0.7')->retarget(TVM_POOL_OLD);
        tvm_pool_refill();

        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroying);
    });

    it('keeps warm VMs when the host image list cannot be read', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $this->fleet->host('10.44.0.7')->fail = 'image list';

        tvm_pool_refill();

        expect($vm->fresh()?->state)->toBe(TaskVmState::Warm);
    });

    it('refills only active configured hosts and logs pool VMs it cannot drain', function (): void {
        Queue::fake();
        Log::spy();
        $shark = tvm_pool_node('shark', '10.44.0.11', 'nckrtl');
        $stranded = tvm_pool_vm(host: $shark);
        $shark->update(['status' => LifecycleStatus::Failed]);
        tvm_pool_hosts([[$this->host, 3, 0], [$shark, 3, 1]]);

        expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 0])
            ->and($stranded->fresh()?->state)->toBe(TaskVmState::Warm);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'the pool cannot drain it') && $context['task_vm'] === $stranded->name);
    });
});

describe('failure and backoff', function (): void {
    it('fails a pool VM without a group, backs off its host loudly, and doubles the wait', function (): void {
        Queue::fake();
        Log::spy();
        $this->travelTo('2026-10-11 12:00:00');
        tvm_pool_refill();
        $vm = TaskVm::query()->sole();

        (new ProvisionTaskVm($vm->id))->failed(new TaskVmException('task_vm.host_command_failed', '`incus launch` failed.', 502));

        expect($vm->fresh()?->state)->toBe(TaskVmState::Failed)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.host_command_failed');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'A warm task VM failed')
            && $context['failures'] === 1 && $context['retry_at'] === '2026-10-11T12:01:00+00:00');

        // The failed VM is destroyed, and no new one starts during the wait.
        expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 1])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroying);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'waits after a failed warm VM'));
        $vm->update(['state' => TaskVmState::Destroyed]);

        $this->travel(61)->seconds();
        expect(tvm_pool_refill()['created'])->toBe(1);
        $second = TaskVm::query()->live()->sole();
        (new EnrollTaskVm($second->id, now()->getTimestamp()))->failed(new RuntimeException('boom'));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'A warm task VM failed')
            && $context['failures'] === 2 && $context['error_code'] === 'task_vm.job_failed' && $context['retry_at'] === '2026-10-11T12:03:01+00:00');
    });

    it('fails a pool row that lost its job an hour ago, logs it, destroys it, and frees the slot', function (): void {
        Queue::fake();
        Log::spy();
        tvm_pool_hosts([[$this->host, 2, 1]]);
        $stuck = tvm_pool_vm(TaskVmState::Provisioning);
        $stuck->forceFill(['created_at' => now()->subMinutes(RefillTaskVmPoolAction::StartMinutes + 1)])->save();
        $young = tvm_pool_vm(TaskVmState::Provisioning);
        $young->forceFill(['created_at' => now()->subMinutes(RefillTaskVmPoolAction::StartMinutes - 1)])->save();

        tvm_pool_refill();

        expect($stuck->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($stuck->fresh()?->error_code)->toBe(TaskVmError::PoolStartTimeout->value)
            ->and($young->fresh()?->state)->toBe(TaskVmState::Provisioning);
        Queue::assertPushed(DestroyTaskVm::class, fn (DestroyTaskVm $job): bool => $job->taskVmId === $stuck->id);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'A warm task VM failed')
            && $context['task_vm'] === $stuck->name && $context['error_code'] === TaskVmError::PoolStartTimeout->value);

        // Once destroyed, its slot is free again for a pool VM after the wait.
        $stuck->update(['state' => TaskVmState::Destroyed]);
        $young->update(['state' => TaskVmState::Destroyed]);
        $this->travel(61)->seconds();
        expect(tvm_pool_refill()['created'])->toBe(1);
    });

    it('forgets the failures once a pool VM becomes warm', function (): void {
        Queue::fake();
        $failed = tvm_pool_vm(TaskVmState::Provisioning);
        (new ProvisionTaskVm($failed->id))->failed(new RuntimeException('boom'));
        $failed->update(['state' => TaskVmState::Destroyed]);
        $starting = tvm_pool_vm(TaskVmState::Provisioning);
        $node = tvm_pool_node('tvm-node', '10.44.0.200');
        $starting->update(['node_id' => $node->id]);

        app()->call([new PrepareTaskVmRuntime($starting->id), 'handle']);
        tvm_pool_hosts([[$this->host, 3, 2]]);

        expect(tvm_pool_refill()['created'])->toBe(1);
    });
});

describe('warm', function (): void {
    it('makes an enrolled pool VM warm without any group step, and queues the refill', function (): void {
        Queue::fake();
        Log::spy();
        $this->freezeSecond();
        $vm = tvm_pool_vm(TaskVmState::Provisioning);
        $vm->forceFill(['created_at' => now()->subSeconds(30)])->save();
        $vm->update(['node_id' => tvm_pool_node('tvm-node', '10.44.0.200')->id]);

        app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']);

        $vm->refresh();
        expect($vm->state)->toBe(TaskVmState::Warm)
            ->and($vm->warmed_at)->not->toBeNull()
            ->and($vm->pi_token)->toBeNull()
            ->and($vm->model_key)->toBeNull()
            ->and($vm->image_fingerprint)->toBeNull()
            ->and($this->fleet->commands)->toBe([]);
        Queue::assertPushed(RefillTaskVmPool::class);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Task VM step done.'
            && $context['task_vm'] === $vm->name && $context['step'] === 'warm' && $context['seconds'] === 30.0);
    });
});

describe('claim', function (): void {
    it('takes a current warm VM, and queues only the group steps when they fail in the claim', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_pool_vm();
        $group = tvm_pool_group();

        $claimed = app(AllocateTaskVmAction::class)->execute($group);

        expect($claimed->id)->toBe($vm->id)
            ->and($claimed->group_id)->toBe($group->id)
            ->and($claimed->state)->toBe(TaskVmState::Provisioning)
            ->and($claimed->pi_token)->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($claimed->model_key)->toBeNull()
            ->and($claimed->claimed_at)->not->toBeNull()
            ->and($claimed->node_id)->toBe($vm->node_id)
            ->and(TaskVm::query()->count())->toBe(1)
            ->and(app(AllocateTaskVmAction::class)->execute($group)->id)->toBe($vm->id);
        Queue::assertPushedOn('task-vms', PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);
        Queue::assertPushed(RefillTaskVmPool::class);
        Queue::assertNotPushed(ProvisionTaskVm::class);
        Queue::assertNotPushed(EnrollTaskVm::class);
        // Even invalid runtime configuration is evaluated by the worker, not by this claim.
        Log::shouldNotHaveReceived('warning');
    });

    it('waits through the claim until the claimed warm VM is ready', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $group = tvm_pool_group();

        expect(fn () => app(TaskVmWorkspace::class)->node($group))->toThrow(TaskCapacityException::class, 'Task VM: provisioning.');

        $vm->refresh()->update(['state' => TaskVmState::Ready]);
        expect(app(TaskVmWorkspace::class)->node($group)->id)->toBe($vm->node_id);
    });

    it('gives a group with a declared topology a new large VM, never a warm one', function (): void {
        Queue::fake();
        $warm = tvm_pool_vm();
        tvm_pool_hosts([[$this->host, 4, 1]]);
        $group = tvm_pool_group();
        Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Dev', 'brief' => 'Dev', 'status' => TaskStatus::Todo, 'topology' => ['app-dev', 'app-prod']]);

        $vm = app(AllocateTaskVmAction::class)->execute($group);

        expect($vm->id)->not->toBe($warm->id)
            ->and($vm->refresh()->size)->toBe(TaskVmSize::Large)
            ->and($warm->refresh()->group_id)->toBeNull()
            ->and($warm->state)->toBe(TaskVmState::Warm);
        Queue::assertPushed(ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('gives a group of a medium Project a new medium VM, never a warm one', function (): void {
        Queue::fake();
        $warm = tvm_pool_vm();
        $group = tvm_pool_group();
        TaskVmSettingsBuilder::bind([
            'devClusterId' => null, 'modelProxyOrigin' => TVM_POOL_ORIGIN, 'mediumProjectIds' => [$group->project_id],
            'hosts' => [TaskVmSettingsBuilder::host($this->host->id, ['maxVms' => 4, 'warmVms' => 1])],
        ]);

        $vm = app(AllocateTaskVmAction::class)->execute($group);

        expect($vm->id)->not->toBe($warm->id)
            ->and($vm->refresh()->size)->toBe(TaskVmSize::Medium)
            ->and($warm->refresh()->state)->toBe(TaskVmState::Warm);
    });

    it('never gives one warm VM to two groups', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();

        $first = app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        $second = app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        expect($first->id)->toBe($vm->id)
            ->and($second->id)->not->toBe($vm->id)
            ->and($second->warmed_at)->toBeNull();
        Queue::assertPushed(ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $second->id);
    });

    it('ignores an obsolete placement cache lock when claiming a warm VM', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $lock = Cache::lock('task-vms:placement', 30);
        expect($lock->get())->toBeTrue();

        try {
            Sleep::fake(syncWithCarbon: true);
            expect(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->id)->toBe($vm->id);
            Queue::assertPushed(PrepareTaskVmRuntime::class, 1);
        } finally {
            $lock->release();
        }
    });

    it('takes no warm VM from an older image, whose image is not read yet, or that is not warm', function (TaskVmState $state, ?string $image, bool $verified): void {
        Queue::fake();
        $vm = tvm_pool_vm($state, image: $image, verified: $verified);

        $claimed = app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        expect($claimed->id)->not->toBe($vm->id)
            ->and($vm->fresh()?->group_id)->toBeNull();
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    })->with([
        'older image' => [TaskVmState::Warm, TVM_POOL_OLD, true],
        'image not read' => [TaskVmState::Warm, TVM_POOL_CURRENT, false],
        'destroying' => [TaskVmState::Destroying, TVM_POOL_CURRENT, false],
        'failed' => [TaskVmState::Failed, TVM_POOL_CURRENT, false],
    ]);

    it('takes no warm VM from a host whose alias moved after the refill read it', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $this->fleet->host('10.44.0.7')->retarget(TVM_POOL_OLD);

        expect(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->id)->not->toBe($vm->id);
    });

    it('takes no warm VM whose host image list cannot be read, and falls back to a new VM', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $this->fleet->host('10.44.0.7')->fail = 'image list';

        expect(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->id)->not->toBe($vm->id)
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Warm);
    });

    it('prefers a host with a warm VM, even when another host has more free slots', function (): void {
        Queue::fake();
        $shark = tvm_pool_node('shark', '10.44.0.11', 'nckrtl');
        $this->fleet->host('10.44.0.11')->images = [TVM_POOL_CURRENT => [TaskVmHost::BaseImage]];
        tvm_pool_hosts([[$this->host, 4, 0], [$shark, 2, 1]]);
        $vm = tvm_pool_vm(host: $shark);

        expect(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->id)->toBe($vm->id)
            ->and(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->host_node_id)->toBe($this->host->id);
    });

    it('takes a warm VM on a full host', function (): void {
        Queue::fake();
        tvm_pool_hosts([[$this->host, 1, 1]]);
        $vm = tvm_pool_vm();

        expect(app(AllocateTaskVmAction::class)->execute(tvm_pool_group())->id)->toBe($vm->id)
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_pool_group()))->toThrow(TaskCapacityException::class, TaskVmPlacementPolicy::NoCapacity);
    });

    it('reads a host image at most every 10 seconds, and waits a minute after a failed read', function (): void {
        Queue::fake();
        tvm_pool_hosts([[$this->host, 8, 0]]);
        tvm_pool_vm();
        tvm_pool_vm();
        $reads = fn (): int => count(array_filter($this->fleet->argv('10.44.0.7'), static fn (string $command): bool => str_contains($command, 'image list')));

        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        expect($reads())->toBe(1);

        $this->travel(11)->seconds();
        $this->fleet->host('10.44.0.7')->fail = 'image list';
        tvm_pool_vm();
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        expect($reads())->toBe(2);

        $this->travel(61)->seconds();
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());
        expect($reads())->toBe(3);
    });

    it('reads the host image again after a promote, so a claim never takes a VM from the old image', function (): void {
        Queue::fake();
        tvm_pool_vm();
        $second = tvm_pool_vm();
        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        app(IncusTaskVmBaseImage::class)->promote(app(TaskVmSettings::class)->host($this->host->id), $this->host, TVM_POOL_OLD);
        $claimed = app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        expect($claimed->warmed_at)->toBeNull()->and($second->fresh()?->group_id)->toBeNull();
    });

    it('reads no host image without a warm VM', function (): void {
        Queue::fake();

        app(AllocateTaskVmAction::class)->execute(tvm_pool_group());

        expect($this->fleet->commands)->toBe([]);
    });
});

describe('transaction-serialized placement', function (): void {
    beforeEach(function (): void {
        // Independent connections need a file. Copy the real test schema and fixtures, not the open test transaction.
        $this->placementDatabase = tempnam(sys_get_temp_dir(), 'task-vm-placement');
        $target = new PDO('sqlite:'.$this->placementDatabase);
        $target->exec('PRAGMA foreign_keys = OFF');
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $table) {
            $target->exec($table->sql);
            foreach (DB::table($table->name)->get() as $row) {
                $values = (array) $row;
                $columns = implode(',', array_map(static fn (string $column): string => '"'.$column.'"', array_keys($values)));
                $statement = $target->prepare('INSERT INTO "'.$table->name.'" ('.$columns.') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
                $statement->execute(array_values($values));
            }
        }
        $this->originalDatabase = config('database.default');
        config(['database.connections.placement' => [...config('database.connections.sqlite'), 'database' => $this->placementDatabase, 'busy_timeout' => 1000], 'database.default' => 'placement']);
        tvm_pool_hosts([[$this->host, 1, 0]]);
    });

    afterEach(function (): void {
        config(['database.default' => $this->originalDatabase]);
        DB::purge('placement');
        @unlink($this->placementDatabase);
        @unlink($this->placementDatabase.'-wal');
        @unlink($this->placementDatabase.'-shm');
    });

    it('serializes two concurrent placements so only one takes the last slot without a cache placement lock', function (): void {
        Queue::fake();
        $first = tvm_pool_group();
        $second = tvm_pool_group();
        $resultFile = tempnam(sys_get_temp_dir(), 'placement-result');
        // Holding the obsolete lock must no longer affect placement.
        $obsolete = Cache::lock('task-vms:placement', 30);
        $obsolete->get();
        DB::beginTransaction();
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge('placement');
            try {
                app(AllocateTaskVmAction::class)->execute($second);
                file_put_contents($resultFile, 'overfilled');
            } catch (TaskCapacityException $exception) {
                file_put_contents($resultFile, $exception->getMessage());
            } catch (Throwable $exception) {
                file_put_contents($resultFile, $exception::class.': '.$exception->getMessage());
            }
            exit(0);
        }
        try {
            expect($pid)->toBeGreaterThan(0);
            usleep(100_000);
            expect(file_get_contents($resultFile))->toBe('');
            app(AllocateTaskVmAction::class)->execute($first);
            DB::commit();
            pcntl_waitpid($pid, $status);
            expect(file_get_contents($resultFile))->toBe(TaskVmPlacementPolicy::NoCapacity)
                ->and(TaskVm::query()->count())->toBe(1);
            Queue::assertPushed(ProvisionTaskVm::class, 1);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            pcntl_waitpid($pid, $status);
            $obsolete->release();
            @unlink($resultFile);
        }
    });

    it('skips a refill while the SQLite writer is busy and retries on the next run', function (): void {
        Queue::fake();
        Log::spy();
        tvm_pool_hosts([[$this->host, 1, 1]]);
        config(['database.connections.placement.busy_timeout' => 1]);
        DB::purge('placement');
        // Initialize WAL before the competing writer starts, as on the deployed database.
        DB::connection()->getPdo();
        $writer = new PDO('sqlite:'.$this->placementDatabase);
        $writer->exec('BEGIN IMMEDIATE');
        try {
            expect(tvm_pool_refill())->toBe(['created' => 0, 'destroying' => 0]);
        } finally {
            $writer->exec('ROLLBACK');
        }
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->with('The task VM pool refill waits for another placement; the next run tries again.');
        expect(tvm_pool_refill())->toBe(['created' => 1, 'destroying' => 0]);
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    });

    it('maps an exhausted SQLite writer wait to capacity and lets a later claim succeed', function (): void {
        Queue::fake();
        $group = tvm_pool_group();
        config(['database.connections.placement.busy_timeout' => 1]);
        DB::purge('placement');
        $writer = new PDO('sqlite:'.$this->placementDatabase);
        $writer->exec('BEGIN IMMEDIATE');
        try {
            app(AllocateTaskVmAction::class)->execute($group);
            test()->fail('A busy writer must cause a capacity wait.');
        } catch (TaskCapacityException $exception) {
            expect($exception->wait)->toBe(TaskWait::Capacity);
        } finally {
            $writer->exec('ROLLBACK');
        }
        Queue::assertNothingPushed();
        expect(TaskVm::query()->count())->toBe(0);
        app(AllocateTaskVmAction::class)->execute($group);
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    });
});

describe('a claimed warm VM', function (): void {
    beforeEach(function (): void {
        $this->transport = new AppDevFakeSshExecutor;
        // The host answers Incus from the fake fleet, and the VM records its Pi commands.
        // The VM has the pinned Pi, as a VM from a current base image does, unless a test sets `pi` to ''.
        $this->ssh = new class($this->fleet, $this->transport) implements SshExecutor
        {
            public string $pi = 'current';

            /** @var list<float> the time each VM command could take under the running command deadline */
            public array $caps = [];

            public function __construct(private FakeIncusFleet $fleet, private AppDevFakeSshExecutor $vm) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if ($connection->host === '10.44.0.7') {
                    return $this->fleet->execute($connection, $command);
                }
                $this->caps[] = app(CommandDeadline::class)->cap($command->timeout ?? $connection->commandTimeout);
                $result = $this->vm->execute($connection, $command);

                return str_contains(implode(' ', $command->arguments), 'printf current') ? new CommandResult(0, $this->pi, '', 1, false) : $result;
            }
        };
        app()->instance(SshExecutor::class, $this->ssh);
        app()->instance(ProcessRuntimeManager::class, new ProcessesApiFakeRuntimeManager);
        app(ProxyCliState::class)->enable(1, 'cache', TVM_POOL_ORIGIN, 'management-key', 'read-token', 'control-token');
        $this->artifact = tempnam(sys_get_temp_dir(), 'pi-artifact');
        file_put_contents($this->artifact, 'pi-server executable');
        TaskVmSettingsBuilder::bind([
            'devClusterId' => null, 'modelProxyOrigin' => TVM_POOL_ORIGIN,
            'piArtifactPath' => $this->artifact, 'piArtifactSha256' => hash_file('sha256', $this->artifact), 'piModels' => ['gpt-5.6-luna'],
            'hosts' => [TaskVmSettingsBuilder::host($this->host->id, ['maxVms' => 3, 'warmVms' => 1])],
        ]);
        $keys = new ArrayObject;
        // CLIProxyAPI keeps its key list in memory and answers a key it holds, as its management API does.
        Http::fake([TVM_POOL_ORIGIN.'/*' => function (Request $request) use ($keys) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v0/management/api-keys' && $request->method() === 'GET') {
                return Http::response(['api-keys' => array_values($keys->getArrayCopy())]);
            }
            if ($path === '/v0/management/api-keys') {
                if ($request['new'] !== '') {
                    $keys->append($request['new']);
                }

                return Http::response(['status' => 'ok']);
            }

            return Http::response([], in_array(substr($request->header('Authorization')[0] ?? '', 7), $keys->getArrayCopy(), true) ? 200 : 401);
        }]);
    });

    afterEach(function (): void {
        @unlink($this->artifact);
    });

    it('dispatches exactly one preparation job without runtime SSH and becomes ready only on the worker', function (): void {
        Queue::fake();
        Log::spy();
        // Time stands still from the claim, and the VM warmed an hour before it, so each step logs 0 seconds
        // only when it counts from the claim.
        $this->freezeSecond();
        $vm = tvm_pool_vm();
        $this->travel(1)->hours();
        $group = tvm_pool_group();

        expect(fn () => app(TaskVmWorkspace::class)->node($group))->toThrow(TaskCapacityException::class, 'Task VM: provisioning.');
        // A later tick sees the committed claim but cannot duplicate its queued job.
        app(AllocateTaskVmAction::class)->execute($group);
        expect($this->transport->commands)->toBe([]);
        Http::assertNothingSent();
        Queue::assertPushed(PrepareTaskVmRuntime::class, 1);
        Queue::assertPushed(PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id && $job->queue === 'task-vms');
        Queue::assertPushed(RefillTaskVmPool::class);

        app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']);
        $node = app(TaskVmWorkspace::class)->node($group);
        $vm->refresh();
        expect($node->id)->toBe($vm->node_id)
            ->and($vm->state)->toBe(TaskVmState::Ready)
            ->and($vm->group_id)->toBe($group->id)
            ->and($vm->model_key)->toMatch('/\A[a-f0-9]{64}\z/')
            ->and($vm->model_proxy_origin)->toBe(TVM_POOL_ORIGIN);
        $steps = [];
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use (&$steps, $vm): bool {
            if ($message === 'Task VM step done.' && $context['task_vm'] === $vm->name) {
                $steps[$context['step']] = $context['seconds'];
            }

            return true;
        });
        expect(array_keys($steps))->toBe(['queue', 'model-key', 'pi-current', 'pi-files', 'pi-server', 'warm-claim-to-ready'])
            ->and(array_map(static fn (RemoteCommand $command): ?float => $command->timeout, $this->transport->commands))->each->toBeLessThanOrEqual(60.0)
            ->and($steps)->each->toBe(0.0)
            ->and($this->transport->connections)->each(fn ($connection) => $connection->host->toBe('10.44.0.129'))
            ->and(app(PiConnection::class)->token($vm->node))->toBe($vm->pi_token);
        // Replaying an in-flight preparation after deploy leaves the ready row alone.
        app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('recovers a claim committed before enqueue and releases a unique lock when dispatch fails', function (): void {
        $vm = tvm_pool_vm();
        $group = tvm_pool_group();
        $vm->update(['group_id' => $group->id, 'state' => TaskVmState::Provisioning, 'claimed_at' => now()]);
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('queue unavailable'));

        expect(fn () => app(AllocateTaskVmAction::class)->execute($group))->toThrow(RuntimeException::class, 'queue unavailable');
        $lock = Cache::lock(UniqueLock::getKey(new PrepareTaskVmRuntime($vm->id)));
        expect($lock->get())->toBeTrue();
        $lock->release();
        Queue::fake();
        app(AllocateTaskVmAction::class)->execute($group);
        app(AllocateTaskVmAction::class)->execute($group);

        Queue::assertPushed(PrepareTaskVmRuntime::class, 1);
        expect($this->transport->commands)->toBe([]);
    });

    it('preserves a replacement lock when a confirmed pre-submit failure cleans up its own owner', function (): void {
        $vm = tvm_pool_vm();
        $key = UniqueLock::getKey(new PrepareTaskVmRuntime($vm->id));
        $replacement = Cache::lock($key, 3600);
        Queue::shouldReceive('connection')->once()->andReturnUsing(function () use ($key, $replacement): never {
            // The original lease expires while connection resolution is failing.
            Cache::lock($key)->forceRelease();
            expect($replacement->get())->toBeTrue();
            throw new RuntimeException('connection rejected');
        });

        expect(fn () => PrepareTaskVmRuntime::enqueue($vm->id))->toThrow(RuntimeException::class, 'connection rejected')
            ->and(Cache::lock($key)->get())->toBeFalse();
        expect($replacement->release())->toBeTrue();
    });

    it('keeps accepted preparation unique after an ambiguous submission exception until the worker finishes', function (string $fault, bool $failWorker): void {
        $vm = tvm_pool_vm();
        $group = tvm_pool_group();
        $queue = new class(Mockery::mock(RedisFactory::class)) extends RedisQueue
        {
            /** @var list<string> */
            public array $accepted = [];

            public bool $loseReply = false;

            public function pushRaw($payload, $queue = null, array $options = [])
            {
                $this->accepted[] = $payload;
                if ($this->loseReply && count($this->accepted) === 1) {
                    throw new RuntimeException('accepted but reply lost');
                }

                return 'accepted-id';
            }
        };
        $queue->setContainer(app());
        $queue->setConnectionName('gateway');
        $queue->loseReply = $fault === 'lost reply';
        Queue::shouldReceive('connection')->andReturn($queue);
        if ($fault === 'post-push event') {
            Event::listen(JobQueued::class, function (JobQueued $event) use ($queue): void {
                if ($event->job instanceof PrepareTaskVmRuntime && count($queue->accepted) === 1) {
                    throw new RuntimeException('post-push listener failed');
                }
            });
        }

        $message = $queue->loseReply ? 'accepted but reply lost' : 'post-push listener failed';
        expect(fn () => app(AllocateTaskVmAction::class)->execute($group))->toThrow(RuntimeException::class, $message);
        app(AllocateTaskVmAction::class)->execute($group);
        app(AllocateTaskVmAction::class)->execute($group);
        expect($queue->accepted)->toHaveCount(1)
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Provisioning)
            ->and($this->transport->commands)->toBe([]);
        $payload = json_decode($queue->accepted[0], true, flags: JSON_THROW_ON_ERROR);
        $accepted = unserialize($payload['data']['command']);
        expect($accepted->uniqueLockOwner)->not->toBe('')
            ->and(Cache::lock(UniqueLock::getKey($accepted))->get())->toBeFalse();

        // Use Laravel's real serialized-command handler, including its owner-aware unlock.
        $handler = app(CallQueuedHandler::class);
        if ($failWorker) {
            $handler->failed($payload['data'], new RuntimeException('preparation exhausted'), $payload['uuid']);
            expect($vm->fresh()?->state)->toBe(TaskVmState::Failed);
        } else {
            $workerJob = Mockery::mock(Job::class);
            $workerJob->shouldReceive('isReleased', 'hasFailed', 'isDeletedOrReleased')->andReturnFalse();
            $workerJob->shouldReceive('delete')->once();
            $handler->call($workerJob, $payload['data']);
            expect($vm->fresh()?->state)->toBe(TaskVmState::Ready);
        }
        $released = Cache::lock(UniqueLock::getKey($accepted));
        expect($released->get())->toBeTrue();
        $released->release();
    })->with([
        'lost response, completion' => ['lost reply', false],
        'lost response, exhausted preparation' => ['lost reply', true],
        'post-push event, completion' => ['post-push event', false],
        'post-push event, exhausted preparation' => ['post-push event', true],
    ]);

    it('leaves the Pi upload to the queued job, which makes the VM ready', function (): void {
        Queue::fake();
        Log::spy();
        $this->ssh->pi = '';
        $vm = tvm_pool_vm();

        expect(fn () => app(TaskVmWorkspace::class)->node(tvm_pool_group()))->toThrow(TaskCapacityException::class, 'Task VM: provisioning.')
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Provisioning)
            ->and($this->transport->commands)->toBe([]);
        Queue::assertPushed(PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);

        app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']);

        expect($vm->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and(collect($this->transport->commands)->contains(fn (RemoteCommand $command): bool => $command->protectedInput !== null && $command->timeout === 600.0))->toBeTrue();
    });
});

describe('a warm VM', function (): void {
    it('holds no Pi token, never falls back to the Gateway token, and serves no Instance', function (): void {
        config(['orbit.pi.token' => 'gateway-wide-token']);
        $vm = tvm_pool_vm();
        $node = $vm->node;
        assert($node instanceof Node);

        expect(fn () => app(PiConnection::class)->token($node))->toThrow(AgentDriverException::class, 'no task group claimed it yet')
            ->and(app(PiConnection::class)->secrets($node))->toBe([])
            ->and(fn () => Instance::query()->create([
                'project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-1', 'source_layout' => 'checkout',
                'checkout_path' => '/home/orbit/apps/shop/task-1', 'root' => 'public', 'branch' => 'task-1',
            ]))->toThrow(TaskVmException::class, 'is a warm task VM')
            ->and(TaskVm::forNode($node)?->id)->toBe($vm->id);
    });

    it('is not parked, woken, or destroyed with an ended group', function (): void {
        Queue::fake();
        $vm = tvm_pool_vm();
        $vm->forceFill(['updated_at' => now()->subDays(2)])->save();
        $node = $vm->node;
        assert($node instanceof Node);
        $instance = new Instance(['node_id' => $node->id]);

        expect(app(ParkIdleTaskVmsAction::class)->execute())->toBe(0)
            ->and(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(0)
            ->and(app(WakeTaskVmAction::class)->forInstance($instance))->toBeTrue()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Warm);
        Queue::assertNothingPushed();
    });

    it('is neither orphaned nor kept when it is gone, and the sweep fails it with no group to ask', function (): void {
        $vm = tvm_pool_vm();
        $this->fleet->host('10.44.0.7')->instances['tvm-999'] = null;

        $found = app(TaskVmHostAudit::class)->audit(app(TaskVmSettings::class)->host($this->host->id), $this->host);
        expect($found['orphaned'])->toBe(['tvm-999'])->and($found['missing'])->toBe([]);

        unset($this->fleet->host('10.44.0.7')->instances[$vm->name]);
        app()->call([new SweepTaskVmHosts, 'handle']);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Warm);
        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($vm->fresh()?->state)->toBe(TaskVmState::Failed)
            ->and($vm->fresh()?->error_code)->toBe(TaskVmError::VmMissing->value)
            ->and(Task::query()->count())->toBe(0);
    });

    it('is reported missing by Doctor on its host', function (): void {
        $vm = tvm_pool_vm();
        unset($this->fleet->host('10.44.0.7')->instances[$vm->name]);
        $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($this->host, new NodeInspectionData(true, 'linux', 'x86_64', true)));

        expect(array_values(array_map(
            static fn ($issue): array => [$issue->code, (string) $issue->observed],
            array_filter($report->issues, static fn ($issue): bool => str_starts_with($issue->code, 'node.task_vm_')),
        )))->toBe([['node.task_vm_missing', 'absent']]);
    });

    it('is destroyed like any task VM: the VM, then the row', function (): void {
        $vm = tvm_pool_vm(TaskVmState::Provisioning);
        $vm->update(['state' => TaskVmState::Destroying]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->vmCalls)->toBe(["destroy {$vm->name}"])
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed);
    });

    it('keeps its host from retiring until it is destroyed', function (): void {
        tvm_pool_vm();
        tvm_pool_hosts([]);

        expect(fn () => app(TaskVmImagePeers::class)->retire($this->host->id))->toThrow(TaskVmException::class, 'warm ones included');
    });
});

describe('schedule', function (): void {
    it('queues the refill every minute only while a host has warm_vms or a pool VM exists', function (): void {
        config(['task_vms.enabled' => true, 'task_vms.incus.hosts' => [['node_id' => 1, 'cidr' => '10.251.77.0/24', 'max_vms' => 2]]]);
        Artisan::call('schedule:list');
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event instanceof CallbackEvent && $event->description === RefillTaskVmPool::class);

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('* * * * *')
            ->and(RefillTaskVmPool::due())->toBeFalse();

        config(['task_vms.incus.hosts' => [['node_id' => 1, 'cidr' => '10.251.77.0/24', 'max_vms' => 2, 'warm_vms' => 1]]]);
        expect(RefillTaskVmPool::due())->toBeTrue();

        config(['task_vms.enabled' => false]);
        expect(RefillTaskVmPool::due())->toBeFalse();
        tvm_pool_vm();
        expect(RefillTaskVmPool::due())->toBeTrue();
    });
});

describe('legacy warm claim payloads', function (): void {
    it('queues the group steps loudly when a warm claim is still provisioning, and leaves other rows alone', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_pool_vm();
        $group = tvm_pool_group();
        // Serialized using the previous release's class name and public row id.
        TaskVm::query()->whereKey($vm->id)->update(['group_id' => $group->id, 'state' => TaskVmState::Provisioning, 'claimed_at' => now()->subMinutes(6)]);
        $ready = tvm_pool_vm();
        $ready->update(['group_id' => tvm_pool_group()->id, 'state' => TaskVmState::Ready]);
        $warming = tvm_pool_vm(TaskVmState::Provisioning);

        foreach ([$vm, $ready, $warming] as $row) {
            // Captured by serializing the job class at the group's base commit.
            $payload = str_replace('s:8:"taskVmId";i:1;', 's:8:"taskVmId";i:'.$row->id.';', file_get_contents(base_path('tests/Fixtures/TaskVms/legacy-warm-claim.txt')));
            $job = unserialize($payload);
            expect($job)->toBeInstanceOf(RecoverLegacyWarmClaim::class);
            app()->call([$job, 'handle']);
        }

        Queue::assertPushed(PrepareTaskVmRuntime::class, 1);
        Queue::assertPushed(PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Provisioning);
    });

    it('does not duplicate preparation already queued or running', function (): void {
        Queue::fake();
        Log::spy();
        $vm = tvm_pool_vm();
        TaskVm::query()->whereKey($vm->id)->update(['group_id' => tvm_pool_group()->id, 'state' => TaskVmState::Provisioning, 'claimed_at' => now()->subMinutes(6)]);
        // The ordinary preparation job holds its unique lock until it ends.
        PrepareTaskVmRuntime::dispatch($vm->id);

        app()->call([new RecoverLegacyWarmClaim($vm->id), 'handle']);

        Queue::assertPushed(PrepareTaskVmRuntime::class, 1);
        Log::shouldNotHaveReceived('error');
        // Once the old preparation has ended, a retry can enqueue another idempotent preparation.
        Cache::lock(UniqueLock::getKey(new PrepareTaskVmRuntime($vm->id)))->forceRelease();
        app()->call([new RecoverLegacyWarmClaim($vm->id), 'handle']);

        Queue::assertPushed(PrepareTaskVmRuntime::class, 2);
    });
});
