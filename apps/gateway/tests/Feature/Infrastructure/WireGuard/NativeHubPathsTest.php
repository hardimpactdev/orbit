<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\WireGuard\NativeWireGuardPeerConverger;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

/** The older native hub and peer paths of node:wireguard-key and orbit:node-provision (ADR 0251). */
beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/orbit-native-hub-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->home.'/wireguard');
    file_put_contents($this->home.'/wireguard/public.key', base64_encode(str_repeat('h', 32))."\n");
    file_put_contents($this->home.'/wireguard/private.key', base64_encode(str_repeat('k', 32))."\n");
    config(['orbit.home' => $this->home]);
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);
    $this->hub = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'vpn', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '94.237.124.160', 'wireguard_ip' => '10.44.0.1', 'lan_ip' => '10.0.0.2']));
    $this->hub->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
});

afterEach(function (): void {
    File::deleteDirectory($this->home);
});

it('keeps the hub\'s mark in the hub file the native projection loads with wg syncconf', function (): void {
    $config = app(WireGuardServerConfigRenderer::class)->render(app(VpnConfigurationRepository::class)->forPeer($this->hub), collect([$this->hub]));

    expect($config)->toStartWith("[Interface]\nAddress = 10.44.0.1/24\nListenPort = 51820\nFwMark = 51820\nPrivateKey = ");
});

it('refuses to write the hub\'s LAN endpoint on the native peer path, before any Node change', function (): void {
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'app-prod', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => '85.9.211.193', 'wireguard_ip' => '10.44.0.4', 'lan_ip' => '10.0.0.4']));
    $agents = Mockery::mock(AdmissionAgentRunner::class);
    $agents->shouldNotReceive('run');
    $hubs = Mockery::mock(GatewayPeerProjectionManager::class);
    $hubs->shouldNotReceive('converge');
    $converger = new NativeWireGuardPeerConverger(app(VpnConfigurationRepository::class), $hubs, $agents);

    expect(fn () => $converger->converge($node, new SshConnection('10.44.0.4', 'orbit', 22, '/dev/null', '/dev/null')))
        ->toThrow(fn (NodeProvisioningException $refusal) => expect($refusal->errorCode)->toBe('node.hub_lan_unproven'));
});
