<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmFleetBuildIntent;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmReadyCopiesIntent;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

/** A ready task VM with its host and active guest Node. */
function fleetBuildWorld(TaskVmState $state = TaskVmState::Ready): TaskVm
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $guest = $node('tvm-554', '10.44.0.131');

    return TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-554',
        'state' => $state, 'address' => '10.251.78.33', 'wireguard_ip' => '10.44.0.131', 'size' => TaskVmSize::Normal]);
}

it('renders the whole public intent of the fleet build, with the layout of the nested VMs and the pins of the new Gateway', function (): void {
    $vm = fleetBuildWorld();

    $input = app(TaskVmFleetBuildIntent::class)->capture($vm);

    expect($input)->toMatchArray(['task_vm_id' => $vm->id, 'node_id' => $vm->host_node_id, 'guest_node_id' => $vm->node_id])
        ->and($input['fleet'])->toMatchArray(['network' => TaskVmReadyCopiesIntent::Network, 'format' => TaskVmReadyCopiesIntent::Format, 'bridge' => '10.232.2.1/24'])
        ->and(array_keys($input['fleet']['layout']))->toBe(['gateway', 'app-dev', 'app-prod'])
        ->and($input['fleet']['guests'])->toBe(TaskVmReadyCopiesIntent::Guests);
    $members = json_decode('{'.$input['bootstrap']['fragment'].'}', true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($members))->toBe(['swamp', 'gateway', 'operator', 'agent_delivery'])
        ->and($members['swamp']['sha256'])->toMatch('/^[a-f0-9]{64}$/')
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

it('refuses a VM that is not ready', function (): void {
    $vm = fleetBuildWorld(TaskVmState::Provisioning);

    expect(fn () => app(TaskVmFleetBuildIntent::class)->capture($vm))->toThrow(
        fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.fleet_build_refused'),
    );
});
