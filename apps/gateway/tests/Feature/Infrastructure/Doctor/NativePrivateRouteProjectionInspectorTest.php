<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Doctor\NativePrivateRouteProjectionInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

it('observes private Route projections without application HTTP checks', function (string $root, string $relative): void {
    [$instance, $route] = private_route_inspector_standalone();
    $instance->update(['root' => $root]);
    $agents = private_route_agents();

    $observation = private_route_inspector($agents)->inspect($instance, $route);

    expect($observation)
        ->toEqual(new PrivateRouteProjectionObservation(true, true, true, true, true, true, true))
        ->and($agents->calls[0]['command'])->toBe('doctor.inspect-private-route')
        ->and($agents->calls[0]['input']['domain'])->toBe($route->domain)
        ->and($agents->calls[0]['input']['projection'])->toBe([
            'kind' => 'workload',
            'dns_address' => $instance->node->wireguard_ip,
            'laravel' => ['kind' => 'check', 'environment' => $instance->checkout_path.$relative.'/.env', 'url' => 'https://'.$route->domain],
        ])
        ->and(json_encode($observation, JSON_THROW_ON_ERROR))
        ->not->toContain($route->domain)
        ->not->toContain((string) $instance->checkout_path);
})->with(['root public' => ['public', ''], 'nested Laravel' => ['server/web/public', '/server/web']]);

it('reports a bounded private projection mismatch from remote observations', function (): void {
    [$instance, $route] = private_route_inspector_standalone();
    $agents = private_route_agents(['caddy' => false, 'tls' => false, 'dns' => false, 'laravel' => false]);

    $observation = private_route_inspector($agents)->inspect($instance, $route);

    expect($observation)->toEqual(new PrivateRouteProjectionObservation(
        true,
        true,
        false,
        false,
        false,
        true,
        false,
    ));
});

it('leaves the APP_URL of a Route with a web root to the application directory check', function (): void {
    [$instance, $own] = private_route_inspector_standalone();
    $route = Route::query()->create([
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'domain' => 'docs-'.uniqid().'.test',
        'web_root' => 'apps/docs/public',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $agents = private_route_agents();

    $observation = private_route_inspector($agents)->inspect($instance, $route->fresh());

    expect($observation->laravelUrlMatches)
        ->toBeTrue()
        ->and($agents->calls[0]['input']['projection']['laravel'])
        ->toBe(['kind' => 'skip'])
        ->and($own->hasWebRoot())
        ->toBeFalse();
});

it('fails closed when private Route inspection cannot run', function (): void {
    [$instance, $route] = private_route_inspector_standalone();

    expect(fn (): PrivateRouteProjectionObservation => private_route_inspector(
        new FakeAgentCommandRunner()->refuse('doctor.inspect-private-route', 'doctor.projection_invalid'),
    )->inspect($instance, $route))->toThrow(DoctorInspectionException::class, '');
});

it('inspects Router Caddy on a selected Cluster Route', function (): void {
    [$instance, $route, $router] = private_route_inspector_cluster();
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-private-route', static fn (array $input): array => [
        'caddy' => $input['projection']['kind'] === 'workload',
        'tls' => true, 'firewall' => true, 'dns' => true, 'laravel' => true,
        'pool' => $input['projection']['kind'] === 'router' ? false : null,
    ]);

    $observation = private_route_inspector($agents)->inspect($instance, $route);

    expect($observation->routerCaddyMatches)
        ->toBeFalse()
        ->and($observation->workloadCaddyMatches)
        ->toBeTrue()
        ->and($observation->targetSetMatches)->toBeFalse()
        ->and($agents->calls[1]['node'])->toBe($router->name)
        ->and($agents->calls[1]['input']['domain'])->toBe($route->domain)
        ->and($agents->calls[1]['input']['projection'])->toBe(['kind' => 'router', 'upstreams' => ['10.10.0.20']]);
});

/** @return array{Instance, Route} */
function private_route_inspector_standalone(): array
{
    $project = Project::query()->create([
        'name' => 'Private Doctor App',
        'slug' => 'private-doctor-app-'.uniqid(),
        'repository_url' => 'https://git.example.test/acme/private-doctor.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'private-doctor-workload-'.uniqid(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => private_route_inspector_address(),
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'development',
        'environment' => 'development',
        'checkout_path' => '/srv/users/nckrtl/apps/private-doctor/development',
        'branch' => 'development',
        'starting_commit' => str_repeat('a', 40),
        'source_is_laravel' => true,
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'private-doctor-'.uniqid().'.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->fresh(['node', 'project']), $route->fresh()];
}

/** @return array{Instance, Route, Node} */
function private_route_inspector_cluster(): array
{
    [$instance, $route] = private_route_inspector_standalone();
    $cluster = Cluster::query()->create([
        'name' => 'private-doctor-cluster-'.uniqid(),
        'state' => ClusterState::Active,
    ]);
    $instance->node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.20']);
    $router = Node::query()->create([
        'name' => 'private-doctor-router-'.uniqid(),
        'cluster_id' => $cluster->id,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => private_route_inspector_address(),
        'lan_ip' => '10.10.0.21',
    ]);
    $router->roles()->create([
        'cluster_id' => $cluster->id,
        'role' => RoleName::Router,
        'status' => LifecycleStatus::Active,
    ]);
    $route->update(['node_id' => null, 'cluster_id' => $cluster->id]);

    return [$instance->fresh(['node', 'project']), $route->fresh(['cluster.routerAssignment.node']), $router];
}

function private_route_inspector(FakeAgentCommandRunner $agents): NativePrivateRouteProjectionInspector
{
    return new NativePrivateRouteProjectionInspector($agents, new CommandDeadline);
}

/** @param array<string, ?bool> $overrides */
function private_route_agents(array $overrides = []): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner()->answer('doctor.inspect-private-route', array_replace([
        'caddy' => true, 'tls' => true, 'dns' => true, 'firewall' => true, 'laravel' => true, 'pool' => null,
    ], $overrides));
}

function private_route_inspector_address(): string
{
    static $octet = 80;

    return '10.44.8.'.($octet++);
}

it('rejects incomplete private Route observations', function (): void {
    [$instance, $route] = private_route_inspector_standalone();
    $agents = new FakeAgentCommandRunner()->answer('doctor.inspect-private-route', ['caddy' => true]);

    expect(fn () => private_route_inspector($agents)->inspect($instance, $route))->toThrow(DoctorInspectionException::class);
});

it('checks the stable production environment with stored URL intent', function (): void {
    [$instance, $route] = private_route_inspector_standalone();
    $instance->node->roles()->delete();
    $instance->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $instance->update(['production_home' => '/srv/production/app', 'checkout_path' => '/srv/production/app/releases/initial', 'environment' => 'production']);
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}/base']);
    $agents = private_route_agents();

    private_route_inspector($agents)->inspect($instance->fresh(), $route);

    expect($agents->calls[0]['input']['projection']['laravel'])->toBe([
        'kind' => 'check', 'environment' => '/srv/production/app/.env', 'url' => 'https://'.$route->domain.'/base',
    ]);
});

it('keeps private Route inspection behind agent commands', function (): void {
    $source = file_get_contents(app_path('Infrastructure/Doctor/NativePrivateRouteProjectionInspector.php'));
    expect($source)->not->toContain('SshExecutor', 'ProcessRunner', 'RemoteCommand', 'bash', '<<<');
});
