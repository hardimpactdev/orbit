<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterDnsEvidence;
use App\Domain\Clusters\ClusterDnsTransition;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\FactDoctor;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeFact;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

function clusterDnsMember(string $name, string $address, ?Cluster $cluster = null, ?string $lan = null): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'cluster_id' => $cluster?->id,
        'lan_ip' => $lan, 'wireguard_public_key' => $name.'-key', 'ssh_host_fingerprint' => 'SHA256:'.$name,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIPlaceholderPlaceholderPlaceholderPlaceholderAA',
    ]));
}

it('keeps canonical VPN answers and preserves foreign nested namespaces in the LAN projection', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $foreign = clusterDnsMember('foreign', '10.44.0.8');
    $foreign->update(['tld' => 'foreign.test']);
    $foreign->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $renderer = app(DevelopmentDnsConfigRenderer::class);

    expect($renderer->state()->tlds['test'])->toBe('10.44.0.7')
        ->and($renderer->routerState($cluster, $router)->tlds)->toBe([
            'foreign.test' => '10.44.0.8', 'test' => '192.168.6.20',
        ]);
});

it('selects the Router for a roleless managed LAN member and keeps a foreign namespace on VPN', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $foreign = clusterDnsMember('foreign', '10.44.0.8');
    $foreign->update(['tld' => 'foreign.test']);
    $foreign->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    $policy = app(PeerDnsPolicy::class);
    $selection = $policy->selection($member);

    expect($policy->manages($member))->toBeTrue()
        ->and($selection['delegate'])->toMatchArray(['server' => '192.168.6.20', 'domains' => ['test']])
        ->and($selection['domains'])->toContain('orbit', 'foreign.test')->not->toContain('test')
        ->and($selection['all_domains'])->toContain('test');
});

it('keeps VPN selection for a member without LAN and for an inactive Cluster', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster);
    expect(app(PeerDnsPolicy::class)->selection($member)['delegate'])->toBeNull();
    $member->update(['lan_ip' => '192.168.6.21']);
    $cluster->update(['state' => ClusterState::Inactive]);
    expect(app(PeerDnsPolicy::class)->selection($member->fresh())['delegate'])->toBeNull();
});

it('retains a candidate after a partial DNS selection failure', function (): void {
    $candidate = new NodeRole([
        'role' => RoleName::Router, 'status' => 'failed',
        'failed_step' => 'dns-publication', 'error_code' => 'dns.member_selection_failed',
    ]);
    expect(ClusterRouterTransition::candidateServes($candidate))->toBeTrue()
        ->and(ClusterRouterTransition::candidateAnswersDns($candidate))->toBeTrue();
});

it('keeps complete VPN selection for a retained detach before membership is removed', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    app(ClusterDnsTransition::class)->begin($member, $cluster->id, null);
    $policy = app(PeerDnsPolicy::class)->selection($member->fresh());
    expect($policy['delegate'])->toBeNull()->and($policy['domains'])->toContain('test');
});

it('blocks Node removal until membership and retained DNS transitions are cleared', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'state' => ClusterState::Inactive]);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    $caller = clusterDnsMember('caller', '10.44.0.2');
    expect(fn () => app(NodeRemovalPolicy::class)->protected($member, $caller))
        ->toThrow(ResourceOperationException::class, 'Complete Cluster detach');
    app(ClusterDnsTransition::class)->begin($member, $cluster->id, null);
    $member->update(['cluster_id' => null]);
    expect(fn () => app(NodeRemovalPolicy::class)->protected($member->fresh(), $caller))
        ->toThrow(ResourceOperationException::class, 'Complete Cluster detach');
});

it('restamps every resolver and compares publications without nested revisions', function (): void {
    $dns = ['revision' => '1', 'routers' => [['dns' => ['revision' => '1', 'listen' => ['192.168.6.20']]]],
        'retired' => [['revision' => '1', 'cluster_id' => 4]]];
    $next = app(PrivateDnsPublication::class)->restamp($dns);
    expect($next['routers'][0]['dns']['revision'])->toBe($next['revision'])
        ->and($next['retired'][0]['revision'])->toBe($next['revision'])
        ->and(PrivateDnsPublication::same($dns, $next))->toBeTrue();
});

it('reports unsupported DNS delegate capability from phase evidence', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    app(ClusterDnsEvidence::class)->record($member->id, ['checked_at' => '100', 'effective' => ['supported' => false]]);
    $report = app(FactDoctor::class)->report([$member->fresh()], [DoctorFamily::Node]);
    expect(array_column($report->nodes[0]->families[0]->issues, 'code'))->toContain('node.cluster_dns_delegate_unsupported');
});

it('reports a failed live DNS observation while retaining the selected policy', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    $selection = app(PeerDnsPolicy::class)->selection($member);
    app(ClusterDnsEvidence::class)->record($member->id, ['checked_at' => '100', 'effective' => ['supported' => true, ...$selection]]);
    NodeFact::query()->create(['node_id' => $member->id, 'kind' => 'units', 'digest' => 'proof', 'observed_at' => now(), 'received_at' => now(),
        'data' => ['units' => [
            ['runtime' => 'systemd', 'name' => 'systemd-resolved.service', 'state' => 'active'],
            ['runtime' => 'systemd', 'name' => 'orbit-cluster-dns-check.timer', 'state' => 'active'],
            ['runtime' => 'systemd', 'name' => 'orbit-cluster-dns-check.service', 'state' => 'failed'],
        ]]]);

    $report = app(FactDoctor::class)->report([$member->fresh()], [DoctorFamily::Node]);

    expect(array_column($report->nodes[0]->families[0]->issues, 'code'))->toContain('node.cluster_dns_unreachable')
        ->and(app(PeerDnsPolicy::class)->selection($member->fresh()))->toBe($selection);
});

it('ignores older member evidence even when the old run finishes later', function (): void {
    $member = clusterDnsMember('member', '10.44.0.10');
    $evidence = app(ClusterDnsEvidence::class);
    $evidence->record($member->id, ['revision' => '20', 'checked_at' => '100', 'effective' => ['supported' => true]]);
    $evidence->record($member->id, ['revision' => '19', 'checked_at' => '200', 'effective' => ['supported' => false]]);

    expect($member->fresh()->settings[ClusterDnsEvidence::Key]['effective']['supported'])->toBeTrue();
});

it('includes a retained detach in publication after the final Router leaves', function (): void {
    SwampNodeFixture::ensure();
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Inactive]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10');
    app(ClusterDnsTransition::class)->begin($member, $cluster->id, null);

    $publication = app(PrivateDnsPublication::class)->render();

    expect($publication['members'][0]['node_id'])->toBe($member->id)
        ->and($publication['members'][0]['policy']['delegate'])->toBeNull()
        ->and($publication['members'][0]['policy']['revision'])->toBe($publication['revision']);
});

it('withdraws previously selected members and retires DNS when LAN addresses are removed', function (): void {
    SwampNodeFixture::ensure();
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    $vpn = clusterDnsMember('vpn', '10.44.0.1');
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);
    $member = clusterDnsMember('member', '10.44.0.10', $cluster, '192.168.6.21');
    foreach ([$router, $member] as $node) {
        app(ClusterDnsEvidence::class)->record($node->id, ['checked_at' => '100', 'effective' => app(PeerDnsPolicy::class)->selection($node)]);
    }
    foreach ([$router, $member] as $node) {
        $node->update(['lan_ip' => null]);
    }

    $publication = app(PrivateDnsPublication::class)->render();

    expect($publication['routers'])->toBe([])
        ->and(array_column($publication['members'], 'node_id'))->toContain($router->id, $member->id)
        ->and(array_column(array_column($publication['members'], 'policy'), 'delegate'))->toBe([null, null])
        ->and($publication['retired'][0]['node_id'])->toBe($router->id);
});

it('keeps the previous Router bind until consumers confirm its new LAN selection', function (): void {
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $router = clusterDnsMember('router', '10.44.0.7', $cluster, '192.168.6.20');
    $router->roles()->create(['role' => RoleName::Router, 'status' => 'active', 'cluster_id' => $cluster->id]);
    app(ClusterDnsEvidence::class)->record($router->id, ['checked_at' => '100', 'effective' => [
        'delegate' => ['cluster_id' => $cluster->id, 'router_id' => $router->id, 'server' => '192.168.6.20'],
    ]]);
    $router->update(['lan_ip' => '192.168.6.22']);

    $projection = app(DevelopmentDnsConfigRenderer::class)->routerState($cluster, $router->fresh());

    expect($projection->listen)->toBe(['192.168.6.22', '192.168.6.20'])
        ->and($projection->tlds['test'])->toBe('192.168.6.22');
});
