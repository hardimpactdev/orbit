<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageRefreshIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(LazilyRefreshDatabase::class);

const ImageRefreshCommit = 'dddddddddddd0000000000000000000000000000';
const ImageRefreshImage = 'c1c2cf4051fb0123456789abcdef0123456789abcdef0123456789abcdef0123';

beforeEach(function (): void {
    // A release layout whose current release is ImageRefreshCommit.
    $this->base = sys_get_temp_dir().'/orbit-image-refresh-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/releases/'.substr(ImageRefreshCommit, 0, 12).'/apps/gateway', 0700, true);
    file_put_contents($this->base.'/releases/'.substr(ImageRefreshCommit, 0, 12).'/REVISION', ImageRefreshCommit."\n");
    symlink('releases/'.substr(ImageRefreshCommit, 0, 12), $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
});

afterEach(function (): void {
    Process::run(['rm', '-rf', $this->base]);
});

/** A configured task VM host with a promoted image; 6 slots hold a refresh VM and one more large task VM. */
function imageRefreshHost(int $maxVms = 6): Node
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', $maxVms, 4, '8GiB', '40GiB')],
        'http://10.44.0.3:8317', '/pi', str_repeat('a', 64), ['gpt-6']));
    TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-1', 'state' => TaskVmImageBuildState::Succeeded,
        'promoted_at' => now(), 'images' => ['base' => ['project' => 'orbit-tasks', 'type' => 'virtual-machine', 'fingerprint' => ImageRefreshImage]]]);

    return $host;
}

function imageRefreshRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $error) {
        return $error->errorCode;
    }

    throw new RuntimeException('The call was not refused.');
}

it('refuses a host that cannot refresh before it creates a group', function (): void {
    $host = imageRefreshHost();
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'plain', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'orbit']));
    $intent = app(TaskVmImageRefreshIntent::class);

    expect(imageRefreshRefusal(fn () => $intent->start($other, 'test', null)))->toBe('task_vm.host_unavailable');

    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'recipe-2', 'state' => TaskVmImageBuildState::Running]);
    expect(imageRefreshRefusal(fn () => $intent->start($host, 'test', null)))->toBe('task_vm.image_build_running');
    $build->delete();

    TaskVmImageBuild::query()->where('host_node_id', $host->id)->delete();
    expect(imageRefreshRefusal(fn () => $intent->start($host, 'test', null)))->toBe('task_vm.image_unavailable');
    expect(Task::topLevel()->count())->toBe(0);
});

it('refuses a refresh when the Gateway release has no commit, before it creates a group', function (): void {
    $host = imageRefreshHost();
    unlink($this->base.'/orbit');

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', null)))->toBe('task_vm.image_refresh_unavailable');
    expect(Task::topLevel()->count())->toBe(0);
});

it('returns the open refresh of a host for a second admission, and none once it ended', function (): void {
    $host = imageRefreshHost();
    $operation = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $host->id,
        'status' => OperationStatus::Running, 'input' => ['refresh' => ['host_node_id' => $host->id]], 'triggered_by' => 'test']);

    expect(TaskVmImageRefreshIntent::open($host)?->id)->toBe($operation->id);
    expect(app(TaskVmImageRefreshIntent::class)->start($host, 'test', null)->id)->toBe($operation->id);
    expect(Operation::query()->where('kind', 'task_vm.image_refresh')->count())->toBe(1);

    $operation->update(['status' => OperationStatus::Succeeded]);
    expect(TaskVmImageRefreshIntent::open($host))->toBeNull();
});

it('places the VM of a refresh in a large size that the admission sets, whatever the Project size lists say', function (): void {
    $host = imageRefreshHost();
    // The recipe of the release the layout names; the allocation records its identity on the VM.
    $recipe = $this->base.'/orbit/apps/gateway/resources/machine-software';
    mkdir($recipe, 0700, true);
    copy(base_path('resources/machine-software/recipe.json'), $recipe.'/recipe.json');
    // The refresh runs in the orbit Project (46), which a size list could make medium.
    $project = Project::query()->forceCreate(['id' => 46, 'name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    config(['task_vms.medium_projects' => [$project->id], 'task_vms.large_projects' => []]);
    app(TaskExtensionState::class)->enable();
    config(['swamp.proof_club_api_key' => 'club-key']);

    // The admission allocates the VM, then asks for a model key, which this fixture does not configure and which rolls the transaction back; the size is read as the VM is created.
    $sizes = [];
    TaskVm::created(function (TaskVm $vm) use (&$sizes): void {
        $sizes[] = $vm->size;
    });

    try {
        app(TaskVmImageRefreshIntent::class)->start($host, 'test', null);
    } catch (TaskVmException) {
        // The model key step, after the allocation.
    }

    expect($sizes)->toBe([TaskVmSize::Large]);
});

it('refuses a seed that is neither build nor keep, before it creates a group', function (): void {
    $host = imageRefreshHost();

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', null, 'both')))->toBe('task_vm.seed_invalid');
    expect(Task::topLevel()->count())->toBe(0);
});

it('names the seed of an image from its fingerprint, so the refreshed image has a seed of its own', function (): void {
    expect(TaskVmVolumes::seed(ImageRefreshImage))->toBe('orbit-topology-seed-c1c2cf4051fb')
        ->and(TaskVmVolumes::SeedPrefix.substr(ImageRefreshImage, 0, 12))->toBe(TaskVmVolumes::seed(ImageRefreshImage));
});

it('refuses a refresh while its host has no room for one more large task VM, before it creates a group', function (): void {
    // Proofs and tasks come first: with one slot taken, 6 slots hold the refresh VM (3) but not a large task VM after it.
    $host = imageRefreshHost();
    TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
        'name' => 'tvm-busy-0', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.249']);

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', null)))->toBe('tasks.capacity_unavailable');
    expect(Task::topLevel()->count())->toBe(0);
});

it('keeps the reserved slots of a host free under the placement lock', function (): void {
    $host = imageRefreshHost(maxVms: 5);
    $recipe = $this->base.'/orbit/apps/gateway/resources/machine-software';
    mkdir($recipe, 0700, true);
    copy(base_path('resources/machine-software/recipe.json'), $recipe.'/recipe.json');
    $project = Project::query()->forceCreate(['id' => 62, 'name' => 'swamp-proof', 'slug' => 'swamp-proof', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Refresh', 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
    $allocate = app(AllocateTaskVmAction::class);

    // A large VM fits the 5 free slots, but not with 3 more kept free.
    expect(fn () => $allocate->capture($group, $host->id, TaskVmSize::Large, 3))->toThrow(TaskCapacityException::class);
    expect(TaskVm::query()->count())->toBe(0);
    expect($allocate->capture($group, $host->id, TaskVmSize::Large)->size)->toBe(TaskVmSize::Large);
});

it('refuses a host that can never hold the refresh VM and one more large task VM, instead of letting it wait', function (): void {
    $host = imageRefreshHost(maxVms: 5);

    expect(imageRefreshRefusal(fn () => app(TaskVmImageRefreshIntent::class)->start($host, 'test', null)))->toBe('task_vm.host_unavailable');
    expect(Task::topLevel()->count())->toBe(0);
});
