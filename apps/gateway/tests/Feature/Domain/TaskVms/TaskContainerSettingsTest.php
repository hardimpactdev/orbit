<?php

declare(strict_types=1);

use App\Actions\Nodes\UpdateNodeAction;
use App\Data\Nodes\NodeSettingsData;
use App\Data\Nodes\NodeTaskVmsData;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const ContainerHost = ['cidr' => '10.252.10.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => ['cidr' => '10.252.11.0/24', 'task_places' => 1, 'ci_places' => 1]];

/** A Node that may call the API about itself, with `$settings` stored. */
function containerSettingsNode(string $name = 'beast', string $ip = '10.44.0.10', ?array $settings = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'settings' => $settings,
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    return $node;
}

function containerSettingsPatch(Node $node, ?array $taskVms): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])->patchJson("/api/v1/nodes/{$node->id}", ['task_vms' => $taskVms]);
}

/**
 * Admits a settings update that withdraws input rules, inside a transaction that keeps the after-commit Swamp dispatch out of the test.
 * The settings stay as they were until the withdrawal succeeds.
 */
function containerSettingsWithdrawal(Node $node, ?array $taskVms): Operation
{
    SwampNodeFixture::ensure();
    DB::beginTransaction();
    $operation = app(UpdateNodeAction::class)->execute($node, null, null, new NodeSettingsPatch(false, null, true, $taskVms === null ? null : NodeTaskVmsData::from($taskVms)))->result;
    expect($operation)->toBeInstanceOf(Operation::class);

    return $operation;
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
    // Removing the member withdraws the container bridge's input rules first; the settings are saved when that succeeds.
    $operation = containerSettingsWithdrawal($node->refresh(), $vmOnly);
    expect($operation->input['task_host_input_comments'])->toBe(NodeFirewallRuleCatalog::TaskContainerInputComments)
        ->and($operation->input['settings']['task_vms'])->not->toHaveKey('containers')
        ->and($node->refresh()->settings['task_vms'])->toHaveKey('containers')
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

it('reads the container placement switch from the Gateway config, off by default', function (bool $on): void {
    containerSettingsNode(settings: ['task_vms' => ContainerHost]);
    expect(TaskVmSettings::fromConfig()->containerPlacement)->toBeFalse();

    config(['task_vms.container_placement' => $on]);
    expect(TaskVmSettings::fromConfig()->containerPlacement)->toBe($on);
})->with([true, false]);

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

it('keeps the container side when node:add enrolls an existing host again', function (): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);
    liveContainer($node);
    $without = new NodeSettingsData(task_vms: NodeTaskVmsData::validated(array_diff_key(ContainerHost, ['containers' => true]), Ipv4Subnet::from('10.44.0.0/24')));
    $action = app(UpdateNodeAction::class);

    foreach ([fn () => $action->enrollmentIntent($node, $without), fn () => $action->persistDuringProvisioning($node, $without)] as $call) {
        expect($call)->toThrow(fn (ResourceOperationException $exception) => expect([$exception->errorCode, $exception->status])->toBe(['task_vm.runtime_in_use', 409]));
    }
});

it('lets the container places change while task containers live, and a live task VM never holds the container side', function (): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);
    liveContainer($node);

    containerSettingsPatch($node, [...ContainerHost, 'containers' => [...ContainerHost['containers'], 'task_places' => 0, 'ci_places' => 3]])
        ->assertOk()->assertJsonPath('data.settings.task_vms.containers.ci_places', 3);

    TaskVm::query()->update(['runtime' => TaskVmRuntime::Vm]);
    $operation = containerSettingsWithdrawal($node->refresh(), array_diff_key(ContainerHost, ['containers' => true]));
    expect($operation->input['settings']['task_vms'])->not->toHaveKey('containers');
});

it('withdraws only the input rules of the bridge that goes or moves', function (?array $next, array $comments): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);

    expect(containerSettingsWithdrawal($node, $next)->input['task_host_input_comments'])->toBe($comments);
})->with([
    'the container bridge moves' => [[...ContainerHost, 'containers' => [...ContainerHost['containers'], 'cidr' => '10.252.12.0/24']], NodeFirewallRuleCatalog::TaskContainerInputComments],
    'the container bridge is renamed' => [[...ContainerHost, 'containers' => [...ContainerHost['containers'], 'network' => 'orbittaskc1']], NodeFirewallRuleCatalog::TaskContainerInputComments],
    'the VM bridge moves' => [[...ContainerHost, 'cidr' => '10.252.13.0/24'], NodeFirewallRuleCatalog::TaskHostInputComments],
    'the host goes' => [null, [...NodeFirewallRuleCatalog::TaskHostInputComments, ...NodeFirewallRuleCatalog::TaskContainerInputComments]],
]);

it('withdraws nothing when only the container places change', function (): void {
    $node = containerSettingsNode(settings: ['task_vms' => NodeTaskVmsData::validated(ContainerHost, Ipv4Subnet::from('10.44.0.0/24'))->toArray()]);

    containerSettingsPatch($node, [...ContainerHost, 'containers' => [...ContainerHost['containers'], 'task_places' => 3]])->assertOk();
    expect(Operation::query()->where('kind', 'node.update')->exists())->toBeFalse();
});
