<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotIdentity;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\Swamp\SwampWorkerKnownHostsInput;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A task VM host of `$maxVms` slots, with a base snapshot unless `$snapshot` is false. */
function slotPoolHost(int $maxVms = 6, bool $snapshot = true): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['proof.project' => $project->id]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $maxVms, 4, '8GiB', '20GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    $base = $snapshot ? ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107120000']
        : ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64)];
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => $base]]);

    return [$host, $project, $build];
}

/** A todo task group of the Project. */
function slotPoolGroup(Project $project): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
}

/** The Gateway, Swamp and VPN Node a release intent names, and a Swamp dispatch that starts nothing. */
function slotPoolSweepWorld(): void
{
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:gateway', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-public-key', 'wireguard_public_key' => str_repeat('g', 43).'=']));
    foreach ([RoleName::Gateway, RoleName::Swamp, RoleName::Vpn] as $role) {
        $gateway->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
}

it('takes the lowest free slot number and mints a fresh identity, its Node active with the public keys pinned', function (): void {
    [$host, $project] = slotPoolHost();
    $pool = app(TaskVmSlotPool::class);

    $first = $pool->take(slotPoolGroup($project));
    $second = $pool->take(slotPoolGroup($project));

    expect($first->name)->toBe('beast-tvm-1')->and($first->slot_number)->toBe(1)->and($first->state)->toBe(TaskVmState::Provisioning)
        ->and($second->name)->toBe('beast-tvm-2')->and($second->wireguard_ip)->not->toBe($first->wireguard_ip);
    $node = $first->node;
    expect($node)->toBeInstanceOf(Node::class)->and($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->agent_secret_hash)->toBe(hash('sha256', (string) $first->agent_secret))
        ->and($node->wireguard_public_key)->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $first->wireguard_private_key))
        ->and($node->ssh_host_fingerprint)->toBe(TaskVmSlotIdentity::sshFingerprint((string) $first->ssh_host_private_key))
        ->and($node->roles()->where('role', RoleName::AppDev)->sole()->status)->toBe(LifecycleStatus::Active)
        // The run of a task VM carries its pin; the pin admits no publication of the workers' known hosts.
        ->and(Operation::query()->where('kind', 'node.worker_known_hosts')->exists())->toBeFalse();

    // A released slot's number is free again; the new identity shares nothing with the old one.
    $old = [$first->wireguard_private_key, $first->agent_secret];
    app(TaskVmSlotReleaseProjection::class)->record($first, true, null, true);
    $third = $pool->take(slotPoolGroup($project));
    expect($third->slot_number)->toBe(1)->and($third->id)->not->toBe($first->id)
        ->and([$third->wireguard_private_key, $third->agent_secret])->not->toBe($old);
});

it('refuses when no host has room, and leaves the asked number of slots free, as a report-only proof asks', function (): void {
    [, $project] = slotPoolHost(maxVms: 6);
    $pool = app(TaskVmSlotPool::class);

    expect(fn () => $pool->take(slotPoolGroup($project), keepFree: 1))->not->toThrow(TaskCapacityException::class)
        ->and(fn () => $pool->take(slotPoolGroup($project), keepFree: 1))->toThrow(TaskCapacityException::class);
    $pool->take(slotPoolGroup($project));
    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});

it('starts a group only from a base snapshot, while a refresh takes a slot on its own host before the host has one', function (): void {
    [$host, $project] = slotPoolHost(snapshot: false);
    $pool = app(TaskVmSlotPool::class);

    expect(fn () => $pool->take(slotPoolGroup($project)))->toThrow(TaskCapacityException::class)
        ->and($pool->take(null, $host->id)->group_id)->toBeNull();
});

it('deletes the Node, its workspace Instance and the keys of a released slot', function (): void {
    [, $project] = slotPoolHost();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $node = $slot->node;
    $slot->update(['state' => TaskVmState::Ready, 'model_key' => str_repeat('c', 64)]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null, true);

    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed)->and($slot->group_id)->toBeNull()->and($slot->node_id)->toBeNull()
        ->and($slot->model_key)->toBeNull()->and($slot->wireguard_private_key)->toBeNull()->and($slot->agent_secret)->toBeNull()
        ->and(Node::query()->whereKey($node->id)->exists())->toBeFalse()->and(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('mints keys whose public halves match what the real tools derive', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'slot_number' => 1, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'beast-tvm-1', 'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.201']);
    $keys = app(TaskVmSlotIdentity::class)->ensure($slot);

    expect(TaskVmSlotIdentity::sshPublicKey((string) $keys->ssh_host_private_key))->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and(TaskVmSlotIdentity::sshFingerprint((string) $keys->ssh_host_private_key))->toStartWith('SHA256:')
        ->and(strlen((string) base64_decode(TaskVmSlotIdentity::wireguardPublicKey((string) $keys->wireguard_private_key))))->toBe(32);
});

it('submits a release without secrets with an empty secret map, which the workflow takes as an object', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Queued, 'input' => ['intent' => ['host' => [], 'slot' => []], 'execution_secrets' => []], 'triggered_by' => 'test']);

    expect(json_encode(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets']))->toBe('{}');
});

it('fails a slot whose release start was refused, keeping the model key to revoke', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    $slot->update(['state' => TaskVmState::Releasing, 'group_id' => null, 'model_key' => str_repeat('c', 64)]);
    $operation = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused', 'input' => [], 'triggered_by' => 'test']);

    app(TaskVmSlotReleaseProjection::class)->refused($operation);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('swamp.start_refused')
        ->and($slot->model_key)->toBe(str_repeat('c', 64))->and($slot->node)->toBeInstanceOf(Node::class);
});

it('has the sweep release each slot that no group holds and no Operation works on, and leaves the others', function (): void {
    [$host, $project] = slotPoolHost(maxVms: 12);
    $pool = app(TaskVmSlotPool::class);
    $stale = $pool->take(slotPoolGroup($project));
    $held = $pool->take(slotPoolGroup($project));
    $busy = $pool->take(slotPoolGroup($project));
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    $busy->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $busy->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    slotPoolSweepWorld();

    app()->call([new SweepTaskVmHosts, 'handle']);

    $releases = Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->where('triggered_by', 'task-vm-sweep')->get();
    expect($releases->pluck('target_id')->all())->toBe([$stale->id])
        ->and($releases->sole()->input['intent']['hub_peer']['public_key'])->toBe(TaskVmSlotIdentity::wireguardPublicKey((string) $stale->wireguard_private_key))
        ->and($stale->refresh()->state)->toBe(TaskVmState::Releasing)
        ->and($held->refresh()->state)->toBe(TaskVmState::Provisioning)->and($busy->refresh()->state)->toBe(TaskVmState::Failed);
});

it('refuses a group of a Project whose checkout the base does not carry', function (): void {
    slotPoolHost();
    $other = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => '13.x', 'root' => 'public']);

    expect(fn () => app(TaskVmSlotPool::class)->take(slotPoolGroup($other)))->toThrow(TaskCapacityException::class, 'carries only the checkout of Project')
        ->and(TaskVm::query()->count())->toBe(0);
});

it('has the sweep leave a host alone while its refresh is open, and go on past a row whose release cannot be built', function (): void {
    [$host, $project] = slotPoolHost(maxVms: 12);
    $pool = app(TaskVmSlotPool::class);
    $broken = $pool->take(slotPoolGroup($project));
    $stale = $pool->take(slotPoolGroup($project));
    // A row without its keys has no hub peer to remove, so its intent cannot be built.
    $broken->update(['group_id' => null, 'state' => TaskVmState::Failed, 'wireguard_private_key' => null]);
    $stale->update(['group_id' => null, 'state' => TaskVmState::Failed]);
    slotPoolSweepWorld();
    $refresh = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_vm_host', 'target_id' => $host->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->exists())->toBeFalse();

    $refresh->update(['status' => OperationStatus::Succeeded]);
    app()->call([new SweepTaskVmHosts, 'handle']);
    expect(Operation::query()->where('kind', TaskVmSlotPool::ReleaseKind)->pluck('target_id')->all())->toBe([$stale->id])
        ->and($broken->refresh()->state)->toBe(TaskVmState::Failed);
});

it('leaves task VM Nodes out of the workers\' known hosts and keeps every other pinned Node', function (): void {
    [$host, $project] = slotPoolHost();
    $slot = app(TaskVmSlotPool::class)->take(slotPoolGroup($project));
    slotPoolSweepWorld();

    $content = app(SwampWorkerKnownHostsInput::class)->capture()['known_hosts_content'];

    expect(SwampWorkerKnownHostsInput::covers($slot->node))->toBeFalse()->and(SwampWorkerKnownHostsInput::covers($host))->toBeTrue()
        ->and($content)->toContain('10.44.0.7 ssh-ed25519 fixture-public-key')->not->toContain((string) $slot->wireguard_ip.' ');
});

it('unbinds a failed start\'s group from the workspace its release deletes, and its cancel waits for that release, then closes it', function (): void {
    [$host, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    $release = Operation::query()->create(['kind' => TaskVmSlotPool::ReleaseKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'task-vm']);

    expect(fn () => app(TaskCancelIntent::class)->start($group, 'test', null))->toThrow(ResourceOperationException::class, 'still running');

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null, true);
    $release->update(['status' => OperationStatus::Succeeded]);
    expect($group->refresh()->taskable_id)->toBeNull();

    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    expect($cancel->input['instance_id'])->toBeNull()->and($cancel->input['release'])->toBeNull()
        ->and(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
});

it('lets a cancel admitted before a failed start\'s release still close the group the release unbound, and keeps the slot destroyed', function (): void {
    [, $project] = slotPoolHost();
    slotPoolSweepWorld();
    app(TaskExtensionState::class)->enable();
    $group = slotPoolGroup($project);
    $slot = app(TaskVmSlotPool::class)->take($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $slot->node_id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    // The cancel is admitted while the release job has not started its Operation yet.
    $cancel = app(TaskCancelIntent::class)->start($group, 'test', null);
    app(TaskVmSlotReleaseProjection::class)->record($slot->refresh(), true, null, true);

    expect(app(TaskCancelIntent::class)->input($cancel)['intent']['group_id'])->toBe($group->id);
    app(TaskGroupEnding::class)->failed($cancel->input, 'task_vm.slot_release_failed');
    expect($slot->refresh()->state)->toBe(TaskVmState::Destroyed);
});
