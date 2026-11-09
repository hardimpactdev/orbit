<?php

declare(strict_types=1);

use App\Domain\WireGuard\HubEndpoint;
use App\Domain\WireGuard\LanAddress;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function endpointNode(int $id, ?string $lan): Node
{
    return (new Node)->forceFill(['id' => $id, 'name' => "node-{$id}", 'public_ssh_host' => '94.237.124.160', 'lan_ip' => $lan]);
}

it('uses the hub\'s LAN endpoint only for a Node in the hub\'s recorded /24', function (?string $hubLan, ?string $nodeLan, string $endpoint): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820, endpoint: '94.237.124.160:51820');

    $resolved = HubEndpoint::for(endpointNode(4, $nodeLan), endpointNode(1, $hubLan), app(VpnSettings::class));

    expect($resolved->endpoint)->toBe($endpoint)
        ->and($resolved->lan)->toBe($endpoint === '10.0.0.2:51820' ? ['address' => '10.0.0.2', 'source' => $nodeLan] : null);
})->with([
    'same data centre' => ['10.0.0.2', '10.0.0.4', '10.0.0.2:51820'],
    'last address of the /24' => ['10.0.0.2', '10.0.0.255', '10.0.0.2:51820'],
    'next /24' => ['10.0.0.2', '10.0.1.4', '94.237.124.160:51820'],
    'home LAN' => ['10.0.0.2', '192.168.6.20', '94.237.124.160:51820'],
    'no Node LAN' => ['10.0.0.2', null, '94.237.124.160:51820'],
    'no hub LAN' => [null, '10.0.0.4', '94.237.124.160:51820'],
]);

it('never gives the hub its own LAN endpoint, and keeps one LAN rule for Process Routes and the hub', function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', port: 51820);
    $hub = endpointNode(1, '10.0.0.2');

    expect(HubEndpoint::for($hub, $hub, app(VpnSettings::class))->endpoint)->toBe('94.237.124.160:51820')
        ->and(LanAddress::sameNetwork('10.0.0.2', '10.0.0.4'))->toBeTrue()
        ->and(LanAddress::sameNetwork('10.0.0.2', 'fd00::2'))->toBeFalse();
});
