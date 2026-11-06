<?php

declare(strict_types=1);

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
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

    return array_keys(array_values($overrides)[0] ?? []);
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
