<?php

declare(strict_types=1);

use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Nodes\RoleName;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

const ContainerHost = ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 1]];

/** A Node that may call the API about itself, with `$settings` stored. */
function containerSettingsNode(string $name = 'beast', string $ip = '10.44.0.10', ?array $settings = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'settings' => $settings,
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    return $node;
}

function containerSettingsPatch(Node $node, ?array $taskVms): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => $taskVms]);
}

function liveContainer(Node $host, TaskVmRuntime $runtime = TaskVmRuntime::Container, TaskVmState $state = TaskVmState::Ready): TaskVm
{
    return TaskVm::query()->create(['host_node_id' => $host->id, 'provider' => TaskVmProviderKind::Incus, 'runtime' => $runtime, 'name' => $host->name.'-tvm-1',
        'wireguard_ip' => '10.44.0.150', 'state' => $state, 'capacity_pool' => 'task', 'size' => TaskVmSize::Large]);
}

it('stores the containers member with its defaults, and a host without it keeps its shape', function (): void {
    $node = containerSettingsNode();

    containerSettingsPatch($node, ContainerHost)->assertOk()
        ->assertJsonPath('data.settings.task_vms.containers', ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 1, 'project' => 'orbit-task-containers', 'network' => 'orbittaskc0']);
    $host = TaskVmHost::fromConfig([...$node->refresh()->settings['task_vms'], 'node_id' => $node->id], 0, Ipv4Subnet::from('10.44.0.0/24'), []);
    expect($host->containers?->project)->toBe('orbit-task-containers')->and($host->places(TaskVmRuntime::Container, false))->toBe(1)
        ->and($host->places(TaskVmRuntime::Vm, false))->toBe(2)->and($host->bridges())->toBe(['10.252.10.0/24', '10.252.11.0/24']);

    $vmOnly = array_diff_key(ContainerHost, ['containers' => true]);
    containerSettingsPatch($node, $vmOnly)->assertOk()->assertJsonMissingPath('data.settings.task_vms.containers');
    expect($node->refresh()->settings['task_vms'])->not->toHaveKey('containers')
        ->and(TaskVmHost::fromConfig([...$vmOnly, 'node_id' => $node->id], 0, Ipv4Subnet::from('10.44.0.0/24'), config('task_vms.incus.host_defaults'))->places(TaskVmRuntime::Container, true))->toBe(0);
});

it('validates the containers member like the VM fields, apart from the host\'s VM resources', function (array $containers, string $message): void {
    $value = [...ContainerHost, 'containers' => $containers];
    expect(fn () => NodeTaskVmsData::validated($value, Ipv4Subnet::from('10.44.0.0/24')))->toThrow(TaskVmException::class, $message);
})->with([
    'the VM project' => [[...ContainerHost['containers'], 'project' => 'orbit-tasks'], 'containers.project must differ'],
    'the VM bridge name' => [[...ContainerHost['containers'], 'network' => 'orbittask0'], 'containers.network must differ'],
    'inside the VM bridge' => [[...ContainerHost['containers'], 'cidr' => '10.252.10.128/25'], 'containers.cidr must not overlap'],
    'around the VM bridge' => [[...ContainerHost['containers'], 'cidr' => '10.252.0.0/16'], 'containers.cidr must not overlap'],
    'the VPN subnet' => [[...ContainerHost['containers'], 'cidr' => '10.44.0.0/24'], 'containers.cidr must not overlap the VPN subnet'],
    'a public network' => [[...ContainerHost['containers'], 'cidr' => '8.8.8.0/24'], 'containers.cidr must be a private'],
    'an invalid bridge name' => [[...ContainerHost['containers'], 'network' => 'br0'], 'containers.network is invalid'],
    'no task places' => [['cidr' => '10.252.11.0/24', 'ci_places' => 1], 'containers.task_places'],
    'too many CI places' => [[...ContainerHost['containers'], 'ci_places' => 65], 'containers.ci_places'],
    'places as a string' => [[...ContainerHost['containers'], 'task_places' => '1'], 'containers.task_places'],
    'an unknown field' => [[...ContainerHost['containers'], 'image_alias' => 'base'], 'containers must be an object'],
]);

it('refuses a containers member that is not an object', function (mixed $containers): void {
    expect(fn () => NodeTaskVmsData::validated([...ContainerHost, 'containers' => $containers], Ipv4Subnet::from('10.44.0.0/24')))
        ->toThrow(TaskVmException::class, 'containers must be an object');
})->with([null, 'orbittaskc0', 3]);

it('refuses an invalid containers member through the API and keeps the stored settings', function (): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);
    $stored = $node->settings;

    containerSettingsPatch($node, [...ContainerHost, 'containers' => [...ContainerHost['containers'], 'network' => 'orbittask0']])
        ->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
    expect($node->refresh()->settings)->toBe($stored);
});

it('refuses a bridge of either runtime that overlaps a bridge of another host', function (array $mine, array $theirs): void {
    containerSettingsNode('shark', '10.44.0.11', ['task_vms' => $theirs]);
    $node = containerSettingsNode();

    containerSettingsPatch($node, $mine)->assertStatus(422)->assertJsonPath('error.code', 'node.settings_invalid');
    expect($node->refresh()->settings)->toBeNull();
})->with([
    'my container bridge, their VM bridge' => [ContainerHost, ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 0]],
    'my VM bridge, their container bridge' => [ContainerHost, ['cidr' => '10.252.20.0/24', 'task_places' => 1, 'ci_places' => 0, 'containers' => ['cidr' => '10.252.10.0/24', 'task_places' => 1, 'ci_places' => 0]]],
    'both container bridges' => [ContainerHost, ['cidr' => '10.252.20.0/24', 'task_places' => 1, 'ci_places' => 0, 'containers' => ['cidr' => '10.252.11.0/25', 'task_places' => 1, 'ci_places' => 0]]],
]);

it('fails the task VM settings when stored container bridges of two hosts overlap', function (): void {
    containerSettingsNode('shark', '10.44.0.11', ['task_vms' => ['cidr' => '10.252.20.0/24', 'task_places' => 1, 'ci_places' => 0, 'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 0]]]);
    containerSettingsNode(settings: ['task_vms' => ContainerHost]);

    expect(fn () => TaskVmSettings::fromConfig())->toThrow(TaskVmException::class, 'task_vms host bridges must not overlap.');
});

it('keeps the container project, bridge and network of a host while it has a live task container', function (?array $next): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);
    $stored = $node->settings;
    $container = liveContainer($node);

    containerSettingsPatch($node, $next)->assertStatus(409)->assertJsonPath('error.code', 'task_vm.runtime_in_use');
    expect($node->refresh()->settings)->toBe($stored);

    // A destroyed container holds nothing. Removing the whole host withdraws its input rules on the Node, which this fixture cannot reach.
    $container->update(['state' => TaskVmState::Destroyed]);
    expect(containerSettingsPatch($node, $next)->json('error.code'))->not->toBe('task_vm.runtime_in_use');
})->with([
    'the member removed' => [array_diff_key(ContainerHost, ['containers' => true])],
    'the whole host removed' => [null],
    'another project' => [[...ContainerHost, 'containers' => [...ContainerHost['containers'], 'project' => 'orbit-task-containers-2']]],
    'another bridge name' => [[...ContainerHost, 'containers' => [...ContainerHost['containers'], 'network' => 'orbittaskc1']]],
    'another bridge network' => [[...ContainerHost, 'containers' => [...ContainerHost['containers'], 'cidr' => '10.252.12.0/24']]],
]);

it('lets the container places change while task containers live, and a live task VM never holds the container side', function (): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);
    liveContainer($node);

    containerSettingsPatch($node, [...ContainerHost, 'containers' => [...ContainerHost['containers'], 'task_places' => 0, 'ci_places' => 3]])
        ->assertOk()->assertJsonPath('data.settings.task_vms.containers.ci_places', 3);

    TaskVm::query()->update(['runtime' => TaskVmRuntime::Vm]);
    containerSettingsPatch($node, array_diff_key(ContainerHost, ['containers' => true]))->assertOk();
    expect($node->refresh()->settings['task_vms'])->not->toHaveKey('containers');
});
