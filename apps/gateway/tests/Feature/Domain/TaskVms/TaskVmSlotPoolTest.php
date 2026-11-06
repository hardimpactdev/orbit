<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\NodeSettingsParser;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotEnrolProjection;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotSetting;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Support\SwampNodeFixture;

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
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64)]]]);

    slotPoolSeedRefreshed($host, $build);

    return [$host, $project, $build];
}

/** The succeeded refresh that promoted `$build` on `$host` with ready copies, so its seed is usable; `$seed` keep saves none. */
function slotPoolSeedRefreshed(Node $host, TaskVmImageBuild $build, ?string $seed = null): Operation
{
    return Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['refresh' => ['host_node_id' => $host->id, 'seed' => $seed,
            'ready_copies' => ['fleet' => ['format' => TaskVmReadyCopiesIntent::Format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $host->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
}

/** A slot row of the host: by default a fresh ready slot of the current image. */
function slotPoolSlot(Node $host, Project $project, TaskVmImageBuild $build, array $overrides = []): TaskVm
{
    static $next = 1;
    $id = $next++;

    return TaskVm::query()->create([...['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'tvm-slot-'.$id, 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.'.(200 + $id),
        'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => 'recipe-1', 'ready_at' => now()], ...$overrides]);
}

/** The succeeded enrol Operation of a slot, as its claim reads it. */
function slotPoolEnrol(TaskVm $slot, array $result = []): Operation
{
    return Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $slot->host_node_id,
        'status' => OperationStatus::Succeeded, 'input' => ['task_vm_id' => $slot->id, 'name' => $slot->name, 'converge' => ['x' => 1]],
        'result' => $result, 'finished_at' => now()]);
}

it('keeps no pool on a host without the slots setting', function (): void {
    [$host] = slotPoolHost(null);

    expect(app(TaskVmSlotPool::class)->next($host->id))->toBeNull();
});

it('enrols while the pool is below its count, and waits while a slot enrols', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->next($host->id))->toBe(['enrol', null]);

    $enrolling = slotPoolSlot($host, $project, $build, ['state' => TaskVmState::Provisioning]);
    expect($pool->next($host->id))->toBeNull();

    $enrolling->update(['state' => TaskVmState::Parked]);
    expect($pool->next($host->id))->toBe(['enrol', null]);

    slotPoolSlot($host, $project, $build);
    expect($pool->next($host->id))->toBeNull();
});

it('destroys a failed slot first, and holds the enrol for 15 minutes after a failed enrol', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $failed = slotPoolSlot($host, $project, $build, ['state' => TaskVmState::Failed]);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->next($host->id)[0])->toBe('destroy')->and($pool->next($host->id)[1]->id)->toBe($failed->id);

    $failed->update(['state' => TaskVmState::Destroyed]);
    Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $failed->id, 'node_id' => $host->id,
        'status' => OperationStatus::Failed, 'input' => [], 'finished_at' => now()->subMinutes(5)]);
    expect($pool->next($host->id))->toBeNull();

    Operation::query()->where('kind', TaskVmSlotPool::EnrolKind)->update(['finished_at' => now()->subMinutes(16)]);
    expect($pool->next($host->id))->toBe(['enrol', null]);
});

it('replaces one stale slot at a time once the pool is full', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $old = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-0', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now()->subDay(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('c', 64)]]]);
    $stale = slotPoolSlot($host, $project, $old);
    slotPoolSlot($host, $project, $build);
    $pool = app(TaskVmSlotPool::class);

    expect($pool->next($host->id)[0])->toBe('destroy')->and($pool->next($host->id)[1]->id)->toBe($stale->id);

    $stale->update(['state' => TaskVmState::Destroying]);
    expect($pool->next($host->id))->toBeNull();
});

it('counts a slot stale by image, age, Project and size', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    $pool = app(TaskVmSlotPool::class);
    $setting = app(TaskVmSettings::class)->host($host->id)->slots;
    $current = ['build_id' => $build->id, 'fingerprint' => str_repeat('b', 64), 'recipe_identity' => 'recipe-1'];

    expect($pool->stale(slotPoolSlot($host, $project, $build), $setting, $current))->toBeFalse()
        ->and($pool->stale(slotPoolSlot($host, $project, $build, ['ready_at' => now()->subHours(21)]), $setting, $current))->toBeTrue()
        ->and($pool->stale(slotPoolSlot($host, $project, $build, ['size' => TaskVmSize::Medium]), $setting, $current))->toBeTrue()
        ->and($pool->stale(slotPoolSlot($host, $project, $build), $setting, [...$current, 'build_id' => $build->id + 1]))->toBeTrue()
        ->and($pool->stale(slotPoolSlot($host, $project, $build), null, $current))->toBeTrue()
        ->and($pool->stale(slotPoolSlot($host, $project, $build), $setting, null))->toBeFalse();
});

it('empties the pool when the setting goes, one slot at a time', function (): void {
    [$host, $project, $build] = slotPoolHost(null);
    $slot = slotPoolSlot($host, $project, $build);

    expect(app(TaskVmSlotPool::class)->next($host->id)[1]->id)->toBe($slot->id);
});

it('keeps at most as many slots as the host can run at once', function (): void {
    [$host, $project, $build] = slotPoolHost(5, maxVms: 6);
    slotPoolSlot($host, $project, $build);
    slotPoolSlot($host, $project, $build);

    expect(app(TaskVmSlotPool::class)->next($host->id))->toBeNull();
});

it('refills beside a live task, into idle capacity only', function (): void {
    [$host] = slotPoolHost(2, maxVms: 6);
    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'tvm-busy-0', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.249']);
    expect(app(TaskVmSlotPool::class)->next($host->id))->toBe(['enrol', null]);

    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
        'name' => 'tvm-busy-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.250']);
    expect(app(TaskVmSlotPool::class)->next($host->id))->toBeNull();
});

it('waits for capacity to enrol, and a ready slot takes none', function (): void {
    [$host, $project, $build] = slotPoolHost(3, maxVms: 12);
    $capacity = app(TaskVmCapacity::class);
    $hosts = $capacity->activeHosts();

    slotPoolSlot($host, $project, $build);
    slotPoolSlot($host, $project, $build);
    expect($capacity->freeSlots($hosts)[$host->id])->toBe(12);

    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'tvm-busy', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.250']);
    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
        'name' => 'tvm-busy-2', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.251']);
    expect($capacity->freeSlots($hosts)[$host->id])->toBe(8)
        ->and(app(TaskVmSlotPool::class)->next($host->id))->toBe(['enrol', null]);

    foreach ([3, 4] as $n) {
        TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
            'name' => 'tvm-busy-'.$n.'0', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.24'.$n]);
    }
    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'tvm-busy-5', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.247']);
    expect($capacity->freeSlots($hosts)[$host->id])->toBe(3)
        ->and(app(TaskVmSlotPool::class)->next($host->id))->toBe(['enrol', null]);
});

it('keeps ready slots out of the stray records of the host sweep', function (): void {
    [$host, $project, $build] = slotPoolHost(2);
    slotPoolSlot($host, $project, $build, ['updated_at' => now()->subDay()]);
    $stray = TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
        'name' => 'tvm-stray', 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.252']);
    TaskVm::query()->whereKey($stray->id)->update(['updated_at' => now()->subDay()]);

    expect(array_map(static fn (TaskVm $vm): int => $vm->id, TaskVmHostAudit::strayRecords($host)))->toBe([$stray->id]);
});

it('reads the slots setting of a host and rejects a bad one', function (): void {
    $vpn = Ipv4Subnet::from('10.44.0.0/16');
    $entry = ['node_id' => 9, 'project' => 'orbit-tasks', 'network' => 'orbittask0', 'cidr' => '10.252.7.0/24', 'max_vms' => 6, 'cpus' => 4, 'memory' => '8GiB', 'disk' => '20GiB', 'pool' => 'orbit-tasks'];

    expect(TaskVmHost::fromConfig($entry, 0, $vpn, [])->slots)->toBeNull();
    $slots = TaskVmHost::fromConfig([...$entry, 'slots' => ['count' => 5, 'project_id' => 46, 'size' => 'large']], 0, $vpn, [])->slots;
    expect($slots?->toArray())->toBe(['count' => 5, 'project_id' => 46, 'size' => 'large']);

    expect(fn () => TaskVmHost::fromConfig([...$entry, 'slots' => []], 0, $vpn, []))->toThrow(TaskVmException::class);
    expect(fn () => TaskVmHost::fromConfig([...$entry, 'slots' => ['count' => 0, 'project_id' => 46, 'size' => 'large']], 0, $vpn, []))
        ->toThrow(TaskVmException::class)
        ->and(fn () => TaskVmHost::fromConfig([...$entry, 'slots' => ['count' => 5, 'project_id' => 46, 'size' => 'huge']], 0, $vpn, []))
        ->toThrow(TaskVmException::class);
});

it('takes no lock and starts nothing on a host without a pool', function (): void {
    [$host] = slotPoolHost(null);

    expect(app(TaskVmSlotPool::class)->refill($host->id))->toBeNull()
        ->and(Operation::query()->where('kind', TaskVmSlotPool::EnrolKind)->count())->toBe(0);
});

it('fails an interrupted or cancelled enrol, so the refill rule destroys it', function (string $status): void {
    [$host, $project, $build] = slotPoolHost(2);
    $slot = slotPoolSlot($host, $project, $build, ['state' => TaskVmState::Provisioning]);
    $operation = Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id,
        'node_id' => $host->id, 'status' => OperationStatus::Running, 'input' => ['task_vm_id' => $slot->id, 'node_id' => $host->id, 'host_node_id' => $host->id,
            'name' => $slot->name, 'provider' => 'incus', 'wireguard_ip' => $slot->wireguard_ip, 'image_fingerprint' => $slot->image_fingerprint,
            'recipe_identity' => $slot->recipe_identity], 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => 'fixture-slot-enrol']]);
    Bus::fake();

    app(TaskVmSlotEnrolProjection::class)->apply($operation, ['run_id' => 'fixture-slot-enrol', 'status' => $status, 'error_code' => null, 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => []]);

    expect($slot->fresh()->state)->toBe(TaskVmState::Failed)
        ->and(app(TaskVmSlotPool::class)->next($host->id)[1]->id)->toBe($slot->id);
    Bus::assertDispatched(RefillTaskVmSlots::class);
})->with(['interrupted', 'cancelled']);

it('reads the slots setting from a node update body', function (): void {
    $body = json_decode('{"cidr": "10.252.7.0/24", "max_vms": 6, "pool": "orbit-tasks", "slots": {"count": 5, "project_id": 46, "size": "large"}}', flags: JSON_THROW_ON_ERROR);

    $settings = app(NodeSettingsParser::class)->parseComplete((object) ['task_vms' => $body]);

    expect($settings?->task_vms?->slots)->toBe(['count' => 5, 'project_id' => 46, 'size' => 'large']);
});

it('registers the operator command that runs the refill rule', function (): void {
    expect(array_keys(Artisan::all()))->toContain('task-vms:fill-slots');
});

it('claims the oldest fitting enrolled slot for a group, and none of another Project, a stale one, one with no enrol, or another recipe', function (): void {
    [$host, $project, $build] = slotPoolHost(3);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, app(TaskVmSettings::class)->devClusterId, '10.44.0.128/25',
        app(TaskVmSettings::class)->hosts, 'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6'], largeProjectIds: [$project->id]));
    $other = Project::query()->create(['name' => 'other', 'slug' => 'other', 'repository_url' => 'https://example.test/other.git', 'default_branch' => 'main', 'root' => 'public']);
    slotPoolSlot($host, $other, $build);
    slotPoolSlot($host, $project, $build, ['ready_at' => now()->subHours(21)]);
    slotPoolSlot($host, $project, $build);
    $fit = slotPoolSlot($host, $project, $build);
    slotPoolEnrol($fit);
    slotPoolEnrol(slotPoolSlot($host, $project, $build));
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
    Bus::fake();

    $claimed = app(TaskVmSlotPool::class)->claim($group);

    expect($claimed?->id)->toBe($fit->id)->and($claimed->group_id)->toBe($group->id)->and($claimed->claimed_at)->not->toBeNull()
        ->and(app(TaskVmSlotPool::class)->claim($group))->toBeNull();
    Bus::assertDispatched(RefillTaskVmSlots::class);

    $current = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo, 'current_recipe' => true]);
    expect(app(TaskVmSlotPool::class)->claim($current))->toBeNull();
});

it('gives a claimed slot the intent and the receipt of its enrol', function (): void {
    SwampNodeFixture::ensure();
    [$host, $project, $build] = slotPoolHost(2);
    $slot = slotPoolSlot($host, $project, $build);
    $receipt = ['task_vm_id' => $slot->id, 'guest_node_id' => 77, 'ready' => true, 'provider' => ['running' => true], 'enrollment' => ['ready' => true]];
    slotPoolEnrol($slot, [...$receipt, 'image' => ['fallback' => false]]);

    $input = app(TaskVmSlotPool::class)->claimInput($slot);

    expect($input)->not->toHaveKey('converge')->and($input['name'])->toBe($slot->name)->and($input['host_ssh_execution']['target']['host'])->toBe('10.44.0.7')
        ->and($input['slot']['receipt'])->toBe([...$receipt, 'result_json' => json_encode($receipt)]);
});

it('waits, and claims nothing, while the image seed holds no ready copies', function (): void {
    [$host, $project] = slotPoolHost(2);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, app(TaskVmSettings::class)->devClusterId, '10.44.0.128/25',
        app(TaskVmSettings::class)->hosts, 'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6'], largeProjectIds: [$project->id]));
    // A refresh with seed=keep promotes a newer image and saves no ready copies for it.
    $kept = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now()->addMinute(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('d', 64)]]]);
    slotPoolSeedRefreshed($host, $kept, 'keep');
    slotPoolEnrol(slotPoolSlot($host, $project, $kept));
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);
    Bus::fake();

    expect(app(TaskVmSlotPool::class)->next($host->id))->toBeNull()
        ->and(app(TaskVmSlotPool::class)->claim($group))->toBeNull();

    slotPoolSeedRefreshed($host, $kept);
    expect(app(TaskVmSlotPool::class)->claim($group))->not->toBeNull();
});
