<?php

declare(strict_types=1);

use App\Domain\Nodes\NativeNodeEnrollmentInput;
use App\Domain\Nodes\NodeWireGuardHubInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** The hub's wg-quick file that the enrollment of a Node writes. */
function enrollmentHubFile(Node $node): array
{
    $network = (new ReflectionMethod(NativeNodeEnrollmentInput::class, 'network'))->invoke(app(NativeNodeEnrollmentInput::class), $node, false);

    return $network;
}

it('writes the hub file the hub converge writes, so a peer that has only its peer file survives a restart of the hub', function (): void {
    $hub = SwampNodeFixture::ensure();
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);

    $network = enrollmentHubFile($hub->refresh());
    $file = collect($network['files'])->firstWhere('path', '/etc/wireguard/orbit.conf');

    expect($network['is_hub'])->toBeTrue()
        ->and($file['content'])->toBe(NodeWireGuardHubInput::config($hub))
        ->and($file['content'])->toContain('wg addconf %i')->not->toContain('[Peer]');
});

it('rewrites the hub file of a joining Node as the hub converge does, and puts that Node in its own peer file', function (): void {
    $home = sys_get_temp_dir().'/orbit-hub-file-'.bin2hex(random_bytes(4));
    mkdir($home.'/wireguard', 0700, true);
    file_put_contents($home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    config(['orbit.home' => $home]);
    $hub = SwampNodeFixture::ensure();
    $hub->forceFill(['ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key'])->saveQuietly();
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'joining', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.30', 'user' => 'orbit',
        'status' => LifecycleStatus::Provisioning, 'platform' => 'linux', 'architecture' => 'x86_64',
        'ssh_host_fingerprint' => 'SHA256:joining', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'joining-public-key']));

    $network = enrollmentHubFile($node);
    $files = collect($network['hub_files'])->keyBy('path');

    expect($files['/etc/wireguard/orbit.conf']['content'])->toBe(NodeWireGuardHubInput::config($hub->refresh()))
        ->and($files[NodeWireGuardHubInput::peerFile($node->id)]['content'])->toContain('10.44.0.30/32');
});
