<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotSetting;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const SlotAdmissionCommit = 'ffffffffffff0000000000000000000000000000';

beforeEach(function (): void {
    // A release layout with the recipe the clone allocation reads.
    $this->base = sys_get_temp_dir().'/orbit-slot-admission-'.bin2hex(random_bytes(4));
    $release = $this->base.'/releases/'.substr(SlotAdmissionCommit, 0, 12);
    mkdir($release.'/apps/gateway/resources/machine-software', 0700, true);
    file_put_contents($release.'/REVISION', SlotAdmissionCommit."\n");
    symlink('releases/'.substr(SlotAdmissionCommit, 0, 12), $this->base.'/orbit');
    copy(base_path('resources/machine-software/recipe.json'), $release.'/apps/gateway/resources/machine-software/recipe.json');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway', 'swamp.proof_club_api_key' => 'club-key']);
    app(TaskExtensionState::class)->enable();
    SwampNodeFixture::ensure();
    Bus::fake();
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/**
 * A large-VM Project, one task VM host with its promoted image of the current recipe and a pool of `$slots` slots,
 * and a `todo` group of the Project.
 *
 * @return array{Node, Project, TaskVmImageBuild, Task}
 */
function slotAdmissionWorld(?int $slots): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB',
            slots: $slots === null ? null : new TaskVmSlotSetting($slots, $project->id, TaskVmSize::Large))],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6'], largeProjectIds: [$project->id]));
    $recipe = app(RecipeImageBuilds::class)->currentRecipe()->identity;
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => $recipe, 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64)]]]);
    // The seed of the image holds ready copies, which a large clone needs.
    Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['refresh' => ['host_node_id' => $host->id, 'ready_copies' => ['fleet' => ['format' => TaskVmReadyCopiesIntent::Format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $host->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Task', 'brief' => 'Brief', 'status' => TaskGroupStatus::Todo]);

    return [$host, $project, $build, $group];
}

/**
 * Runs the provision admission of `$group` until a later step stops it (this fixture has no model key or guest Node),
 * and returns the VM it took: a ready slot it claimed, or the new row of a clone.
 *
 * @return array{claimed: list<int>, cloned: list<int>}
 */
function slotAdmissionTakes(Task $group): array
{
    $taken = ['claimed' => [], 'cloned' => []];
    TaskVm::created(function (TaskVm $vm) use (&$taken): void {
        $taken['cloned'][] = $vm->id;
    });
    TaskVm::updated(function (TaskVm $vm) use (&$taken, $group): void {
        if ($vm->wasChanged('group_id') && $vm->group_id === $group->id && $vm->slot_project_id !== null) {
            $taken['claimed'][] = $vm->id;
        }
    });
    try {
        app(TaskProvisionIntent::class)->start($group, 'test', null, SlotAdmissionCommit);
    } catch (Throwable) {
        // A step after the choice of the VM.
    }

    return $taken;
}

it('clones a new VM when the host keeps no ready slot', function (): void {
    [, , , $group] = slotAdmissionWorld(null);

    expect(slotAdmissionTakes($group))->toBe(['claimed' => [], 'cloned' => [1]]);
});

it('clones a new VM when every ready slot is stale or never enrolled', function (): void {
    [$host, $project, $build, $group] = slotAdmissionWorld(2);
    foreach ([['ready_at' => now()->subHours(21)], []] as $index => $overrides) {
        TaskVm::query()->create([...['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
            'size' => TaskVmSize::Large, 'name' => 'tvm-slot-'.$index, 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.20'.$index,
            'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => $build->recipe_identity, 'ready_at' => now()], ...$overrides]);
    }

    $taken = slotAdmissionTakes($group);

    expect($taken['claimed'])->toBe([])->and($taken['cloned'])->toHaveCount(1);
});

it('claims a ready enrolled slot and creates no VM', function (): void {
    [$host, $project, $build, $group] = slotAdmissionWorld(2);
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'tvm-slot', 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.209',
        'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => $build->recipe_identity, 'ready_at' => now()]);
    Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['task_vm_id' => $slot->id, 'name' => $slot->name], 'result' => ['task_vm_id' => $slot->id], 'finished_at' => now()]);

    expect(slotAdmissionTakes($group))->toBe(['claimed' => [$slot->id], 'cloned' => []]);
});
