<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSlot;
use App\Domain\TaskVms\TaskVmSlotClaim;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const CLAIM_HOST_KEY = 'AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT';

/**
 * Two task VM hosts with a promoted image each. `$claims` lists the hosts whose tasks claim a slot.
 *
 * @param  list<string>  $claims
 * @return array<string, Node>
 */
function claimWorld(array $claims = ['beast', 'shark']): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $nodes = [];
    $hosts = [];
    foreach (['beast' => 77, 'shark' => 78] as $name => $octet) {
        $nodes[$name] = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => '10.44.0.'.($octet - 60), 'wireguard_ip' => '10.44.0.'.($octet - 60), 'user' => 'orbit',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY, 'ssh_host_fingerprint' => 'SHA256:fixture']));
        $hosts[] = new TaskVmHost($nodes[$name]->id, 'orbit-tasks', 'orbittask'.($octet - 77), "10.251.{$octet}.0/24", 5, 4, '8GiB', '40GiB');
        TaskVmImageBuild::query()->create(['host_node_id' => $nodes[$name]->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
            'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => 'c1c2cf4051fb0123']]]);
    }
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25', $hosts, 'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6'],
        slotClaimHosts: array_map(static fn (string $name): int => $nodes[$name]->id, $claims)));

    return $nodes;
}

/** An enrolled slot: free, with its Node, its keys and its tokens. */
function claimSlot(Node $host, int $slot, TaskVmState $state = TaskVmState::Free): TaskVm
{
    $name = TaskVmSlot::name($host->name, $slot);
    $octet = $host->name === 'beast' ? 77 : 78;
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => "10.251.{$octet}.".(254 - $slot), 'wireguard_ip' => '10.44.0.'.(130 + $slot + ($host->name === 'beast' ? 0 : 10)), 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY, 'ssh_host_fingerprint' => 'SHA256:slot']));

    return TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => $name, 'state' => $state,
        'slot' => $slot, 'address' => "10.251.{$octet}.".(254 - $slot), 'bridge_address' => "10.251.{$octet}.".(254 - $slot), 'bridge_mac' => TaskVmSlot::bridgeMac($name),
        'wireguard_ip' => $node->wireguard_ip, 'pi_token' => str_repeat('p', 64), 'model_key' => str_repeat('m', 64), 'identity_revision' => str_repeat('b', 64)]);
}

function claimGroup(): Task
{
    return Task::query()->create(['title' => 'Feature', 'brief' => 'Do it', 'status' => TaskGroupStatus::Todo]);
}

function claimHostFact(TaskVm $slot, string $receivedAt): void
{
    NodeFact::query()->create(['node_id' => $slot->node_id, 'kind' => NodeFactKind::Host->value, 'subject' => '', 'digest' => 'd', 'data' => [],
        'observed_at' => $receivedAt, 'received_at' => $receivedAt]);
}

it('claims the lowest free slot of the host with the most free slots, and makes no Node, row or token', function (): void {
    $nodes = claimWorld();
    claimSlot($nodes['beast'], 1);
    claimSlot($nodes['beast'], 2);
    foreach ([1, 2, 3] as $n) {
        $shark = claimSlot($nodes['shark'], $n);
    }
    claimHostFact($shark, now()->subHour()->toDateTimeString());
    $group = claimGroup();
    $rows = [TaskVm::query()->count(), Node::query()->count()];

    $vm = app(AllocateTaskVmAction::class)->capture($group);

    expect($vm->name)->toBe('shark-tvm-1');
    expect($vm->state)->toBe(TaskVmState::Provisioning);
    expect([$vm->group_id, $vm->image_fingerprint, $vm->recipe_identity, $vm->image_fallback])->toBe([$group->id, 'c1c2cf4051fb0123', 'recipe-1', true]);
    expect($vm->claimed_at)->not->toBeNull();
    expect([TaskVm::query()->count(), Node::query()->count()])->toBe($rows);
    expect(TaskVm::query()->where('state', TaskVmState::Free)->count())->toBe(4);
    // The old facts of the slot Node go with the claim, so a `host` fact after it is the new VM's own.
    expect(NodeFact::query()->where('node_id', $vm->node_id)->count())->toBe(0);
    // The same group gets the same slot again.
    expect(app(AllocateTaskVmAction::class)->capture($group)->id)->toBe($vm->id);
})->group('slots');

it('leaves the slots alone while no host claims them, so the task takes the old path', function (): void {
    $nodes = claimWorld(claims: []);
    $slot = claimSlot($nodes['beast'], 1);

    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());

    expect($vm->name)->toMatch('/\Atvm-\d+\z/D');
    expect($vm->slot)->toBeNull();
    expect($slot->refresh()->state)->toBe(TaskVmState::Free);
    expect($slot->group_id)->toBeNull();
})->group('slots');

it('gives a host that does not claim slots its own VMs, after the slots of the hosts that do', function (): void {
    $nodes = claimWorld(['beast']);
    $slot = claimSlot($nodes['beast'], 1);

    $first = app(AllocateTaskVmAction::class)->capture(claimGroup());
    $second = app(AllocateTaskVmAction::class)->capture(claimGroup());

    expect($first->id)->toBe($slot->id);
    expect([$second->slot, $second->host_node_id])->toBe([null, $nodes['shark']->id]);
    expect($second->name)->toMatch('/\Atvm-\d+\z/D');
})->group('slots');

it('waits for capacity when a claiming host has no free slot, and never creates a VM there', function (): void {
    $nodes = claimWorld();
    claimSlot($nodes['beast'], 1, TaskVmState::Failed);
    claimSlot($nodes['beast'], 2, TaskVmState::Ready);

    expect(fn () => app(AllocateTaskVmAction::class)->capture(claimGroup()))->toThrow(TaskCapacityException::class, 'Task VM: no capacity.');
    expect(TaskVm::query()->whereNull('slot')->count())->toBe(0);
})->group('slots');

it('gives one free slot to one claim and makes the next claim wait', function (): void {
    $nodes = claimWorld();
    claimSlot($nodes['beast'], 1);

    $first = app(AllocateTaskVmAction::class)->capture(claimGroup());

    expect($first->state)->toBe(TaskVmState::Provisioning);
    expect(fn () => app(AllocateTaskVmAction::class)->capture(claimGroup()))->toThrow(TaskCapacityException::class);
    expect(TaskVm::query()->where('group_id', '!=', $first->group_id)->whereNotNull('group_id')->count())->toBe(0);
})->group('slots');

it('renders the claim as public input without a secret, and the provision still owns it', function (): void {
    $nodes = claimWorld(['beast']);
    claimSlot($nodes['beast'], 1);
    $group = claimGroup();
    $vm = app(AllocateTaskVmAction::class)->capture($group);

    $input = app(TaskVmProvisionIntent::class)->capture($vm);

    expect($input)->toMatchArray(['placement' => 'slot', 'task_vm_id' => $vm->id, 'node_id' => $nodes['beast']->id, 'host_node_id' => $nodes['beast']->id,
        'guest_node_id' => $vm->node_id, 'name' => 'beast-tvm-1', 'provider' => 'incus', 'wireguard_ip' => $vm->wireguard_ip, 'image_fingerprint' => 'c1c2cf4051fb0123']);
    expect($input['slot_claim'])->toMatchArray(['host_node_id' => $nodes['beast']->id, 'nested_reset' => false]);
    expect($input['slot_claim']['vm'])->toMatchArray(['fingerprint' => 'c1c2cf4051fb0123', 'seed_source' => 'orbit-topology-seed-c1c2cf4051fb', 'seed_clone' => 'beast-tvm-1-topology-seed']);
    expect(json_decode($input['slot_claim']['vm']['config_json'], true, flags: JSON_THROW_ON_ERROR)['devices']['identity'])->toMatchArray(['source' => 'beast-tvm-1-identity', 'readonly' => 'true']);
    expect($input['slot_claim']['guest'])->toMatchArray(['user' => 'orbit', 'host_key' => 'ssh-ed25519 '.CLAIM_HOST_KEY, 'pi_sha256' => str_repeat('a', 64)]);
    $encoded = json_encode($input, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain(str_repeat('p', 64))->not->toContain(str_repeat('m', 64));
    expect(json_decode($encoded, true)['execution_secrets'])->toBe([]);
    expect($encoded)->toContain('"execution_secrets":{}');
    $receipt = json_decode($input['slot_claim']['receipt_json'], true, flags: JSON_THROW_ON_ERROR);
    expect($receipt)->toMatchArray(['task_vm_id' => $vm->id, 'ready' => true]);
    expect($receipt['enrollment']['node'])->toBe(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY]);
    expect($encoded)->toContain('"process":{}');

    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $nodes['beast']->id,
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0, 'input' => $input]);
    expect(app(TaskVmProvisionIntent::class)->owned($operation)->id)->toBe($vm->id);
})->group('slots');

it('turns the nested reset on from the setting', function (): void {
    $nodes = claimWorld(['beast']);
    claimSlot($nodes['beast'], 1);
    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());
    $on = clone app(TaskVmSettings::class);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $on->devClusterId, $on->wireguardRange, $on->hosts, $on->modelProxyOrigin, $on->piArtifactPath,
        $on->piArtifactSha256, $on->piModels, slotClaimHosts: $on->slotClaimHosts, nestedReset: true));

    expect(app(TaskVmSlotClaim::class)->capture($vm)['slot_claim']['nested_reset'])->toBeTrue();
})->group('slots');

it('counts a host report after the claim as the new VM reporting, and an older one as none', function (): void {
    $nodes = claimWorld(['beast']);
    $slot = claimSlot($nodes['beast'], 1);
    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());
    $claims = app(TaskVmSlotClaim::class);

    expect($claims->reports($vm))->toBeFalse();
    claimHostFact($vm, $vm->claimed_at->subMinute()->toDateTimeString());
    expect($claims->reports($vm))->toBeFalse();
    NodeFact::query()->where('node_id', $vm->node_id)->delete();
    claimHostFact($vm, $vm->claimed_at->addSecond()->toDateTimeString());
    expect($claims->reports($vm))->toBeTrue();

    $claims->record($vm, ['provider' => ['address' => $slot->bridge_address]]);

    expect($vm->refresh()->state)->toBe(TaskVmState::Ready);
    expect([$vm->ready_at, $vm->error_code])->not->toBe([null, null]);
})->group('slots');

/**
 * A claimed slot with the Operation that provisions it for its task group, as the projection reads them.
 *
 * @return array{0: TaskVm, 1: Operation, 2: Task}
 */
function claimedProvision(): array
{
    $nodes = claimWorld();
    claimSlot($nodes['beast'], 1);
    $project = Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'git@github.com:acme/blog.git', 'repository_identity' => 'github.com/acme/blog', 'root' => 'public']);
    $group = claimGroup();
    $group->update(['project_id' => $project->id]);
    $vm = app(AllocateTaskVmAction::class)->capture($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/blog/workspace',
        'task_workspace_owner_id' => $group->id, 'status' => 'reserved']);
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now()])->save();
    $input = [...app(TaskVmProvisionIntent::class)->capture($vm), 'group_id' => $group->id, 'instance_id' => $instance->id, 'project_id' => $project->id,
        'eligible' => true, 'route_id' => null, 'route' => null, 'members' => []];
    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $nodes['beast']->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => $input, 'state' => ['swamp_run_id' => 'fixture-claim']]);

    return [$vm, $operation, $group];
}

/** @return array<string,mixed> */
function claimRun(TaskVm $vm, string $status, array $change = []): array
{
    $receipt = ['task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'ready' => true,
        'provider' => ['provider_id' => null, 'address' => $vm->bridge_address, 'image_fingerprint' => $vm->image_fingerprint, 'running' => true, 'settled' => true],
        'enrollment' => ['ready' => true, 'node' => ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY], 'process' => []],
        'source' => ['branch' => 'main', 'commit' => str_repeat('1', 40), 'php_version' => '8.4', 'laravel' => true]];

    return ['run_id' => 'fixture-claim', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['receipt' => ['result_json' => json_encode([...$receipt, ...$change], JSON_THROW_ON_ERROR)]]];
}

it('makes the slot ready and the task running when the new VM has reported', function (): void {
    [$vm, $operation, $group] = claimedProvision();
    claimHostFact($vm, now()->addSecond()->toDateTimeString());

    app(TaskProvisionProjection::class)->apply($operation, claimRun($vm, 'succeeded'));

    expect($vm->refresh()->state)->toBe(TaskVmState::Ready);
    expect($vm->ready_at)->not->toBeNull();
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    expect($operation->refresh()->status->value)->toBe('succeeded');
    // The slot keeps its Node and its tokens.
    expect([$vm->node->status->value, $vm->pi_token])->toBe(['active', str_repeat('p', 64)]);
})->group('slots');

it('fails the claim and the slot when the new VM never reported', function (): void {
    [$vm, $operation, $group] = claimedProvision();

    app(TaskProvisionProjection::class)->apply($operation, claimRun($vm, 'succeeded'));

    expect($vm->refresh()->state)->toBe(TaskVmState::Failed);
    expect($vm->error_code)->toBe('task_vm.slot_not_reporting');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    expect($operation->refresh()->status->value)->toBe('failed');
})->group('slots');

it('leaves a failed claim as a failed slot that no claim can take', function (): void {
    [$vm, $operation, $group] = claimedProvision();

    app(TaskProvisionProjection::class)->apply($operation, [...claimRun($vm, 'failed'), 'result' => ['receipt' => ['result_json' => json_encode(['error_code' => 'tasks.provision_failed', 'failed_step' => 'vm'])]], 'error_code' => 'operation.native_failed']);

    expect($vm->refresh()->state)->toBe(TaskVmState::Failed);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    expect(fn () => app(AllocateTaskVmAction::class)->capture(claimGroup()))->toThrow(TaskCapacityException::class);
})->group('slots');

it('refuses to destroy a slot until its release is built', function (): void {
    $nodes = claimWorld(['beast']);
    claimSlot($nodes['beast'], 1);
    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());

    $code = rescue(fn () => app(TaskVmDestroyIntent::class)->capture($vm), static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code)->toBe('task_vm.slot_release_unavailable');
})->group('slots');

it('has no claiming host and no nested reset by default, reads both from the config, and refuses a malformed host list', function (): void {
    expect([TaskVmSettings::fromConfig()->slotClaimHosts, TaskVmSettings::fromConfig()->nestedReset])->toBe([[], false]);

    config(['task_vms.slot_claim_hosts' => [4, 9], 'task_vms.nested_reset' => true]);
    $settings = TaskVmSettings::fromConfig();
    expect([$settings->claimsSlots(4), $settings->claimsSlots(5), $settings->nestedReset])->toBe([true, false, true]);

    config(['task_vms.slot_claim_hosts' => 'beast']);
    expect(fn () => TaskVmSettings::fromConfig())->toThrow(TaskVmException::class, 'slot_claim_hosts must be a JSON list of Node ids.');
})->group('slots');
