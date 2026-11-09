<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('takes a removed Node off the hub by deleting its own file, also when the Gateway never recorded its key', function (): void {
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'tvm-1', 'public_ssh_host' => '10.44.0.140', 'wireguard_ip' => '10.44.0.140', 'wireguard_public_key' => null,
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));

    // The intent carries the public key of the Gateway.
    $keys = app(SshKeyProvider::class);
    @mkdir(dirname($keys->privateKeyPath()), 0o700, true);
    file_put_contents($keys->privateKeyPath().'.pub', 'ssh-ed25519 AAAAtest gateway');

    $intent = app(NodeRemovalInput::class)->capture(['node_id' => $node->id, 'name' => $node->name, 'task_vm_id' => null, 'offline' => true, 'machine_deleted' => true,
        'prior_status' => 'active', 'route_ids' => []]);

    expect($intent['intent']['task_host_input_comments'])->toBe(['orbit:task-vms-dhcp', 'orbit:task-vms-dns-tcp', 'orbit:task-vms-dns-udp',
        'orbit:task-containers-dhcp', 'orbit:task-containers-dns-tcp', 'orbit:task-containers-dns-udp']);
    $hub = $intent['intent']['hub'];

    expect($hub)->toHaveCount(1)
        ->and($hub[0]['peers'])->toBe([])
        ->and($hub[0]['prune_argv'])->toBe(['rm', '-f', '--', "/etc/wireguard/orbit-peers.d/{$node->id}.conf"]);
});
