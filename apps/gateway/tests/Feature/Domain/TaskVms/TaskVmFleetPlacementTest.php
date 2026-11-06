<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\TaskVms\TaskVmFleetSeed;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

const FleetPlacementCommit = 'eeeeeeeeeeee0000000000000000000000000000';

/** The Projects whose VMs are large or medium, so they need the nested fleet, and the Project whose VMs are plain. */
const FleetPlacementFleetProject = 101;
const FleetPlacementPlainProject = 102;
const FleetPlacementMediumProject = 103;

beforeEach(function (): void {
    // A release layout whose current release is FleetPlacementCommit, with the recipe the allocation reads.
    $this->base = sys_get_temp_dir().'/orbit-fleet-placement-'.bin2hex(random_bytes(4));
    $release = $this->base.'/releases/'.substr(FleetPlacementCommit, 0, 12);
    mkdir($release.'/apps/gateway/resources/machine-software', 0700, true);
    file_put_contents($release.'/REVISION', FleetPlacementCommit."\n");
    symlink('releases/'.substr(FleetPlacementCommit, 0, 12), $this->base.'/orbit');
    copy(base_path('resources/machine-software/recipe.json'), $release.'/apps/gateway/resources/machine-software/recipe.json');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/**
 * Two task VM hosts that both serve a promoted image: `beast` first in config order, with the given free slots of `$max`, and `shark`.
 * Neither seed holds ready copies until `fleetSeedRefreshed()` records a refresh.
 *
 * @return array{Node, Node, TaskVmImageBuild, TaskVmImageBuild}
 */
function fleetPlacementHosts(int $beastMax = 6, int $sharkMax = 12): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $nodes = [];
    $builds = [];
    $hosts = [];
    foreach (['beast' => [$beastMax, '10.44.0.7', '10.252.7.0/24'], 'shark' => [$sharkMax, '10.44.0.11', '10.251.78.0/24']] as $name => [$max, $address, $cidr]) {
        $nodes[$name] = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit']));
        $hosts[] = new TaskVmHost($nodes[$name]->id, 'orbit-tasks', 'orbittask0', $cidr, $max, 4, '8GiB', '20GiB');
        $builds[$name] = TaskVmImageBuild::query()->create(['host_node_id' => $nodes[$name]->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
            'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => str_repeat($name === 'beast' ? 'b' : 'c', 64)]]]);
    }
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25', $hosts, 'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6'],
        mediumProjectIds: [FleetPlacementMediumProject], largeProjectIds: [FleetPlacementFleetProject]));

    return [$nodes['beast'], $nodes['shark'], $builds['beast'], $builds['shark']];
}

/** The succeeded refresh run that promoted `$build` on `$host`, with the ready copies of `$format` in its intent. */
function fleetSeedRefreshed(Node $host, TaskVmImageBuild $build, string $format = TaskVmReadyCopiesIntent::Format): Operation
{
    return Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['refresh' => ['host_node_id' => $host->id, 'ready_copies' => ['fleet' => ['format' => $format]]]],
        'result' => ['refreshed' => true, 'host_node_id' => $host->id, 'build_id' => $build->id], 'triggered_by' => 'test']);
}

/** A group of the Project `$id`: large, medium or plain by the size lists of the settings. */
function fleetPlacementGroup(int $id): Task
{
    $project = Project::query()->forceCreate(['id' => $id, 'name' => 'p'.$id, 'slug' => 'p'.$id, 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);

    return Task::query()->create(['project_id' => $project->id, 'title' => 'Group', 'brief' => 'Brief', 'status' => 'todo']);
}

it('reads a seed as usable only when the latest refresh of the host promoted that image and saved copies of the current format', function (): void {
    [$beast, $shark, $beastBuild, $sharkBuild] = fleetPlacementHosts();
    $seeds = app(TaskVmFleetSeed::class);

    expect($seeds->usable($beast->id, $beastBuild->id))->toBeFalse()->and($seeds->usable($shark->id, $sharkBuild->id))->toBeFalse();

    fleetSeedRefreshed($shark, $sharkBuild);
    fleetSeedRefreshed($beast, $beastBuild, 'old-format');

    expect($seeds->usable($shark->id, $sharkBuild->id))->toBeTrue()->and($seeds->usable($beast->id, $beastBuild->id))->toBeFalse();

    // Another image, such as one that a plain build promoted, has a seed with only the Node template.
    $later = TaskVmImageBuild::query()->create(['host_node_id' => $shark->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now()->addMinute(), 'images' => $sharkBuild->images]);

    expect($seeds->usable($shark->id, $later->id))->toBeFalse();
});

it('never reads a seed as usable from a refresh with seed=keep, which saved no copies', function (): void {
    [$beast, , $beastBuild] = fleetPlacementHosts();
    $seeds = app(TaskVmFleetSeed::class);

    // The input of a keep run holds the ready copies intent like any other, but the run skipped the save.
    $keep = fleetSeedRefreshed($beast, $beastBuild);
    $keep->update(['input' => ['refresh' => [...$keep->input['refresh'], 'seed' => 'keep']]]);

    expect($seeds->usable($beast->id, $beastBuild->id))->toBeFalse();

    $keep->update(['input' => ['refresh' => [...$keep->input['refresh'], 'seed' => 'build']]]);

    expect($seeds->usable($beast->id, $beastBuild->id))->toBeTrue();
});

it('places a VM that needs the nested fleet on the host with usable copies, though the other host has as many free slots', function (): void {
    // Both hosts have as many free slots, so the tie goes to the first in config order, beast, unless its seed is skipped.
    [$beast, $shark, $beastBuild, $sharkBuild] = fleetPlacementHosts(beastMax: 6, sharkMax: 6);
    fleetSeedRefreshed($shark, $sharkBuild);

    $vm = app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject));

    expect($vm->size)->toBe(TaskVmSize::Large)->and($vm->host_node_id)->toBe($shark->id);
});

it('filters a medium VM too, because it holds a test node', function (): void {
    [$beast, $shark, $beastBuild, $sharkBuild] = fleetPlacementHosts(beastMax: 6, sharkMax: 6);
    fleetSeedRefreshed($shark, $sharkBuild);

    $vm = app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementMediumProject));

    expect($vm->size)->toBe(TaskVmSize::Medium)->and($vm->host_node_id)->toBe($shark->id);
});

it('answers capacity when no host with usable copies has room for a VM that needs the fleet', function (): void {
    fleetPlacementHosts();

    expect(fn () => app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject)))
        ->toThrow(TaskCapacityException::class, 'Hosts with room but no usable nested fleet seed are skipped: beast, shark.');
});

it('names only the hosts that have room, not a full host', function (): void {
    // Beast has 2 slots, too few for a large VM, so it is full for this VM whatever its seed holds.
    fleetPlacementHosts(beastMax: 2, sharkMax: 12);

    expect(fn () => app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject)))
        ->toThrow(TaskCapacityException::class, 'are skipped: shark.');
});

it('reads the seed of the image that placement picks, the one of the current recipe, not the latest promoted', function (): void {
    [$beast, $shark, $beastBuild, $sharkBuild] = fleetPlacementHosts(beastMax: 2, sharkMax: 12);
    // Shark serves the build of the current recipe, promoted first and with no copies, while an older recipe's build, promoted later, has them.
    $sharkBuild->update(['recipe_identity' => app(RecipeImageBuilds::class)->currentRecipe()->identity, 'promoted_at' => now()->subHour()]);
    $older = TaskVmImageBuild::query()->create(['host_node_id' => $shark->id, 'recipe_identity' => 'recipe-old', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => $sharkBuild->images]);
    fleetSeedRefreshed($shark, $older);

    expect(fn () => app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject)))
        ->toThrow(TaskCapacityException::class, 'are skipped: shark.');
});

it('still places a plain task on a host without usable copies', function (): void {
    // Shark holds the copies but has fewer free slots, so the plain task goes to beast, which holds none.
    [$beast, $shark, , $sharkBuild] = fleetPlacementHosts(beastMax: 6, sharkMax: 3);
    fleetSeedRefreshed($shark, $sharkBuild);

    $vm = app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementPlainProject));

    expect($vm->size)->toBe(TaskVmSize::Normal)->and($vm->host_node_id)->toBe($beast->id);
});

it('does not filter a VM that a host refresh pins to its host, because it builds the copies', function (): void {
    [$beast] = fleetPlacementHosts();

    $vm = app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject), $beast->id, TaskVmSize::Large);

    expect($vm->host_node_id)->toBe($beast->id);
});

it('keeps the slots of one large VM free from a report-only proof, so a critical-path run still finds room', function (): void {
    // Shark holds 6 slots, two large VMs. A report-only proof leaves one large VM's slots free.
    [, $shark, , $sharkBuild] = fleetPlacementHosts(beastMax: 2, sharkMax: 6);
    fleetSeedRefreshed($shark, $sharkBuild);
    $reserve = TaskVmPlacementPolicy::reportOnlyReserve();

    $first = app(AllocateTaskVmAction::class)->capture(fleetPlacementGroup(FleetPlacementFleetProject), reserveSlots: $reserve);

    expect($reserve)->toBe(3)->and($first->host_node_id)->toBe($shark->id);

    // The second report-only proof would take the reserved slots: it yields.
    $secondGroup = Task::query()->create(['project_id' => FleetPlacementFleetProject, 'title' => 'Second', 'brief' => 'Brief', 'status' => 'todo']);

    expect(fn () => app(AllocateTaskVmAction::class)->capture($secondGroup, reserveSlots: $reserve))
        ->toThrow(TaskCapacityException::class, 'A report-only proof leaves 3 slots free for task and proof work.');

    // A run without the flag takes the reserved slots.
    $critical = app(AllocateTaskVmAction::class)->capture($secondGroup);

    expect($critical->host_node_id)->toBe($shark->id);
});

it('never places a report-only proof on a host that cannot hold a large VM beside the reserve, but still places a run without the flag', function (): void {
    // Beast holds 6 slots: one large VM (3) plus the reserve (3) is the whole host. A host of 5 slots cannot take a report-only large VM.
    [$beast, , $beastBuild] = fleetPlacementHosts(beastMax: 5, sharkMax: 0);
    fleetSeedRefreshed($beast, $beastBuild);

    $group = fleetPlacementGroup(FleetPlacementFleetProject);

    expect(fn () => app(AllocateTaskVmAction::class)->capture($group, reserveSlots: TaskVmPlacementPolicy::reportOnlyReserve()))
        ->toThrow(TaskCapacityException::class, 'A report-only proof leaves 3 slots free');

    $critical = app(AllocateTaskVmAction::class)->capture($group);

    expect($critical->host_node_id)->toBe($beast->id);
});

it('never overflows a report-only proof to UpCloud', function (): void {
    $group = fleetPlacementGroup(FleetPlacementFleetProject);
    $policy = new TaskVmPlacementPolicy(app(TaskVmSettings::class));

    // No host has a free slot. Without the reserve the policy may go to UpCloud when the rules allow it; with it, it waits.
    expect(fn () => $policy->choose(TaskVmSize::Large, $group, [], TaskVmPlacementPolicy::reportOnlyReserve()))
        ->toThrow(TaskCapacityException::class, 'A report-only proof leaves 3 slots free');
});
