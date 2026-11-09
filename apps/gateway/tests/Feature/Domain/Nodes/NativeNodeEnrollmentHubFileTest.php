<?php

declare(strict_types=1);

use App\Domain\Nodes\NativeNodeEnrollmentInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** @return array<string,mixed> the network part of a Node's enrollment intent */
function enrollmentNetwork(Node $node): array
{
    return (new ReflectionMethod(NativeNodeEnrollmentInput::class, 'network'))->invoke(app(NativeNodeEnrollmentInput::class), $node, false);
}

/** A hub that already has one keyed peer. */
function hubWithPeer(): array
{
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key'])->saveQuietly();
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $peer = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'peer', 'public_ssh_host' => '192.0.2.8', 'wireguard_ip' => '10.44.0.31', 'user' => 'orbit',
        'wireguard_public_key' => base64_encode(str_repeat('p', 32)), 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));

    return [$hub->refresh(), $peer];
}

it('writes the hub file the hub converge writes, and keeps the hub\'s peers out of it', function (): void {
    [$hub, $peer] = hubWithPeer();

    $network = enrollmentNetwork($hub);
    $file = collect($network['files'])->firstWhere('path', '/etc/wireguard/orbit.conf');

    expect($network['is_hub'])->toBeTrue()
        ->and($file['content'])->toBe(NodeWireGuardHubInput::config($hub))
        ->and($file['content'])->toContain('wg addconf %i')->not->toContain($peer->wireguard_public_key)
        // The hub loads the peer files it has at apply time: the run writes each recorded peer's file and carries no snapshot to load.
        ->and(collect($network['files'])->firstWhere('path', NodeWireGuardHubInput::peerFile($peer->id))['content'])->toContain($peer->wireguard_public_key)
        ->and($network['sync_body'])->toBe('')->and($network['port'])->toBe(NodeWireGuardHubInput::intent($hub)['port']);
});

it('rewrites the hub file of a joining Node as the hub converge does, and gives every peer its own file', function (): void {
    [$hub, $peer] = hubWithPeer();
    $home = sys_get_temp_dir().'/orbit-hub-file-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'joining', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.30', 'user' => 'orbit',
        'status' => LifecycleStatus::Provisioning, 'platform' => 'linux', 'architecture' => 'x86_64',
        'ssh_host_fingerprint' => 'SHA256:joining', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'joining-public-key']));

    try {
        $network = enrollmentNetwork($node);
        $files = collect($network['hub_files'])->keyBy('path');
    } finally {
        File::deleteDirectory($home);
    }

    // The hub loads the peer files it has at apply time, so the run carries the port and no snapshot of the peers.
    expect($network['hubs'][0])->toHaveKey('port', NodeWireGuardHubInput::intent($hub)['port'])->not->toHaveKey('sync_body')
        ->and($files['/etc/wireguard/orbit.conf']['content'])->toBe(NodeWireGuardHubInput::config($hub))
        ->and($files[NodeWireGuardHubInput::peerFile($node->id)]['content'])->toContain('10.44.0.30/32')
        ->and($files[NodeWireGuardHubInput::peerFile($peer->id)]['content'])->toContain($peer->wireguard_public_key);
});

/** The enrollment network of a Node joining the hub with this LAN address, against a hub on 10.0.0.2. */
function joiningOnLan(?string $lan): array
{
    [$hub] = hubWithPeer();
    $hub->forceFill(['public_ssh_host' => '94.237.124.160', 'lan_ip' => '10.0.0.2'])->saveQuietly();
    $home = sys_get_temp_dir().'/orbit-hub-lan-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home.'/wireguard');
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'joining', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.30', 'lan_ip' => $lan, 'user' => 'orbit',
        'status' => LifecycleStatus::Provisioning, 'platform' => 'linux', 'architecture' => 'x86_64',
        'ssh_host_fingerprint' => 'SHA256:joining', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'joining-public-key']));

    try {
        return enrollmentNetwork($node);
    } finally {
        File::deleteDirectory($home);
    }
}

it('gives a Node on the hub\'s private network the hub\'s LAN endpoint and the addresses it must prove first', function (): void {
    $network = joiningOnLan('10.0.0.4');

    expect(collect($network['files'])->firstWhere('path', '/etc/wireguard/orbit.conf')['content'])->toContain("Endpoint = 10.0.0.2:51820\n")
        ->and($network['sync_body'])->toContain("Endpoint = 10.0.0.2:51820\n")
        ->and($network['hub_lan'])->toBe(['address' => '10.0.0.2', 'source' => '10.0.0.4']);
});

it('keeps the public hub endpoint for a Node whose LAN is another private network', function (?string $lan): void {
    $network = joiningOnLan($lan);

    expect($network['sync_body'])->toContain("Endpoint = 94.237.124.160:51820\n")
        ->and($network['hub_lan'])->toBeNull();
})->with(['home LAN' => '192.168.6.20', 'no LAN' => null]);

it('carries the hub\'s mark in every syncconf on the hub, so a joining peer never clears it', function (): void {
    $joining = joiningOnLan('10.0.0.4');
    $hub = Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn))->sole();

    expect(enrollmentNetwork($hub)['sync_body'])->toStartWith("ListenPort = 51820\nFwMark = 51820\n\n")
        ->and($joining['hubs'][0]['sync_body'])->toStartWith("ListenPort = 51820\nFwMark = 51820\n\n");
});
