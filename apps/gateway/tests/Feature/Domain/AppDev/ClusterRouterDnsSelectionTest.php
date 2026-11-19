<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRoutePublicationInput;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process as ManagedProcess;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A Cluster whose Router and one member have a LAN address, and two Routes in it. @return array{0:Node,1:list<string>} */
function routerClusterWithRoutes(): array
{
    $cluster = Cluster::query()->create(['name' => 'dev', 'tld' => 'test']);
    $cluster->forceFill(['state' => ClusterState::Active])->save();
    $router = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'lan_ip' => '192.168.6.20', 'user' => 'orbit', 'cluster_id' => $cluster->id,
        'wireguard_public_key' => 'router-key']));
    NodeRole::query()->create(['node_id' => $router->id, 'role' => RoleName::Router, 'status' => LifecycleStatus::Active, 'cluster_id' => $cluster->id]);
    $project = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => 'main', 'root' => 'public']);
    $domains = ['kept.create-bench.test', 'gone.create-bench.test'];
    foreach ($domains as $domain) {
        Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'cluster_id' => $cluster->id, 'provenance' => 'explicit', 'publication' => 'private', 'domain' => $domain, 'status' => 'pending']);
    }

    return [$router, $domains];
}

/** @return list<string> The names the Router answers with its LAN address, for any requester. */
function routerNames(): array
{
    $overrides = app(ClusterRouterDnsSelection::class)->answers()['overrides'];

    return array_keys(array_first($overrides) ?? []);
}

it('names every Route of the Cluster while the real fleet is rendered', function (): void {
    routerClusterWithRoutes();

    expect(routerNames())->toContain('kept.create-bench.test', 'gone.create-bench.test');
});

it('drops the name of a Route that a Route change leaves without a site, as it drops its host record', function (): void {
    [$router] = routerClusterWithRoutes();
    $kept = new DevelopmentSite($router->id, '10.44.0.7', 'kept', '/apps/kept', '/apps/kept/public', null, 'kept.create-bench.test');

    $names = app(RoutePublicationSites::class)->render(collect([$kept]), routerNames(...), complete: true);

    expect($names)->toContain('test', 'kept.create-bench.test')
        ->and($names)->not->toContain('gone.create-bench.test');
});

it('keeps naming Routes by status in a capture that is not a Route change', function (): void {
    [$router] = routerClusterWithRoutes();
    $kept = new DevelopmentSite($router->id, '10.44.0.7', 'kept', '/apps/kept', '/apps/kept/public', null, 'kept.create-bench.test');

    $names = app(RoutePublicationSites::class)->render(collect([$kept]), routerNames(...));

    expect($names)->toContain('kept.create-bench.test', 'gone.create-bench.test');
});

it('names a Route that a removal has marked retiring while the fleet before the removal still serves it', function (): void {
    [$router] = routerClusterWithRoutes();
    // A removal marks its Route retiring and unpublished before it captures, so the status alone cannot tell the fleet before from the fleet after.
    Route::query()->where('domain', 'gone.create-bench.test')->update(['status' => 'retiring', 'sites_published' => false]);
    $site = static fn (string $domain): DevelopmentSite => new DevelopmentSite($router->id, '10.44.0.7', $domain, '/apps/'.$domain, '/apps/'.$domain.'/public', null, $domain);
    $before = collect([$site('kept.create-bench.test'), $site('gone.create-bench.test')]);
    $after = collect([$site('kept.create-bench.test')]);

    $namesBefore = app(RoutePublicationSites::class)->render($before, routerNames(...), complete: true);
    $namesAfter = app(RoutePublicationSites::class)->render($after, routerNames(...), complete: true);

    expect($namesBefore)->toContain('kept.create-bench.test', 'gone.create-bench.test')
        ->and($namesAfter)->toContain('kept.create-bench.test')->not->toContain('gone.create-bench.test');
});

/** A live public Process Route on the Router, which also holds the Ingress. */
function publicProcessRoute(Node $router): Route
{
    $process = ManagedProcess::query()->create([
        'owner_type' => Node::class, 'owner_id' => $router->id, 'name' => 'sync',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'nginx:alpine', 'ports' => ['192.168.6.20:18080:8080/tcp']],
        'desired_state' => 'running', 'status' => 'active',
    ]);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $router->cluster_id, 'provenance' => 'explicit', 'publication' => 'public',
        'domain' => 'sync.example.com', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $router->id, 'process_id' => $process->id, 'upstream' => 'http://192.168.6.20:18080']);
    $route->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => 'cleanup']);

    return $route;
}

it('lists the Router LAN answer that earlier releases gave a public Process name, so publishers withdraw it', function (): void {
    [$router] = routerClusterWithRoutes();
    $router->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $router->cluster_id]);
    publicProcessRoute($router);

    $retired = app(ClusterRouterDnsSelection::class)->retiredAnswers();
    $dns = app(NativeRoutePublicationInput::class)->dns($router);

    expect($retired)->not->toBe([])
        ->and(array_column(array_values($retired), 'sync.example.com'))->each->toBe('192.168.6.20')
        ->and(collect($retired)->flatMap(static fn (array $names): array => array_keys($names))->unique()->all())->toBe(['sync.example.com'])
        // The published catalog never holds the retired answer; the snapshot carries it beside the catalog for the merge.
        ->and(json_decode($dns['catalog'], true)['overrides'])->each->not->toHaveKey('sync.example.com')
        ->and(json_decode(json_encode($dns['retired']), true))->toBe($retired);
});

it('never answers a public Process Route with the Router LAN address, because only the Ingress serves it', function (): void {
    [$router] = routerClusterWithRoutes();
    $router->roles()->create(['role' => RoleName::Ingress, 'status' => LifecycleStatus::Active, 'cluster_id' => $router->cluster_id]);
    $process = ManagedProcess::query()->create([
        'owner_type' => Node::class, 'owner_id' => $router->id, 'name' => 'sync',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'nginx:alpine', 'ports' => ['10.44.0.7:18080:8080/tcp']],
        'desired_state' => 'running', 'status' => 'active',
    ]);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $router->cluster_id, 'provenance' => 'explicit', 'publication' => 'public',
        'domain' => 'sync.example.com', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $router->id, 'process_id' => $process->id, 'upstream' => 'http://10.44.0.7:18080']);
    $route->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => 'cleanup']);

    expect(routerNames())->toContain('kept.create-bench.test')
        ->and(routerNames())->not->toContain('sync.example.com');
});
