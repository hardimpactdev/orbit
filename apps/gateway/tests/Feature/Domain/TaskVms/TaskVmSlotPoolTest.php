<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotEnrolProjection;
use App\Domain\TaskVms\TaskVmSlotIdentity;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotReleaseProjection;
use App\Domain\TaskVms\TaskVmSlotSetting;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A task VM host with a promoted image and, unless `$count` is null, a pool of `$count` large slots of the returned Project. */
function slotPoolHost(?int $count = 2, int $maxVms = 6): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $slots = $count === null ? null : new TaskVmSlotSetting($count, $project->id, TaskVmSize::Large);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', $maxVms, 4, '8GiB', '20GiB', slots: $slots)],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'snapshot', 'vm' => 'beast-base', 'seed' => 'beast-base-seed', 'snapshot' => 'r20261107120000']]]);

    return [$host, $project, $build];
}

/** Slot `$number` of the host: by default free at the current image. */
function slotPoolSlot(Node $host, Project $project, TaskVmImageBuild $build, int $number, array $overrides = []): TaskVm
{
    return TaskVm::query()->create([...['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'slot_number' => $number, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => $host->name.'-tvm-'.$number, 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.'.(200 + $number),
        'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => 'recipe-1', 'ready_at' => now()], ...$overrides]);
}

/** A todo task group of the Project. */
function slotPoolGroup(Project $project): Task
{
    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
}

it('keeps no slots on a host without the slots setting', function (): void {
    [$host] = slotPoolHost(null);

    expect(app(TaskVmSlotPool::class)->next($host->id))->toBeNull();
});

it('records each missing slot by its fixed name and number, one enrol at a time', function (): void {
    [$host, $project] = slotPoolHost(2);
    $pool = app(TaskVmSlotPool::class);

    $first = $pool->next($host->id);
    expect($first['slot']->name)->toBe('beast-tvm-1')->and($first['slot']->slot_number)->toBe(1)
        ->and($first['slot']->state)->toBe(TaskVmState::Provisioning)->and($first['slot']->slot_project_id)->toBe($project->id);
    // The first enrols: the rule waits for it while its Operation is open.
    Operation::query()->create(['kind' => 'task_vm.slot_enrol', 'target_type' => 'task_vm', 'target_id' => $first['slot']->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    expect($pool->next($host->id))->toBeNull();

    $first['slot']->update(['state' => TaskVmState::Parked]);
    $second = $pool->next($host->id);
    expect($second['slot']->name)->toBe('beast-tvm-2')->and($second['slot']->wireguard_ip)->not->toBe($first['slot']->wireguard_ip);
});

it('enrols a failed identity again and never touches a claimed one; an identity of an older image needs nothing', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $one = slotPoolSlot($host, $project, $build, 1, ['state' => TaskVmState::Failed]);
    $two = slotPoolSlot($host, $project, $build, 2, ['image_build_id' => null]);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->next($host->id)['slot']->id)->toBe($one->id);
    $one->update(['state' => TaskVmState::Parked]);
    expect($pool->next($host->id))->toBeNull();

    $two->update(['group_id' => slotPoolGroup($project)->id, 'state' => TaskVmState::Failed]);
    expect($pool->next($host->id))->toBeNull();
});

it('claims a free identity of the group\'s Project on a host with a promoted image, and refuses when none is free', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    slotPoolSlot($host, $project, $build, 1, ['state' => TaskVmState::Failed]);
    $current = slotPoolSlot($host, $project, $build, 2);
    $group = slotPoolGroup($project);

    $claimed = app(TaskVmSlotPool::class)->claim($group);
    expect($claimed->id)->toBe($current->id)->and($claimed->group_id)->toBe($group->id)->and($claimed->state)->toBe(TaskVmState::Provisioning);

    expect(fn () => app(TaskVmSlotPool::class)->claim(slotPoolGroup($project)))->toThrow(TaskCapacityException::class);
});

it('leaves the asked number of slots free, as a report-only proof asks', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    slotPoolSlot($host, $project, $build, 1);

    expect(fn () => app(TaskVmSlotPool::class)->claim(slotPoolGroup($project), keepFree: 1))->toThrow(TaskCapacityException::class);
    slotPoolSlot($host, $project, $build, 2);
    expect(app(TaskVmSlotPool::class)->claim(slotPoolGroup($project), keepFree: 1)->slot_number)->toBe(1);
});

it('frees a released slot with its identity, unbinds its workspace and forgets the claim\'s model key', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $group = slotPoolGroup($project);
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast-tvm-1', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.201', 'wireguard_ip' => '10.44.0.201', 'user' => 'orbit']));
    $slot = slotPoolSlot($host, $project, $build, 1, ['group_id' => $group->id, 'state' => TaskVmState::Ready, 'node_id' => $guest->id,
        'model_key' => str_repeat('c', 64), 'wireguard_private_key' => base64_encode(str_repeat("\1", 32))]);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'task-1', 'checkout_path' => '/home/orbit/apps/orbit/workspace',
        'task_workspace_owner_id' => $group->id, 'branch_override' => 'task-1']);

    app(TaskVmSlotReleaseProjection::class)->record($slot, true, null, true);

    expect($slot->refresh()->state)->toBe(TaskVmState::Parked)->and($slot->group_id)->toBeNull()->and($slot->model_key)->toBeNull()
        ->and($slot->wireguard_private_key)->toBe(base64_encode(str_repeat("\1", 32)))
        ->and($instance->refresh()->exists)->toBeTrue()->and($instance->task_workspace_owner_id)->toBeNull();
});

it('makes a slot\'s identity once and keeps it, with the public keys the real tools derive', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $slot = app(TaskVmSlotIdentity::class)->ensure(slotPoolSlot($host, $project, $build, 1));
    $keys = [$slot->wireguard_private_key, $slot->ssh_host_private_key, $slot->agent_secret];

    $again = app(TaskVmSlotIdentity::class)->ensure($slot->refresh());
    expect([$again->wireguard_private_key, $again->ssh_host_private_key, $again->agent_secret])->toBe($keys)
        ->and(TaskVmSlotIdentity::sshPublicKey($keys[1]))->toStartWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5')
        ->and(TaskVmSlotIdentity::sshFingerprint($keys[1]))->toStartWith('SHA256:')
        ->and(strlen(base64_decode(TaskVmSlotIdentity::wireguardPublicKey($keys[0]))))->toBe(32);
});

it('submits an enrol and a release without secrets with an empty secret map, which the workflow takes as an object', function (string $kind, array $input): void {
    [$host, $project, $build] = slotPoolHost(2);
    $slot = slotPoolSlot($host, $project, $build, 1);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Queued, 'input' => $input, 'triggered_by' => 'test']);

    expect(json_encode(app(DirectOperation::class)->workflowInputs($operation)['execution_secrets']))->toBe('{}');
})->with([
    'enrol' => ['task_vm.slot_enrol', ['intent' => ['public_key' => str_repeat('a', 43).'=']]],
    'release' => ['task_vm.slot_release', ['intent' => ['host' => [], 'slot' => []], 'execution_secrets' => []]],
]);

it('takes an identity whose enrol ended without a projection as failed, and waits while its enrol is open', function (): void {
    [$host, $project, $build] = slotPoolHost(1);
    $slot = slotPoolSlot($host, $project, $build, 1, ['state' => TaskVmState::Provisioning]);
    $enrol = Operation::query()->create(['kind' => 'task_vm.slot_enrol', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->next($host->id))->toBeNull();
    // A start the Gateway refused fails the Operation before any run; the identity stays as the enrol left it.
    $enrol->update(['status' => OperationStatus::Failed]);
    expect($pool->next($host->id)['slot']->id)->toBe($slot->id);
});

it('fails the identity of an enrol whose start was refused, without starting the rule at once', function (): void {
    [$host, $project, $build] = slotPoolHost(1);
    $slot = slotPoolSlot($host, $project, $build, 1, ['state' => TaskVmState::Provisioning]);
    $enrol = Operation::query()->create(['kind' => 'task_vm.slot_enrol', 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'error_code' => 'operation.execution_secrets_invalid', 'input' => [], 'triggered_by' => 'test']);

    Queue::fake();
    app(TaskVmSlotEnrolProjection::class)->refused($enrol);

    expect($slot->refresh()->state)->toBe(TaskVmState::Failed)->and($slot->error_code)->toBe('operation.execution_secrets_invalid');
    Queue::assertNothingPushed();
});
