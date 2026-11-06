<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\NodeAgentSshExecutor;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('starts the agent after the WireGuard tunnel so its first report finds the Gateway', function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2',
        'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nfixture\n-----END CERTIFICATE-----\n"));

    $input = app(NodeAgentSshExecutor::class)->configurationForRoot(new Node(['platform' => 'linux', 'wireguard_ip' => '10.44.0.7']), null);

    expect($input['configuration'])->toContain('gateway_address = "10.44.0.2"')
        ->and(explode("\n", $input['unit']))
        ->toContain('After=network-online.target wg-quick@orbit.service')
        ->toContain('Wants=network-online.target')
        ->not->toContain('Wants=wg-quick@orbit.service');
});
