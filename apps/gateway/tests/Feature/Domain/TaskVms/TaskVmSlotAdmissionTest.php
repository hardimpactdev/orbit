<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
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
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Cluster;
use App\Models\Instance;
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
    // The Swamp vault answers each execution copy as stored; the admission writes the proof club key.
    app()->instance(WebSocketClient::class, new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        private array $frames = [];

        private bool $open = false;

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frames[] = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            $frame = end($this->frames);

            return [['id' => $frame['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    });
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);
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
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public', 'type' => 'monorepo', 'task_workspace_routed' => false]);
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
        $slot = TaskVm::query()->create([...['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
            'size' => TaskVmSize::Large, 'name' => 'tvm-slot-'.$index, 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.20'.$index,
            'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => $build->recipe_identity, 'ready_at' => now()], ...$overrides]);
        if ($index === 0) {
            // The stale slot was enrolled, so only its age keeps it from the claim.
            Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
                'status' => OperationStatus::Succeeded, 'input' => [], 'result' => [], 'finished_at' => now()]);
        }
    }

    $taken = slotAdmissionTakes($group);

    expect($taken['claimed'])->toBe([])->and($taken['cloned'])->toHaveCount(1);
});

it('claims a ready enrolled slot, creates no VM, and starts the provision with the enrol receipt', function (bool $samePins): void {
    [$host, $project, $build, $group] = slotAdmissionWorld(2);
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'tvm-900', 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.190',
        'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => $build->recipe_identity, 'ready_at' => now(),
        'slot_digest' => $samePins ? app(TaskVmSlotPool::class)->releaseDigest() : str_repeat('0', 64)]);
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-900', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.252.7.90', 'wireguard_ip' => '10.44.0.190', 'user' => 'orbit', 'ssh_jump_node_id' => $host->id,
        'ssh_host_fingerprint' => 'SHA256:guest', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'guest-public-key']));
    $slot->update(['node_id' => $guest->id]);
    // The converge of changed pins names the Gateway Node that holds the Pi server.
    Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:gateway', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-public-key']))->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' fixture']);
    $receipt = ['task_vm_id' => $slot->id, 'guest_node_id' => $guest->id, 'ready' => true];
    Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['task_vm_id' => $slot->id, 'name' => $slot->name, 'node_id' => $host->id, 'host_node_id' => $host->id,
            'guest_node_id' => $guest->id, 'provider' => 'incus', 'provider_id' => null, 'wireguard_ip' => $slot->wireguard_ip,
            'image_fingerprint' => $slot->image_fingerprint, 'recipe_identity' => $slot->recipe_identity, 'create' => ['topology' => true], 'converge' => ['x' => 1]],
        'result' => $receipt, 'finished_at' => now()]);

    try {
        app(TaskProvisionIntent::class)->start($group, 'test', null, SlotAdmissionCommit);
    } catch (ResourceOperationException $e) {
        // This fixture answers no Swamp run start; the admission has recorded its Operation by then.
        expect($e->errorCode)->toBe('swamp.start_uncertain');
    }
    $operation = Operation::query()->where('kind', 'tasks.provision')->sole();

    expect($operation->kind)->toBe('tasks.provision')
        ->and($operation->input['slot']['receipt'])->toBe([...$receipt, 'result_json' => json_encode($receipt)])
        ->and($operation->input['converge'] === null)->toBe($samePins)
        ->and(TaskVm::query()->count())->toBe(1)
        ->and($slot->fresh()->group_id)->toBe($group->id);
})->with(['the pins of its enrol' => [true], 'changed pins' => [false]]);

it('captures the Route of a routed workspace served, with the admitted Project facts', function (): void {
    [$host, $project, $build, $group] = slotAdmissionWorld(2);
    // A routed Laravel app in a Cluster whose router is the task VM host.
    $project->update(['type' => 'laravel-app', 'task_workspace_routed' => true, 'php_version' => '8.5', 'is_laravel' => true]);
    $cluster = Cluster::query()->sole();
    $cluster->update(['state' => 'active']);
    Node::withoutEvents(fn () => $host->update(['cluster_id' => $cluster->id]));
    $host->roles()->create(['role' => 'router', 'status' => 'active', 'cluster_id' => $cluster->id]);
    // The Route renders the Orbit root CA; a throwaway one in this test's Orbit home.
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096]);
    $config = tempnam(sys_get_temp_dir(), 'orbit-ca');
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ca]\nbasicConstraints = critical,CA:TRUE\n");
    $root = openssl_csr_sign(openssl_csr_new(['commonName' => 'Orbit test root'], $key), null, $key, 1, ['config' => $config, 'x509_extensions' => 'ca', 'digest_alg' => 'sha256']);
    @mkdir(config('orbit.home').'/ca', 0700, true);
    openssl_x509_export_to_file($root, config('orbit.home').'/ca/root.pem');
    unlink($config);
    $slot = TaskVm::query()->create(['host_node_id' => $host->id, 'slot_project_id' => $project->id, 'provider' => TaskVmProviderKind::Incus,
        'size' => TaskVmSize::Large, 'name' => 'tvm-900', 'state' => TaskVmState::Parked, 'wireguard_ip' => '10.44.0.190',
        'image_build_id' => $build->id, 'image_fingerprint' => str_repeat('b', 64), 'recipe_identity' => $build->recipe_identity, 'ready_at' => now(),
        'slot_digest' => app(TaskVmSlotPool::class)->releaseDigest()]);
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-900', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.252.7.90', 'wireguard_ip' => '10.44.0.190', 'user' => 'orbit', 'ssh_jump_node_id' => $host->id, 'cluster_id' => Cluster::query()->sole()->id,
        'ssh_host_fingerprint' => 'SHA256:guest', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'guest-public-key']));
    $slot->update(['node_id' => $guest->id]);
    $guest->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    // The converge of changed pins names the Gateway Node that holds the Pi server.
    Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:gateway', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-public-key']))->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' fixture']);
    $receipt = ['task_vm_id' => $slot->id, 'guest_node_id' => $guest->id, 'ready' => true];
    Operation::query()->forceCreate(['kind' => TaskVmSlotPool::EnrolKind, 'target_type' => 'task_vm', 'target_id' => $slot->id, 'node_id' => $host->id,
        'status' => OperationStatus::Succeeded, 'input' => ['task_vm_id' => $slot->id, 'name' => $slot->name, 'node_id' => $host->id, 'host_node_id' => $host->id,
            'guest_node_id' => $guest->id, 'provider' => 'incus', 'provider_id' => null, 'wireguard_ip' => $slot->wireguard_ip,
            'image_fingerprint' => $slot->image_fingerprint, 'recipe_identity' => $slot->recipe_identity, 'create' => ['topology' => true], 'converge' => ['x' => 1]],
        'result' => $receipt, 'finished_at' => now()]);

    try {
        app(TaskProvisionIntent::class)->start($group, 'test', null, SlotAdmissionCommit);
    } catch (ResourceOperationException $e) {
        // This fixture answers no Swamp run start; the admission has recorded its Operation by then.
        expect($e->errorCode)->toBe('swamp.start_uncertain');
    }
    $operation = Operation::query()->where('kind', 'tasks.provision')->sole();

    $route = json_encode($operation->input['route'] ?? null, JSON_THROW_ON_ERROR);
    $instance = Instance::query()->findOrFail($operation->input['instance_id']);

    expect($operation->input['route'])->not->toBeNull()
        ->and($route)->not->toContain('Orbit Route unavailable')
        ->and($route)->toContain('reverse_proxy')
        ->and([$instance->selected_php_version, $instance->source_is_laravel])->toBe(['8.5', true]);
    // The workspace step runs the guest's Route access rows before the setup steps; the Route keeps all rows, and skips the guest's.
    $guestRows = array_values(array_filter($operation->input['route']['route_create']['acl'], fn (array $row): bool => $row['node_id'] === $guest->id));
    expect($guestRows)->not->toBeEmpty()
        ->and($operation->input['workspace']['access'])->toBe(array_column($guestRows, 'argv'));
});

it('refuses a routed workspace whose Project source facts are invalid, and records nothing', function (): void {
    [, $project, , $group] = slotAdmissionWorld(null);
    $project->update(['type' => 'laravel-app', 'task_workspace_routed' => true, 'source_profile_error' => 'project.source_profile_unsupported']);

    try {
        app(TaskProvisionIntent::class)->start($group, 'test', null, SlotAdmissionCommit);
        $code = null;
    } catch (ResourceOperationException $e) {
        $code = $e->errorCode;
    }

    expect($code)->toBe('project.source_profile_unsupported')
        ->and(Instance::query()->count())->toBe(0)
        ->and(Operation::query()->where('kind', 'tasks.provision')->count())->toBe(0);
});
