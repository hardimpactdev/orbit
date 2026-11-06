<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmFleetBuildIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token', 'swamp.proof_club_api_key' => 'proof-club-key']);
});

/** A ready task VM with its host and active guest Node. */
function fleetBuildWorld(TaskVmState $state = TaskVmState::Ready, TaskVmSize $size = TaskVmSize::Large): TaskVm
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-554', '10.44.0.131');

    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Refresh', 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace', 'source_layout' => InstanceSourceLayout::Checkout,
        'source_prepare_id' => 'prepare', 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
        'status' => InstanceState::Reserved, 'first_setup_pending' => true]);

    return TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-554',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.131', 'size' => $size]);
}

/** A vault that records its puts. */
function fleetBuildVault(): WebSocketClient
{
    return new class implements WebSocketClient
    {
        /** @var list<array<string,mixed>> */
        public array $frames = [];

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
    };
}

it('renders the whole public intent of the fleet build, with the layout of the nested VMs and the pins of the new Gateway', function (): void {
    $vm = fleetBuildWorld();
    app()->instance(WebSocketClient::class, fleetBuildVault());

    $input = app(TaskVmFleetBuildIntent::class)->capture($vm);

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $vm->node_id, 'operator_checkout' => '/home/orbit/apps/orbit/workspace'])
        ->and($input['fleet'])->toMatchArray(['network' => TaskVmReadyCopiesIntent::Network, 'format' => TaskVmReadyCopiesIntent::Format, 'bridge' => '10.232.2.1/24', 'proof_network' => '10.232.2.0/24'])
        ->and(array_keys($input['fleet']['layout']))->toBe(['gateway', 'app-dev', 'app-prod'])
        ->and($input['fleet']['guests'])->toBe(TaskVmReadyCopiesIntent::Guests)
        ->and($input['execution_secrets']['proof_club'])->toMatchArray(['vault' => 'orbit-control-inputs', 'key' => 'swamp-proof-club-api-key'])
        ->and(json_encode($input))->not->toContain('proof-club-key');
    $members = json_decode('{'.$input['bootstrap']['fragment'].'}', true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($members))->toBe(['swamp', 'gateway', 'operator', 'agent_delivery'])
        ->and($members['swamp']['sha256'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($members['operator']['checkout'])->toBe('/home/orbit/apps/orbit/workspace')
        ->and($members['operator']['wireguard_ip'])->toBe('10.45.0.10')
        ->and($members['gateway']['wireguard_ip'])->toBe('10.45.0.1')
        ->and(json_decode($members['agent_delivery'], true, flags: JSON_THROW_ON_ERROR))->toHaveKeys(['commit', 'version', 'artifacts']);
});

it('gives every nested VM an address inside the subnet of the nested network and a MAC of its own', function (): void {
    $layout = TaskVmFleetBuildIntent::Layout;

    expect(array_unique(array_column($layout, 'address')))->toHaveCount(3)
        ->and(array_unique(array_column($layout, 'hwaddr')))->toHaveCount(3)
        ->and(array_column($layout, 'address'))->each->toStartWith('10.232.2.')
        ->and(array_column($layout, 'wireguard'))->each->toStartWith('10.45.0.');
});

it('renders the intent of a VM that is still being provisioned, for the refresh that admits it before the VM exists', function (): void {
    $vm = fleetBuildWorld(TaskVmState::Provisioning);
    app()->instance(WebSocketClient::class, fleetBuildVault());

    $input = app(PendingSshHostKey::class)->during((int) $vm->node_id, fn (): array => app(TaskVmFleetBuildIntent::class)->provisioning($vm, '/home/orbit/apps/orbit/workspace'));

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $vm->node_id, 'operator_checkout' => '/home/orbit/apps/orbit/workspace'])
        ->and($input)->toHaveKeys(['ssh_execution', 'fleet', 'bootstrap'])
        ->and($input['ssh_execution']['target']['host_keys'])->toBeNull()
        ->and($input['execution_secrets']['proof_club'])->toMatchArray(['vault' => 'orbit-control-inputs', 'key' => 'swamp-proof-club-api-key'])
        ->and(json_encode($input))->not->toContain('proof-club-key');
});

it('refuses a VM that is not large, because the nested VMs hold more memory than a medium VM has', function (): void {
    $memory = static fn (string $gib): int => (int) $gib;
    $nested = array_sum(array_map(static fn (array $node): int => $memory($node['memory']), TaskVmFleetBuildIntent::Layout));
    expect($memory(TaskVmSize::Large->resources()->memory))->toBeGreaterThan($nested)
        ->and($memory(TaskVmSize::Medium->resources()->memory))->toBeLessThanOrEqual($nested);
    app()->instance(WebSocketClient::class, fleetBuildVault());

    $vm = fleetBuildWorld();
    foreach ([TaskVmSize::Normal, TaskVmSize::Medium] as $size) {
        $vm->update(['size' => $size]);
        expect(fn () => app(TaskVmFleetBuildIntent::class)->capture($vm))->toThrow(
            fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.fleet_build_refused'),
        );
    }

    $vm->update(['state' => TaskVmState::Provisioning, 'size' => TaskVmSize::Medium]);
    expect(fn () => app(TaskVmFleetBuildIntent::class)->provisioning($vm, '/home/orbit/apps/orbit/workspace'))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.fleet_build_refused'),
    );
});

it('refuses a VM that is not ready', function (): void {
    $vm = fleetBuildWorld(TaskVmState::Provisioning);

    expect(fn () => app(TaskVmFleetBuildIntent::class)->capture($vm))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.fleet_build_refused'),
    );
});

it('renders the mount units of the shared checkout under the names the stage 2 apply step starts, as network mounts', function (): void {
    $vm = fleetBuildWorld();
    app()->instance(WebSocketClient::class, fleetBuildVault());

    $checkout = app(TaskVmFleetBuildIntent::class)->capture($vm)['fleet']['checkout'];

    expect($checkout['server'])->toBe('10.232.2.1')
        ->and($checkout['binds']['app-dev'])->toBe(['home-orbit-orbit-apps-gateway-.env.mount', 'home-orbit-orbit-apps-gateway-storage.mount', 'home-orbit-orbit-apps-gateway-bootstrap-cache.mount'])
        ->and($checkout['binds']['gateway'])->toContain('home-orbit-orbit-swamp-.swamp.mount', 'home-orbit-orbit-swamp-vaults.mount');
    $units = collect($checkout['units'])->groupBy('guest');
    expect($units['nested-gateway'])->toHaveCount(7)
        ->and($units['nested-app-dev'])->toHaveCount(4);
    foreach ($checkout['units'] as $unit) {
        expect($unit['content'])->toContain('_netdev', "[Install]\nWantedBy=multi-user.target\n");
    }
    $nfs = collect($checkout['units'])->firstWhere('name', 'home-orbit-orbit.mount');
    expect($nfs['content'])->toContain("What=10.232.2.1:/srv/checkout\n", "Where=/home/orbit/orbit\n", "Type=nfs\n");
});

it('names no workspace path in the mount units, so the ready copies serve a task VM of any Project', function (): void {
    $vm = fleetBuildWorld();
    app()->instance(WebSocketClient::class, fleetBuildVault());
    Instance::query()->where('task_workspace_owner_id', $vm->group_id)->update(['checkout_path' => '/home/orbit/apps/swamp-proof/workspace']);

    $input = app(TaskVmFleetBuildIntent::class)->capture($vm);

    expect($input['operator_checkout'])->toBe('/home/orbit/apps/swamp-proof/workspace');
    foreach ($input['fleet']['checkout']['units'] as $unit) {
        expect($unit['content'])->not->toContain('/home/orbit/apps/');
    }
});

it('mounts the path where the task VM role exports every checkout', function (): void {
    $role = Yaml::parseFile(base_path('../../swamp/ansible/task-vm-role.yml'))[0];
    $nfs = Yaml::parseFile(base_path('../../swamp/ansible/tasks/task-vm-nfs.yml'));
    $task = fn (string $name): array => collect($nfs)->firstWhere('name', $name);
    $unit = str_replace('/', '-', ltrim(TaskVmFleetBuildIntent::ExportPath, '/')).'.mount';

    expect($role['vars']['nfs_export'])->toBe(TaskVmFleetBuildIntent::ExportPath)
        ->and($task('Bind the checkout at the export path')['ansible.builtin.copy']['dest'])->toBe('/etc/systemd/system/'.$unit)
        ->and($task('Mount the checkout at the export path')['ansible.builtin.systemd']['name'])->toBe($unit)
        ->and($task('Make the checkout folder')['ansible.builtin.file']['path'])->toBe('{{ intent.nfs.checkout }}')
        ->and($task('Bind the checkout at the export path')['ansible.builtin.copy']['content'])->toContain("What={{ intent.nfs.checkout }}\n", "Where={{ nfs_export }}\n", "Options=bind\n")
        ->and($task('Export the checkout to the nested subnet')['ansible.builtin.copy']['content'])->toStartWith('{{ nfs_export }} ');
});
