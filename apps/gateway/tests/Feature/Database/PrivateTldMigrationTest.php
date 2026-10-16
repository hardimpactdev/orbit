<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Cluster;
use App\Models\Node;

function private_tld_migration(): object
{
    return require base_path('database/migrations/2026_11_13_000000_refuse_private_cluster_and_node_tlds.php');
}

function private_tld_node(string $name, string $tld): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "{$name}.example.test",
        'tld' => $tld,
    ]);
}

it('passes when no TLD covers private names', function (): void {
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    private_tld_node('beast', 'test');
    private_tld_node('app-dev', 'app-dev.test');
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'VPN.CORP.');
    private_tld_node('unrelated', 'notvpn.corp');

    private_tld_migration()->up();

    expect(Cluster::query()->sole()->tld)->toBe('test');
});

it('refuses to upgrade over a stored TLD that covers private names', function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'corp');
    Cluster::query()->create(['name' => 'lab', 'tld' => 'orbit', 'state' => ClusterState::Inactive]);
    private_tld_node('beast', 'test');
    private_tld_node('corp-node', 'corp');

    expect(fn () => private_tld_migration()->up())
        ->toThrow(RuntimeException::class, 'Cluster [lab] has the TLD [orbit]; Node [corp-node] has the TLD [corp]. A TLD may not cover private names. Change it on the current release, then deploy again.');
});

it('refuses stored Cluster and Node ancestors of the VPN domain without changing them', function (string $domain): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: $domain);
    $cluster = Cluster::query()->create(['name' => 'lab', 'tld' => 'corp', 'state' => ClusterState::Inactive]);
    $node = private_tld_node('corp-node', 'corp');

    expect(fn () => private_tld_migration()->up())
        ->toThrow(RuntimeException::class, 'Cluster [lab] has the TLD [corp]; Node [corp-node] has the TLD [corp]. A TLD may not cover private names.');

    expect($cluster->refresh()->tld)->toBe('corp')
        ->and($node->refresh()->tld)->toBe('corp');
})->with(['vpn.corp', 'VPN.CORP.', 'deep.vpn.corp.']);

it('refuses stored Node descendants without changing them', function (string $tld): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', domain: 'VPN.CORP.');
    $node = private_tld_node('overlap', $tld);

    expect(fn () => private_tld_migration()->up())
        ->toThrow(RuntimeException::class, "Node [overlap] has the TLD [{$tld}]");

    expect($node->refresh()->tld)->toBe($tld);
})->with(['beast.orbit', 'n1.vpn.corp', 'vpn.corp']);
