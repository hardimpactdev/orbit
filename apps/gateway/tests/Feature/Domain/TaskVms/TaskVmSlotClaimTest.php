<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\StartTaskVmDestroyAction;
use App\Actions\TaskVms\StartTaskVmSlotReleaseAction;
use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmDestroyIntent;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlot;
use App\Domain\TaskVms\TaskVmSlotClaim;
use App\Domain\TaskVms\TaskVmSlotEnrollIntent;
use App\Domain\TaskVms\TaskVmSlotReleaseIntent;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Cluster;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

const CLAIM_HOST_KEY = 'AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT';

/**
 * Two task VM hosts with a promoted image each, of the current recipe unless `$image` names another. `$claims` lists the
 * hosts whose tasks claim a slot.
 *
 * @param  list<string>  $claims
 * @return array<string, Node>
 */
function claimWorld(array $claims = ['beast', 'shark'], ?string $image = null): array
{
    $image ??= app(RecipeImageBuilds::class)->currentRecipe()->identity;
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $nodes = [];
    $hosts = [];
    foreach (['beast' => 77, 'shark' => 78] as $name => $octet) {
        $nodes[$name] = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => '10.44.0.'.($octet - 60), 'wireguard_ip' => '10.44.0.'.($octet - 60), 'user' => 'orbit',
            'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY, 'ssh_host_fingerprint' => 'SHA256:fixture']));
        $hosts[] = new TaskVmHost($nodes[$name]->id, 'orbit-tasks', 'orbittask'.($octet - 77), "10.251.{$octet}.0/24", 5, 4, '8GiB', '40GiB');
        TaskVmImageBuild::query()->create(['host_node_id' => $nodes[$name]->id, 'recipe_identity' => $image, 'state' => TaskVmImageBuildState::Succeeded,
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
        'cluster_id' => app(TaskVmSettings::class)->devClusterId, 'ssh_jump_node_id' => $host->id,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => CLAIM_HOST_KEY, 'ssh_host_fingerprint' => 'SHA256:slot']));

    Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'pi-server', 'runtime' => 'systemd', 'runtime_config' => '{}', 'status' => 'active']);

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
    $other = claimSlot($nodes['beast'], 1);
    claimSlot($nodes['beast'], 2);
    claimHostFact($other, now()->subHour()->toDateTimeString());
    foreach ([1, 2, 3] as $n) {
        $shark = claimSlot($nodes['shark'], $n);
    }
    claimHostFact($shark, now()->subHour()->toDateTimeString());
    $group = claimGroup();
    $rows = [TaskVm::query()->count(), Node::query()->count()];

    $vm = app(AllocateTaskVmAction::class)->capture($group);

    expect($vm->name)->toBe('shark-tvm-1');
    expect($vm->state)->toBe(TaskVmState::Provisioning);
    expect([$vm->group_id, $vm->image_fingerprint, $vm->recipe_identity, $vm->image_fallback])->toBe([$group->id, 'c1c2cf4051fb0123', app(RecipeImageBuilds::class)->currentRecipe()->identity, false]);
    expect($vm->claimed_at)->not->toBeNull();
    expect([TaskVm::query()->count(), Node::query()->count()])->toBe($rows);
    expect(TaskVm::query()->where('state', TaskVmState::Free)->count())->toBe(4);
    // The old facts of the slot Node go with the claim, so a `host` fact after it is the new VM's own.
    expect(NodeFact::query()->where('node_id', $vm->node_id)->count())->toBe(0);
    // The facts of every other Node stay.
    expect(NodeFact::query()->where('node_id', $other->node_id)->count())->toBe(1);
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
    claimSlot($nodes['shark'], 1, TaskVmState::Failed);

    expect(fn () => app(AllocateTaskVmAction::class)->capture(claimGroup()))->toThrow(TaskCapacityException::class, 'Task VM: no capacity.');
    expect(TaskVm::query()->whereNull('slot')->count())->toBe(0);
})->group('slots');

it('gives one free slot to one claim and makes the next claim wait', function (): void {
    $nodes = claimWorld();
    claimSlot($nodes['beast'], 1);
    claimSlot($nodes['shark'], 1, TaskVmState::Failed);

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
    $process = Process::query()->where('owner_id', $vm->node_id)->where('name', 'pi-server')->value('id');
    expect($receipt['enrollment']['process'])->toBe(['process_id' => $process, 'status' => 'active']);

    // The Operation goes through the database: an empty object would come back as an empty list, which the workspace steps reject.
    $operation = Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $nodes['beast']->id,
        'status' => 'queued', 'sequence' => 1, 'attempts' => 0, 'input' => $input])->fresh();
    $stored = json_decode($operation->input['slot_claim']['receipt_json'], true, flags: JSON_THROW_ON_ERROR);
    expect($stored['enrollment']['process'])->toBe(['process_id' => $process, 'status' => 'active']);
    expect($operation->input['slot_claim']['receipt']['enrollment']['process'])->toBe(['process_id' => $process, 'status' => 'active']);
    expect(app(TaskVmProvisionIntent::class)->owned($operation)->id)->toBe($vm->id);
})->group('slots');

it('refuses the claim while the nested reset is on, and the admission rolls back so no slot is burned', function (): void {
    $nodes = claimWorld(['beast']);
    $slot = claimSlot($nodes['beast'], 1);
    $on = app(TaskVmSettings::class);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $on->devClusterId, $on->wireguardRange, $on->hosts, $on->modelProxyOrigin, $on->piArtifactPath,
        $on->piArtifactSha256, $on->piModels, slotClaimHosts: $on->slotClaimHosts, nestedReset: true));

    $code = rescue(fn () => DB::transaction(function (): void {
        app(TaskVmProvisionIntent::class)->capture(app(AllocateTaskVmAction::class)->capture(claimGroup()));
    }), static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code)->toBe('task_vm.nested_reset_unavailable');
    expect($slot->refresh()->state)->toBe(TaskVmState::Free);
    expect($slot->group_id)->toBeNull();
})->group('slots');

it('claims a slot only with the image of the current recipe, never the fallback image', function (): void {
    $nodes = claimWorld(['beast'], image: 'recipe-old');
    $slot = claimSlot($nodes['beast'], 1);

    // The old image is the host's last promoted one, so the old path may use it; the slot stays free.
    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());

    expect($slot->refresh()->state)->toBe(TaskVmState::Free);
    expect($vm->slot)->toBeNull();
})->group('slots');

it('gives a group with a bigger size no slot, and serves a listed host with no slot row as an unlisted one', function (): void {
    $nodes = claimWorld(['beast']);
    $slot = claimSlot($nodes['beast'], 1);
    $settings = app(TaskVmSettings::class);
    $project = Project::query()->create(['name' => 'Big', 'slug' => 'big', 'repository_url' => 'git@github.com:acme/big.git', 'repository_identity' => 'github.com/acme/big', 'root' => 'public']);
    $group = claimGroup();
    $group->update(['project_id' => $project->id]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $settings->devClusterId, $settings->wireguardRange, $settings->hosts, $settings->modelProxyOrigin,
        $settings->piArtifactPath, $settings->piArtifactSha256, $settings->piModels, mediumProjectIds: [$project->id], slotClaimHosts: $settings->slotClaimHosts));

    $medium = app(AllocateTaskVmAction::class)->capture($group);

    expect([$medium->size, $medium->slot, $medium->host_node_id])->toBe([TaskVmSize::Medium, null, $nodes['shark']->id]);
    expect($slot->refresh()->state)->toBe(TaskVmState::Free);

    // A host that is listed but has no slot row is served as an unlisted one.
    $none = app(AllocateTaskVmAction::class)->capture(claimGroup());
    expect($none->slot)->toBe(1);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $settings->devClusterId, $settings->wireguardRange, $settings->hosts, $settings->modelProxyOrigin,
        $settings->piArtifactPath, $settings->piArtifactSha256, $settings->piModels, slotClaimHosts: [$nodes['beast']->id, $nodes['shark']->id]));
    $own = app(AllocateTaskVmAction::class)->capture(claimGroup());
    expect([$own->slot, $own->host_node_id])->toBe([null, $nodes['shark']->id]);
})->group('slots');

it('keeps the claim data when a failed slot is enrolled again, so capture() accepts it', function (): void {
    $nodes = claimWorld(['beast']);
    claimSlot($nodes['beast'], 1);
    $vm = app(AllocateTaskVmAction::class)->capture(claimGroup());
    $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'operation.native_failed']);

    // The enrolment records the Pi Process itself.
    Process::query()->where('owner_id', $vm->node_id)->where('name', 'pi-server')->delete();

    $again = app(TaskVmSlotEnrollIntent::class)->reserve($nodes['beast'], 1);

    expect($again->id)->toBe($vm->id);
    expect([$again->state, $again->group_id, $again->claimed_at, $again->error_code])->toBe([TaskVmState::Provisioning, null, null, null]);
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
    claimSlot($nodes['shark'], 1, TaskVmState::Failed);
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

    // The failed claim frees its slot: the release is admitted, so the slot is `destroying` and no claim takes it.
    expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
    expect($vm->error_code)->toBe('task_vm.slot_not_reporting');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    expect($operation->refresh()->status->value)->toBe('failed');
    expect(Operation::query()->where('kind', 'task_vm.slot_release')->where('target_id', $vm->id)->count())->toBe(1);
})->group('slots');

it('releases the slot of a failed claim, and no claim can take it meanwhile', function (): void {
    [$vm, $operation, $group] = claimedProvision();

    app(TaskProvisionProjection::class)->apply($operation, [...claimRun($vm, 'failed'), 'result' => ['receipt' => ['result_json' => json_encode(['error_code' => 'tasks.provision_failed', 'failed_step' => 'vm'])]], 'error_code' => 'operation.native_failed']);

    expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    expect(fn () => app(AllocateTaskVmAction::class)->capture(claimGroup()))->toThrow(TaskCapacityException::class);
})->group('slots');

/**
 * A slot that a task claimed and whose task has ended, with the workspace Instance and records the task left on the
 * slot Node. `$status` is the ended group's status.
 *
 * @return array{0: TaskVm, 1: Task, 2: Instance}
 */
function endedSlot(TaskGroupStatus $status = TaskGroupStatus::Cancelled, TaskVmState $state = TaskVmState::Ready): array
{
    $nodes = claimWorld(['beast']);
    claimSlot($nodes['beast'], 1);
    $project = Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'git@github.com:acme/blog.git', 'repository_identity' => 'github.com/acme/blog', 'root' => 'public']);
    $group = claimGroup();
    $group->update(['project_id' => $project->id]);
    $vm = app(AllocateTaskVmAction::class)->capture($group);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/blog/workspace',
        'task_workspace_owner_id' => $group->id, 'task_workspace_routed' => true, 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $vm->node_id, 'domain' => 'task-'.$group->id.'.blog.dev', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => 'vite', 'runtime' => 'systemd', 'runtime_config' => '{}', 'status' => 'active']);
    claimHostFact($vm, now()->toDateTimeString());
    NodeFact::query()->create(['node_id' => $vm->node_id, 'kind' => NodeFactKind::Checkout->value, 'subject' => $instance->checkout_path, 'digest' => 'c', 'data' => [],
        'observed_at' => now(), 'received_at' => now()]);
    $vm->update(['state' => $state, 'ready_at' => now(), 'error_code' => 'task_vm.old', 'publish_commit' => str_repeat('1', 40), 'topology_error' => 'old']);
    $group->taskable()->dissociate();
    $group->fill(['status' => $status, 'settled_at' => now()])->save();

    return [$vm->refresh(), $group, $instance];
}

/** @return array<string,mixed> */
function releaseRun(string $status, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-release', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the slot is clean' => ['status' => $endCheck]]]];
}

function releaseOperation(TaskVm $vm): Operation
{
    return Operation::query()->create(['kind' => 'task_vm.slot_release', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'node_id' => $vm->host_node_id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'input' => app(TaskVmSlotReleaseIntent::class)->capture($vm), 'state' => ['swamp_run_id' => 'fixture-release']]);
}

/** The records a release must leave as they were before the claim. */
function slotRecords(TaskVm $vm): array
{
    $node = Node::query()->findOrFail($vm->node_id);

    return [
        'row' => Arr::except($vm->fresh()->getAttributes(), ['updated_at']),
        'node' => Arr::except($node->getAttributes(), ['updated_at']),
        'processes' => Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->pluck('name', 'id')->all(),
        'facts' => NodeFact::query()->where('node_id', $node->id)->count(),
        'instances' => Instance::query()->where('node_id', $node->id)->count(),
        'routes' => Route::query()->where('node_id', $node->id)->count(),
    ];
}

it('renders the release as public input without a secret, for a slot whose task has ended', function (): void {
    [$vm, $group] = endedSlot();

    $input = app(TaskVmSlotReleaseIntent::class)->capture($vm);

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $vm->node_id, 'slot' => 1, 'name' => 'beast-tvm-1', 'group_id' => $group->id]);
    expect($input['vm'])->toBe(['project' => 'orbit-tasks', 'pool' => 'orbit-tasks', 'name' => 'beast-tvm-1', 'seed_clone' => 'beast-tvm-1-topology-seed', 'identity_volume' => 'beast-tvm-1-identity']);
    expect($input['host_ssh_execution']['target']['host'])->toBe('10.44.0.17');
    expect(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('p', 64))->not->toContain(str_repeat('m', 64));
    expect(TaskVmProviderWorkflowInput::envelope($input)['execution_secrets'])->toBe([]);
})->group('slots');

it('refuses to release a slot that a task holds, a free slot, and a slot that is still enrolling', function (): void {
    [$vm, $group] = endedSlot();
    $intent = app(TaskVmSlotReleaseIntent::class);
    $code = static function () use ($intent, $vm): ?string {
        try {
            $intent->capture($vm->refresh());

            return null;
        } catch (ResourceOperationException $error) {
            return $error->errorCode;
        }
    };

    // An open task holds the slot.
    $group->update(['status' => TaskGroupStatus::Running]);
    expect($code())->toBe('task_vm.slot_held');
    // An ended task that still owns its workspace needs its ending run first: teardown, Route withdrawal and the push.
    $instance = Instance::query()->where('task_workspace_owner_id', $group->id)->sole();
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Completed])->save();
    expect($code())->toBe('task_vm.slot_held');
    expect($intent->sweepable($vm))->toBeFalse();
    // A failed claim never got past provisioning, so its workspace has nothing to tear down.
    $group->update(['status' => TaskGroupStatus::Failed]);
    expect($code())->toBeNull();
    expect($intent->sweepable($vm))->toBeTrue();
    // A free slot and an enrolling slot (no task) have nothing to release.
    $vm->update(['state' => TaskVmState::Free, 'group_id' => null]);
    expect($code())->toBe('task_vm.slot_invalid');
    $vm->update(['state' => TaskVmState::Provisioning]);
    expect($code())->toBe('task_vm.slot_invalid');
    expect($intent->sweepable($vm->refresh()))->toBeFalse();
})->group('slots');

it('admits one release per slot, marks the slot destroying, and leaves a free slot alone', function (): void {
    [$vm] = endedSlot();
    $start = static fn (): ?string => rescue(fn () => app(StartTaskVmSlotReleaseAction::class)->execute($vm->id)?->kind, static fn (ResourceOperationException $error): string => $error->errorCode, false);

    // No Swamp is configured here, so the start fails after the admission is complete; that is the part under test.
    expect($start())->toBe('swamp.start_unavailable');
    $operation = Operation::query()->where('kind', 'task_vm.slot_release')->sole();
    expect([$operation->target_id, $operation->node_id, $vm->refresh()->state])->toBe([$vm->id, $vm->host_node_id, TaskVmState::Destroying]);
    expect($operation->input['vm']['seed_clone'])->toBe('beast-tvm-1-topology-seed');
    // The failed start ended the Operation. With an open one, the same slot admits no second Operation.
    $operation->update(['status' => 'queued', 'error_code' => null]);
    $start();
    expect(Operation::query()->where('kind', 'task_vm.slot_release')->count())->toBe(1);

    // A free slot has nothing to release.
    $vm->update(['state' => TaskVmState::Free, 'group_id' => null]);
    $operation->update(['status' => 'succeeded']);
    expect(app(StartTaskVmSlotReleaseAction::class)->execute($vm->id))->toBeNull();
})->group('slots');

it('frees the slot after a passed end check: scrubs the facts and the workspace records, keeps the identity, and clears the task', function (): void {
    [$vm, $group, $instance] = endedSlot();
    // A failed claim: the group ended `failed` and still names its workspace.
    $group->taskable()->associate($instance);
    $group->fill(['status' => TaskGroupStatus::Failed])->save();
    $other = TaskVm::query()->where('slot', 1)->whereKeyNot($vm->id)->first();
    $vm->update(['state' => TaskVmState::Destroying]);
    $operation = releaseOperation($vm);
    $keys = [$vm->node_id, $vm->pi_token, $vm->model_key, $vm->identity_revision, $vm->wireguard_ip, $vm->bridge_address, $vm->bridge_mac, $vm->node->ssh_host_key];

    app(TaskVmSlotReleaseProjection::class)->apply($operation, releaseRun('succeeded'));

    $vm->refresh();
    expect($operation->refresh()->status->value)->toBe('succeeded');
    expect($operation->result)->toMatchArray(['released' => true, 'task_vm_id' => $vm->id]);
    expect($vm->state)->toBe(TaskVmState::Free);
    expect([$vm->group_id, $vm->claimed_at, $vm->ready_at, $vm->image_fingerprint, $vm->recipe_identity, $vm->image_build_id, $vm->publish_commit, $vm->topology_error, $vm->error_code, $vm->error_message])
        ->toBe([null, null, null, null, null, null, null, null, null, null]);
    expect([$vm->node_id, $vm->pi_token, $vm->model_key, $vm->identity_revision, $vm->wireguard_ip, $vm->bridge_address, $vm->bridge_mac, $vm->node->ssh_host_key])->toBe($keys);
    // Every fact of the slot Node is gone, so the next claim's fresh-fact check is clean.
    expect(NodeFact::query()->where('node_id', $vm->node_id)->count())->toBe(0);
    expect(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
    expect(Route::query()->where('node_id', $vm->node_id)->count())->toBe(0);
    expect(Process::query()->where('owner_type', Instance::MorphAlias)->where('owner_id', $instance->id)->count())->toBe(0);
    // The Node stays active with its role and its pi-server Process.
    expect(Process::query()->where('owner_type', Node::class)->where('owner_id', $vm->node_id)->pluck('name')->all())->toBe(['pi-server']);
    expect($vm->node->status->value)->toBe('active');
    expect($other)->toBeNull();
    // The group keeps its status, and no group points at the removed workspace.
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Failed);
    expect(Task::query()->withoutGlobalScopes()->whereNotNull('taskable_id')->count())->toBe(0);
    // A new task can claim the slot at once.
    expect(app(AllocateTaskVmAction::class)->capture(claimGroup())->id)->toBe($vm->id);
})->group('slots');

it('leaves the slot records equal to the baseline after three claim and release cycles', function (): void {
    $nodes = claimWorld(['beast']);
    $slot = claimSlot($nodes['beast'], 1);
    $baseline = slotRecords($slot);
    $project = Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'git@github.com:acme/blog.git', 'repository_identity' => 'github.com/acme/blog', 'root' => 'public']);

    foreach ([1, 2, 3] as $cycle) {
        $group = claimGroup();
        $group->update(['project_id' => $project->id]);
        $vm = app(AllocateTaskVmAction::class)->capture($group);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $vm->node_id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/blog/workspace',
            'task_workspace_owner_id' => $group->id, 'status' => 'active']);
        Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id, 'name' => 'vite', 'runtime' => 'systemd', 'runtime_config' => '{}', 'status' => 'active']);
        claimHostFact($vm, now()->toDateTimeString());
        $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now()]);
        $group->fill(['status' => TaskGroupStatus::Completed, 'settled_at' => now()])->save();

        expect(app(TaskVmSlotReleaseIntent::class)->held($vm->refresh()))->toBeFalse();
        $vm->update(['state' => TaskVmState::Destroying]);
        app(TaskVmSlotReleaseProjection::class)->apply(releaseOperation($vm), releaseRun('succeeded'));

        expect(slotRecords($vm))->toBe($baseline);
    }
    expect(TaskVm::query()->count())->toBe(1);
})->group('slots');

it('fails the slot when the Node still holds a record of the task, and reports it', function (): void {
    [$vm] = endedSlot();
    FirewallRule::query()->create(['node_id' => $vm->node_id, 'label' => 'task', 'action' => 'allow', 'source' => 'any', 'protocol' => 'tcp', 'port' => '8000', 'status' => 'active']);
    $vm->update(['state' => TaskVmState::Destroying]);
    $operation = releaseOperation($vm);

    app(TaskVmSlotReleaseProjection::class)->apply($operation, releaseRun('succeeded'));

    $vm->refresh();
    expect($vm->state)->toBe(TaskVmState::Failed);
    expect($vm->error_code)->toBe('task_vm.slot_not_clean');
    expect($vm->error_message)->toContain('firewall rules');
    expect($operation->refresh()->status->value)->toBe('failed');
    expect($operation->error_code)->toBe('task_vm.slot_not_clean');
    // The workspace records are gone; the group stays recorded, so the sweep can release the slot once the rule is removed.
    expect(Instance::query()->where('node_id', $vm->node_id)->exists())->toBeFalse();
    expect(app(TaskVmSlotReleaseIntent::class)->sweepable($vm))->toBeTrue();
})->group('slots');

it('keeps the slot destroying with the error when the run fails or expires, and the sweep releases it again', function (): void {
    [$vm] = endedSlot();
    $vm->update(['state' => TaskVmState::Destroying]);
    $operation = releaseOperation($vm);

    app(TaskVmSlotReleaseProjection::class)->apply($operation, [...releaseRun('failed', 'failed'), 'error_code' => 'operation.native_failed']);

    expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
    expect($vm->error_code)->toBe('task_vm.slot_release_failed');
    expect($operation->refresh()->status->value)->toBe('failed');
    // Nothing was scrubbed: the facts and the group stay until a release passes.
    expect([NodeFact::query()->where('node_id', $vm->node_id)->count() > 0, $vm->group_id !== null])->toBe([true, true]);
    expect(app(TaskVmSlotReleaseIntent::class)->sweepable($vm))->toBeTrue();

    $operation->update(['status' => 'succeeded']);
    $second = releaseOperation($vm);
    app(TaskVmSlotReleaseProjection::class)->timedOut($second);
    expect($vm->refresh()->error_code)->toBe('operation.timed_out');
    expect($vm->state)->toBe(TaskVmState::Destroying);
})->group('slots');

it('refuses a succeeded release whose end check did not pass', function (): void {
    [$vm] = endedSlot();
    $vm->update(['state' => TaskVmState::Destroying]);

    $code = rescue(fn () => app(TaskVmSlotReleaseProjection::class)->apply(releaseOperation($vm), releaseRun('succeeded', 'failed')), static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code)->toBe('operation.result_invalid');
    expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
    expect($vm->group_id)->not->toBeNull();
})->group('slots');

it('never destroys a slot, and refuses to start a destroy for one', function (): void {
    [$vm] = endedSlot();

    $code = rescue(fn () => app(StartTaskVmDestroyAction::class)->execute($vm->id), static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code)->toBe('task_vm.slot_not_destroyable');
    expect(Operation::query()->where('kind', 'task_vm.destroy')->count())->toBe(0);
})->group('slots');

it('ends the cancel of a slot task with the task cleanup but no VM destroy, and starts the release after it', function (): void {
    [$vm, $group, $instance] = endedSlot(TaskGroupStatus::Running, TaskVmState::Ready);
    $group->taskable()->associate($instance);
    $group->save();
    app(TaskExtensionState::class)->enable();

    // No Swamp is configured here, so the start fails after the admission is complete; that is the part under test.
    $code = rescue(fn () => app(TaskCancelIntent::class)->start($group->refresh(), 'test', null), static fn (ResourceOperationException $error): string => $error->errorCode, false);

    expect($code)->toBe('swamp.start_unavailable');
    $cancel = Operation::query()->where('kind', 'tasks.cancel')->sole();
    expect($cancel->input)->toMatchArray(['slot' => true, 'task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'group_id' => $group->id, 'instance_id' => $instance->id, 'name' => 'beast-tvm-1']);
    expect($cancel->input['execution_secrets'])->toBe([]);
    expect(array_keys($cancel->input))->not->toContain('node_removal')->not->toContain('model_intent')->not->toContain('provider_input');
    // The slot is untouched until the cleanup succeeded.
    expect($vm->refresh()->state)->toBe(TaskVmState::Ready);

    $cancel->update(['status' => 'running', 'state' => ['swamp_run_id' => 'fixture-cancel']]);
    app(TaskCancelProjection::class)->apply($cancel, ['run_id' => 'fixture-cancel', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['group_id' => $group->id, 'instance_id' => $instance->id, 'cleanup_complete' => true]]);

    expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
    // The release is admitted: the slot is destroying, not destroyed, and keeps its Node.
    expect($vm->refresh()->state)->toBe(TaskVmState::Destroying);
    expect(Operation::query()->where('kind', 'task_vm.slot_release')->where('target_id', $vm->id)->count())->toBe(1);
    expect(Node::query()->whereKey($vm->node_id)->exists())->toBeTrue();
    expect(Operation::query()->where('kind', 'task_vm.destroy')->count())->toBe(0);
})->group('slots');

it('keeps the destroy of a VM that is not a slot as it was: its own VM, provider and model key cleanup', function (): void {
    claimWorld(claims: []);
    $project = Project::query()->create(['name' => 'Blog', 'slug' => 'blog', 'repository_url' => 'git@github.com:acme/blog.git', 'repository_identity' => 'github.com/acme/blog', 'root' => 'public']);
    $group = claimGroup();
    $group->update(['project_id' => $project->id]);
    $vm = app(AllocateTaskVmAction::class)->capture($group);
    $vm->update(['model_key' => null]);

    $capture = app(TaskVmDestroyIntent::class)->capture($vm);

    expect($capture)->not->toHaveKey('slot');
    expect($capture)->toHaveKeys(['provider_input', 'node_removal', 'removal', 'model_key_required', 'workspace_routes']);
    expect([$capture['task_vm_id'], $capture['name'], $capture['model_key_required']])->toBe([$vm->id, 'tvm-'.$vm->id, false]);
})->group('slots');

it('has no claiming host and no nested reset by default, reads both from the config, and refuses a malformed host list', function (): void {
    expect([TaskVmSettings::fromConfig()->slotClaimHosts, TaskVmSettings::fromConfig()->nestedReset])->toBe([[], false]);

    config(['task_vms.slot_claim_hosts' => [4, 9], 'task_vms.nested_reset' => true]);
    $settings = TaskVmSettings::fromConfig();
    expect([$settings->claimsSlots(4), $settings->claimsSlots(5), $settings->nestedReset])->toBe([true, false, true]);

    config(['task_vms.slot_claim_hosts' => 'beast']);
    expect(fn () => TaskVmSettings::fromConfig())->toThrow(TaskVmException::class, 'slot_claim_hosts must be a JSON list of Node ids.');
})->group('slots');
