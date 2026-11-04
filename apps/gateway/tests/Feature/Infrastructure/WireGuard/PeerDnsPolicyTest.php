<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Fleet\Footprint\NetworkFootprintArtifact;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('renders every private namespace and changes it when records change', function (): void {
    Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3', 'tld' => 'prod.internal']);
    $private = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'app.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);
    $project = Project::query()->create(['name' => 'public', 'slug' => 'public', 'repository_url' => 'https://github.com/example/public']);
    Route::query()->create(['kind' => RouteKind::App, 'project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'public.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Public]);
    Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'ai-cli-proxy.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]);

    expect(app(PeerDnsPolicy::class)->domains())->toBe(['app.example.com', 'orbit', 'prod.internal', 'test']);

    $replacement = $private->replicate();
    $replacement->domain = 'new.example.com';
    $private->delete();
    $replacement->save();
    $private = $replacement;
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['new.example.com', 'orbit', 'prod.internal', 'test']);
    $private->delete();
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['orbit', 'prod.internal', 'test']);
});

it('refuses malformed stored names before peer work', function (string $tld): void {
    DB::table('nodes')->insert(['name' => 'bad', 'public_ssh_host' => '192.0.2.3', 'tld' => $tld]);

    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
})->with(['.', 'bad;command', 'bad..test', 'bad.test..', '-bad.test', str_repeat('a', 64).'.test']);

it('accepts the Orbit domain limit and refuses one more without truncating', function (): void {
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
    DB::table('clusters')->insert(['name' => 'overflow', 'tld' => 'overflow']);

    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
});

it('refuses a global underlay DNS server before inspecting a peer', function (): void {
    [$node] = peer_dns_inspection_fixture();
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.0.0.2');
    expect(fn () => app(PeerDnsPolicy::class)->server($node))->toThrow(NodeProvisioningException::class, 'The private DNS server must be a usable address in the WireGuard subnet.');
});

/** @return array{Node} */
function peer_dns_inspection_fixture(): array
{
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $node = Node::query()->create(['name' => 'app-prod', 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3', 'wireguard_public_key' => str_repeat('A', 43).'=', 'ssh_host_fingerprint' => 'SHA256:managed', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::AppProd]);

    return [$node->refresh()->load('roles')];
}

it('excludes the Gateway after VPN moves to another Node', function (): void {
    [$node] = peer_dns_inspection_fixture();
    $node->roles()->create(['role' => RoleName::Gateway]);
    $node->refresh()->load('roles');

    expect(app(PeerDnsPolicy::class)->manages($node))->toBeFalse()
        ->and(app(NetworkFootprintArtifact::class)->applies($node))->toBeTrue();
});

it('refuses invalid names and overflow at each namespace write boundary without saving', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3']);
    $candidate = match ($kind) {
        'node' => $node->fill(['tld' => 'bad;name']),
        'cluster' => new Cluster(['name' => 'dev', 'tld' => 'bad;name']),
        'route' => new Route(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'bad;name', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    expect(fn () => $candidate->save())->toThrow(ValidationException::class);
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['orbit']);
    $rows = [];
    for ($i = 0; $i < 511; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    $candidate->setAttribute($kind === 'route' ? 'domain' : 'tld', 'overflow.example');
    expect(fn () => $candidate->save())->toThrow(ValidationException::class);
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
    $candidate->setAttribute($kind === 'route' ? 'domain' : 'tld', 'covered.t0');
    expect($candidate->save())->toBeTrue();
    expect(app(PeerDnsPolicy::class)->domains())->toHaveCount(512);
})->with(['node', 'cluster', 'route']);

it('refuses removal of a covering namespace when its descendants exceed the cap', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3']);
    $cover = match ($kind) {
        'node' => $node->fill(['tld' => 'example.test']),
        'cluster' => new Cluster(['name' => 'dev', 'tld' => 'example.test']),
        'route' => new Route(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'example.test', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    $cover->save();
    $rows = [];
    for ($i = 0; $i < 512; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i.'.example.test'];
    }
    Cluster::query()->insert($rows);

    expect(fn () => $cover->delete())->toThrow(ValidationException::class);
    $this->assertModelExists($cover);
    expect(app(PeerDnsPolicy::class)->domains())->toBe(['example.test', 'orbit']);
})->with(['node', 'cluster', 'route']);

it('allows nonincreasing teardown and TLD rollback when stored namespace already exceeds the cap', function (string $kind): void {
    $node = Node::query()->create(['name' => 'prod', 'public_ssh_host' => '192.0.2.3', 'tld' => 'original.internal']);
    $cover = match ($kind) {
        'node' => $node,
        'cluster' => Cluster::query()->create(['name' => 'dev', 'tld' => 'dev.internal']),
        'route' => Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $node->id, 'domain' => 'app.example.com', 'provenance' => RouteProvenance::Explicit, 'publication' => RoutePublication::Private]),
    };
    $rows = [];
    for ($i = 0; $i < 513; $i++) {
        $rows[] = ['name' => 'c'.$i, 'tld' => 't'.$i];
    }
    Cluster::query()->insert($rows);
    if ($kind === 'node') {
        expect($node->update(['tld' => 'changed.internal']))->toBeTrue();
        expect($node->update(['tld' => 'original.internal']))->toBeTrue();
        expect($node->refresh()->tld)->toBe('original.internal');
    }
    expect($cover->delete())->toBeTrue();
    $this->assertModelMissing($cover);
    expect(fn () => Cluster::query()->create(['name' => 'growth', 'tld' => 'growth.internal']))->toThrow(ValidationException::class);
    expect(fn () => app(PeerDnsPolicy::class)->domains())->toThrow(NodeProvisioningException::class);
})->with(['node', 'cluster', 'route']);
