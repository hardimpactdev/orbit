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
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;

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
            throw new LogicException('Gateway capture must not look up a Node account.');
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
        'scope' => "route-{$this->route->id}-router", 'user' => 'orbit', 'domain' => $this->route->domain,
        'root_hash' => hash('sha256', $root)]);
    expect($leaf)->not->toHaveKey('home');
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

it('selects all published hosts of the authoritative app Route and ignores other bindings', function (): void {
    $project = Project::query()->create(['name' => 'Tracking Shop', 'slug' => 'tracking-shop',
        'repository_url' => 'https://example.test/shop.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = instance_analytics_node('tracking-app', '10.44.0.60', $this->cluster, RoleName::AppProd);
    $instance = instance_analytics_instance($project, $node, 'production');
    $owner = instance_analytics_app_route($instance, 'shop.example.com', RoutePublication::Public);
    $other = Route::query()->create(['kind' => RouteKind::App, 'project_id' => $project->id, 'cluster_id' => $this->cluster->id,
        'domain' => 'other.example.com', 'publication' => RoutePublication::Public, 'provenance' => RouteProvenance::Explicit,
        'status' => RouteStatus::Pending, 'web_root' => 'docs']);
    $other->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $other->update(['status' => RouteStatus::Active]);
    $default = instance_analytics_tracking($instance, 'default-tracker.example.com');
    $custom = instance_analytics_tracking($instance, 'custom-tracker.example.com');
    $failed = instance_analytics_tracking($instance, 'failed-tracker.example.com');
    $failed->update(['status' => RouteStatus::Failed, 'failed_step' => 'old-publication', 'error_code' => 'analytics.old_failure']);
    $pending = instance_analytics_tracking($instance, 'pending-tracker.example.com');
    $pending->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);

    $plans = app(AnalyticsRoutePlan::class);

    expect(array_map(static fn (Route $route): int => $route->id, $plans->associated($owner)))->toBe([$default->id, $custom->id, $failed->id]);
    expect($plans->associated($other))->toBe([]);
    expect($plans->associated($default))->toBe([]);
});

it('captures publication changes once and projects or rolls back only the admitted tracking records', function (): void {
    $project = Project::query()->create(['name' => 'Checkpoint Shop', 'slug' => 'checkpoint-shop',
        'repository_url' => 'https://example.test/checkpoint.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = instance_analytics_node('checkpoint-app', '10.44.0.60', $this->cluster, RoleName::AppProd);
    $instance = instance_analytics_instance($project, $node, 'production');
    $owner = instance_analytics_app_route($instance, 'shop.example.com', RoutePublication::Public);
    $tracking = instance_analytics_tracking($instance, 'custom-tracker.example.com');
    $before = $tracking->fresh()->getAttributes();
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('public-root');
    app()->instance(LeafCertificateSigner::class, $signer);
    $candidate = clone $owner;
    $candidate->publication = RoutePublication::Private;
    $plans = app(AnalyticsRoutePlan::class);

    $changes = $plans->captureUpdate($owner, $candidate);

    expect($changes)->toHaveCount(1);
    expect($changes[0]['node_ids'])->toContain($this->router->id, $this->ingress->id, $this->vpn->id);
    expect($changes[0]['rollback']['caddy_node_ids'])->toContain($this->ingress->id);
    expect($changes[0]['rollback']['cleanup'])->toBe([]);
    expect($tracking->fresh()->getAttributes())->toBe($before);
    expect($changes[0]['publish']['certificates'][0]['domain'])->toBe('custom-tracker.example.com');
    expect($changes[0]['publish']['certificates'][0])->not->toHaveKey('home');
    $plans->checkpointUpdate($changes, 'certificates_ready');
    expect($tracking->fresh()->publication)->toBe(RoutePublication::Private);
    expect($tracking->fresh()->status)->toBe(RouteStatus::Activating);
    $plans->checkpointUpdate($changes, 'public_verified');
    expect($tracking->fresh()->replacement_step)->toBeNull();
    $plans->checkpointUpdate($changes, 'complete');
    expect($tracking->fresh()->status)->toBe(RouteStatus::Active);
    expect($tracking->fresh()->domain)->toBe('custom-tracker.example.com');
    $plans->checkpointUpdate($changes, 'rollback');
    expect($tracking->fresh()->publication)->toBe(RoutePublication::Public);
    expect($tracking->fresh()->replacement_step)->toBe(RouteReplacementStep::PublicActivated);
    expect($owner->fresh()->publication)->toBe(RoutePublication::Public);
    expect(Operation::query()->count())->toBe(0);
});

it('keeps existing tracking hostnames and creates no host plan for a domain-only change', function (): void {
    $project = Project::query()->create(['name' => 'Domain Shop', 'slug' => 'domain-shop',
        'repository_url' => 'https://example.test/domain.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = instance_analytics_node('domain-app', '10.44.0.60', $this->cluster, RoleName::AppProd);
    $instance = instance_analytics_instance($project, $node, 'production');
    $owner = instance_analytics_app_route($instance, 'current-shop.example.com', RoutePublication::Public);
    $tracking = instance_analytics_tracking($instance);
    $candidate = clone $owner;
    $candidate->domain = 'new.example.com';

    expect(app(AnalyticsRoutePlan::class)->captureUpdate($owner, $candidate))->toBe([]);
    expect($tracking->fresh()->domain)->toBe('analytics.current-shop.example.com');
});

it('captures new-only rollback leaves and preserves freshly loaded scopes after restoration', function (): void {
    $project = Project::query()->create(['name' => 'Rollback Shop', 'slug' => 'rollback-shop',
        'repository_url' => 'https://example.test/rollback.git', 'default_branch' => 'main', 'root' => 'public']);
    $node = instance_analytics_node('rollback-app', '10.44.0.60', $this->cluster, RoleName::AppProd);
    $instance = instance_analytics_instance($project, $node, 'production');
    $owner = instance_analytics_app_route($instance, 'shop.example.com', RoutePublication::Public);
    $tracking = instance_analytics_tracking($instance, 'tracker.example.com');
    $candidateNode = instance_analytics_node('candidate-host', '10.44.0.80', null, RoleName::AppDev);
    instance_analytics_node('plausible', '10.44.0.90', null, RoleName::Analytics);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('public-root');
    app()->instance(LeafCertificateSigner::class, $signer);
    $candidate = clone $owner;
    $candidate->fill(['node_id' => $candidateNode->id, 'cluster_id' => null, 'publication' => RoutePublication::Private]);
    $plans = app(AnalyticsRoutePlan::class);

    $changes = $plans->captureUpdate($owner, $candidate);

    expect($changes)->toHaveCount(1);
    $rollback = ValidatedData::object(json_decode(json_encode($changes[0]['rollback'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
    expect(array_column($rollback['cleanup'], 'node_id'))->toBe([$candidateNode->id]);
    expect($rollback['cleanup'][0])->toMatchArray(['scope' => "route-{$tracking->id}-router", 'in_use' => false]);
    $plans->checkpointUpdate($changes, 'certificates_ready');
    expect($plans->cleanupForNode($rollback, $candidateNode->id))->toBe([]);
    $plans->checkpointUpdate($changes, 'rollback');
    expect($plans->cleanupForNode($rollback, $candidateNode->id))->toEqual($rollback['cleanup']);
    expect($plans->cleanupForNode($rollback, $this->router->id))->toBe([]);
    $tracking->update(['transition_node_id' => $candidateNode->id, 'replacement_step' => null]);
    expect($plans->cleanupForNode($rollback, $candidateNode->id))->toBe([]);
});

it('refuses malformed persisted cleanup before selecting a certificate', function (): void {
    expect(fn () => app(AnalyticsRoutePlan::class)->cleanupForNode(['cleanup' => 'not-a-leaf-list'], $this->router->id))
        ->toThrow(ResourceOperationException::class, 'The stored tracking cleanup must be a leaf list.');
});
