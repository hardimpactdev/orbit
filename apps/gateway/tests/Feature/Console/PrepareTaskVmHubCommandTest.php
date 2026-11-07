<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

function hubNode(string $name, string $address, array $roles = [], ?int $cluster = null): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'cluster_id' => $cluster,
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    foreach ($roles as $role) {
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster]);
    }

    return $node;
}

/** The fleet the hub filter reads: vpn, Gateway, swamp, websocket, one Router, and the ai-router's guard applied on `services`. */
function hubWorld(): Cluster
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'dev']);
    config(['task_vms.dev_cluster_id' => $cluster->id]);
    hubNode('vpn', '10.44.0.1', [RoleName::Vpn]);
    hubNode('gateway', '10.44.0.2', [RoleName::Gateway]);
    hubNode('swamp', '10.44.0.15', [RoleName::Swamp]);
    hubNode('router', '10.44.0.7', [RoleName::Router], $cluster->id);
    $services = hubNode('services', '10.44.0.3', [RoleName::WebSocket]);
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, $cluster->id, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')], '/pi', str_repeat('a', 64), ['gpt-6']));
    app(AiRouterState::class)->enable($services->id, 'cache', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $services->id, 'node_id' => $services->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });

    return $cluster;
}

it('lets task VMs reach the model router only through its guard, on port 8318', function (): void {
    hubWorld();

    $this->artisan('task-vms:prepare-hub')->assertSuccessful();

    $intent = Operation::query()->where('kind', 'task_vm.hub_converge')->sole()->input['intent'];
    expect($intent['policy']['model'])->toBe('10.44.0.3:8318')
        ->and($intent['nft'])->toContain('ip saddr 10.44.0.128/25 ip daddr 10.44.0.3 tcp dport 8318 accept')->not->toContain('dport 8317');
});

it('accepts the Node of a live task VM inside the range and refuses any other Node there', function (): void {
    $cluster = hubWorld();
    $host = hubNode('beast', '10.44.0.9', [], $cluster->id);
    $guest = hubNode('beast-tvm-1', '10.44.0.130', [], $cluster->id);
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Large,
        'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.130']);

    $this->artisan('task-vms:prepare-hub')->assertSuccessful();

    hubNode('laptop', '10.44.0.140');
    $this->artisan('task-vms:prepare-hub')->expectsOutputToContain('[task_vm.range_in_use]')->assertFailed();
});
