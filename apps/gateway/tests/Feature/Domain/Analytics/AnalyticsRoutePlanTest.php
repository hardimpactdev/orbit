<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;

beforeEach(function (): void {
    $this->cluster = Cluster::query()->create(['name' => 'tracking-edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $this->router = instance_analytics_node('tracking-router', '10.44.0.20', $this->cluster, RoleName::Router);
    $this->ingress = instance_analytics_node('tracking-ingress', '10.44.0.30', $this->cluster, RoleName::Ingress);
    $this->vpn = instance_analytics_node('tracking-dns', '10.44.0.1', null, RoleName::Vpn);
    $this->route = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'domain' => 'analytics.shop.example.com',
        'provenance' => RouteProvenance::Explicit, 'cluster_id' => $this->cluster->id, 'publication' => RoutePublication::Public, 'status' => RouteStatus::Pending]);
    $this->route->update(['status' => RouteStatus::Active]);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount($node->user, $node->user, '/srv/users/'.$node->user);
        }
    });
});

it('captures only public host identities and probes without creating or mutating intent', function (): void {
    $root = 'public-root-certificate';
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->once()->andReturn($root);
    app()->instance(LeafCertificateSigner::class, $signer);
    $before = $this->route->fresh()->getAttributes();

    $plan = app(AnalyticsRoutePlan::class)->capture($this->route);

    expect($plan['route_id'])->toBe($this->route->id);
    expect($plan['certificates'])->toHaveCount(1);
    $leaf = $plan['certificates'][0];
    expect($leaf)->toMatchArray(['node_id' => $this->router->id, 'route_id' => $this->route->id,
        'scope' => "route-{$this->route->id}-router", 'home' => '/srv/users/orbit', 'domain' => $this->route->domain,
        'root_hash' => hash('sha256', $root)]);
    expect($leaf['version'])->toHaveLength(16);
    expect($plan['cleanup'])->toBe([]);
    expect($plan['caddy_node_ids'])->toBe([$this->router->id, $this->ingress->id]);
    expect($plan['dns_node_ids'])->toBe([$this->vpn->id]);
    expect($plan['public_verifications'])->toHaveCount(1);
    expect($plan['public_verifications'][0])->toMatchArray(['node_id' => $this->ingress->id,
        'verification' => ['protocol' => 'tls', 'agent_sha256' => $leaf['agent_sha256'],
            'probe' => ['address' => $this->router->lan_ip, 'port' => 443, 'domain' => $this->route->domain]]]);
    expect($this->route->fresh()->getAttributes())->toBe($before);
    expect(Operation::query()->count())->toBe(0);
    expect(json_encode($plan, JSON_THROW_ON_ERROR))->not->toContain('private_key', 'password', 'apiKey');
});

it('captures removal on current and retiring hosts without loading the public CA', function (): void {
    $old = instance_analytics_node('old-tracking-router', '10.44.0.50', null, RoleName::AppDev);
    $this->route->update(['transition_node_id' => $old->id]);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldNotReceive('rootCertificate');
    app()->instance(LeafCertificateSigner::class, $signer);

    $plan = app(AnalyticsRoutePlan::class)->capture($this->route->fresh(), true);

    expect($plan['certificates'])->toBe([]);
    expect(array_column($plan['cleanup'], 'node_id'))->toBe([$this->router->id, $old->id]);
    expect(array_column($plan['cleanup'], 'in_use'))->toBe([false, false]);
    expect($plan['public_verifications'])->toBe([]);
    expect($plan['caddy_node_ids'])->toContain($this->router->id, $old->id, $this->ingress->id);
});

it('never removes the current leaf when a retiring placement uses the same Router', function (): void {
    $this->route->update(['transition_node_id' => $this->router->id]);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->once()->andReturn('public-root');
    app()->instance(LeafCertificateSigner::class, $signer);

    $plan = app(AnalyticsRoutePlan::class)->capture($this->route->fresh());

    expect($plan['certificates'])->toHaveCount(1);
    expect($plan['cleanup'])->toBe([]);
});

it('captures no public Ingress probe for a private tracking host', function (): void {
    $this->route->update(['publication' => RoutePublication::Private]);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->once()->andReturn('public-root');
    app()->instance(LeafCertificateSigner::class, $signer);

    $plan = app(AnalyticsRoutePlan::class)->capture($this->route->fresh());

    expect($plan['caddy_node_ids'])->toBe([$this->router->id]);
    expect($plan['ingress_node_ids'])->toBe([]);
    expect($plan['public_verifications'])->toBe([]);
});

it('refuses a generic app Route before capturing Analytics effects', function (): void {
    $this->route->kind = RouteKind::App;

    expect(fn () => app(AnalyticsRoutePlan::class)->capture($this->route))
        ->toThrow(ResourceOperationException::class, 'A tracking host requires an analytics Route.');
});
