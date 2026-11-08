<?php

declare(strict_types=1);

use App\Data\Tasks\StartTaskCheckData;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckIntent;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskParkIntent;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskVmAwake;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** A group with its task VM and workspace. The VM has no Pi credential, so a resume is refused before any run starts. */
function awakeWorld(TaskVmState $state): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-598', '10.44.0.137');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running, 'implementer_model' => 'gpt-6.1-sol', 'reviewer_model' => 'gpt-6-astra']);
    $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-598',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.137', 'size' => TaskVmSize::Normal, 'pi_token' => null]);
    $vm->update(['name' => 'shark-tvm-2', 'slot_number' => 2]);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id, 'status' => InstanceState::Reserved, 'first_setup_pending' => true]);

    return [$group, $vm];
}

it('lets work go on while the task VM runs', function (): void {
    [$group] = awakeWorld(TaskVmState::Ready);

    TaskVmAwake::require($group);

    expect(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

/** A parkable slot VM with the runtime configuration needed by resume admission. */
function awakeProviderWorld(TaskVmState $state): array
{
    [$group, $vm] = awakeWorld($state);
    SwampNodeFixture::ensure();
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25', [new TaskVmHost($vm->host_node_id, 'orbit-tasks', 'orbittask0', '10.251.78.0/24', 5, 4, '8GiB', '40GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    $vm->update(['pi_token' => str_repeat('p', 64)]);
    $instance = Instance::query()->where('task_workspace_owner_id', $group->id)->sole();
    $instance->update(['status' => InstanceState::SourceResolved]);
    $group->update(['taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id,
        'pr_url' => 'https://github.com/hardimpactdev/orbit/pull/3223', 'watched_pr_state' => 'open']);

    return [$group, $vm];
}

it('admits park and resume for a slot VM, including its original host name after a rename', function (TaskVmState $state): void {
    [$group, $vm] = awakeProviderWorld($state);
    Node::withoutEvents(fn () => Node::query()->findOrFail($vm->host_node_id)->update(['name' => 'renamed-host']));

    $park = app(TaskParkIntent::class)->capture($group);
    $resume = app(TaskVmResumeIntent::class)->capture($vm);

    expect($park['task_vm_id'])->toBe($vm->id)
        ->and($park['provider_input']['vm']['name'])->toBe('shark-tvm-2')
        ->and($resume['task_vm_id'])->toBe($vm->id)
        ->and($resume['provider_input']['vm']['name'])->toBe('shark-tvm-2')
        ->and($resume['mode'])->toBe($state === TaskVmState::Parked ? 'resume' : 'converge');
})->with([TaskVmState::Parked, TaskVmState::Ready]);

it('admits park and resume for an old-pool VM without a slot number', function (): void {
    [$group, $vm] = awakeProviderWorld(TaskVmState::Parked);
    $vm->update(['slot_number' => null, 'name' => 'tvm-'.$vm->id]);

    expect(app(TaskParkIntent::class)->capture($group)['provider_input']['name'])->toBe($vm->name)
        ->and(app(TaskVmResumeIntent::class)->capture($vm)['provider_input']['name'])->toBe($vm->name);
});

it('refuses park and resume with 409 for any name outside the task VM allow-list', function (?int $slot, string $name): void {
    [$group, $vm] = awakeProviderWorld(TaskVmState::Parked);
    $vm->update(['slot_number' => $slot, 'name' => $name]);
    $refused = fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('task_vm.provider_identity_invalid')
        ->and($exception->status)->toBe(409);

    expect(fn () => app(TaskParkIntent::class)->capture($group))->toThrow($refused);
    expect(fn () => app(TaskVmResumeIntent::class)->capture($vm))->toThrow($refused);
})->with([
    'base VM' => [2, 'shark-base'],
    'seed VM' => [2, 'shark-tvm-2-seed'],
    'wrong slot' => [2, 'shark-tvm-3'],
    'old name on a slot' => [2, 'tvm-123'],
    'slot name without a slot' => [null, 'shark-tvm-2'],
    'invalid host shape' => [2, '-shark-tvm-2'],
    'unrelated old pool name' => [null, 'shark-base'],
]);

it('refuses the call, with the reason, when the resume of a parked VM cannot be admitted', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);

    expect(fn () => TaskVmAwake::require($group))->toThrow(ResourceOperationException::class, 'Resume requires the parked VM')
        ->and(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('leaves an ended group and a group that another operation holds to their own refusals', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);
    $group->update(['status' => TaskGroupStatus::Cancelled]);

    TaskVmAwake::require($group);

    $group->update(['status' => TaskGroupStatus::Running]);
    Operation::query()->create(['kind' => 'tasks.prep_fix', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => 1, 'status' => OperationStatus::Running, 'input' => []]);
    TaskVmAwake::require($group);

    expect(Operation::query()->where('kind', 'task_vm.resume')->count())->toBe(0);
});

it('refuses an admission of a check on a parked VM before it creates anything', function (): void {
    [$group] = awakeWorld(TaskVmState::Parked);
    $subtask = Task::query()->create(['parent_id' => $group->id, 'title' => 'Subtask', 'brief' => 'Brief', 'status' => TaskStatus::Todo, 'position' => 1]);

    $start = fn () => app(TaskCheckIntent::class)->start($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), 'test', null);

    expect($start)->toThrow(ResourceOperationException::class, 'Resume requires the parked VM')
        ->and(Operation::query()->where('kind', 'tasks.check')->count())->toBe(0)
        ->and($subtask->checks()->count())->toBe(0);
});

it('suspends the start identity of the request and puts it back, also when the callback throws', function (): void {
    $idempotency = app(OperationIdempotency::class);

    $idempotency->under('tasks.turn', 'turn-1', 'fingerprint', function () use ($idempotency): void {
        expect($idempotency->suspended(fn (): array => [$idempotency->kind, $idempotency->key, $idempotency->fingerprint]))->toBe([null, null, null]);
        expect(fn () => $idempotency->suspended(static fn () => throw new RuntimeException('stop')))->toThrow(RuntimeException::class);
        expect([$idempotency->kind, $idempotency->key, $idempotency->fingerprint])->toBe(['tasks.turn', 'turn-1', 'fingerprint']);
    });
});

it('admits the resume of a parked VM under a keyed request without the key of the request', function (): void {
    [$group, $vm] = awakeWorld(TaskVmState::Parked);
    $vm->update(['pi_token' => str_repeat('p', 64)]);
    $host = Node::query()->findOrFail($vm->host_node_id);
    $host->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    Node::withoutEvents(fn () => $host->update(['settings' => ['task_vms' => ['cidr' => '10.251.78.0/24', 'max_vms' => 4]]]));
    config([
        'task_vms.enabled' => true, 'task_vms.dev_cluster_id' => 1,
        'task_vms.pi.artifact_path' => '/srv/pi.tar', 'task_vms.pi.artifact_sha256' => str_repeat('a', 64),
        'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' fixture',
    ]);

    // With the key of the turn still bound, the store refused the resume with a LogicException (a 500). Now the resume reaches the
    // start of its native run, which this test has no endpoint for: that refusal is the proof that the key check passed.
    $call = fn () => app(OperationIdempotency::class)->under('tasks.turn', 'turn-1', 'fingerprint', fn () => TaskVmAwake::require($group));

    expect($call)->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('swamp.start_unavailable'));
});
