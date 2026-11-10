<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDevPorts;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

/**
 * A web app's container default and one clone of it with T3, on one build-list Node, with the clone's Route.
 *
 * @return array{node: Node, project: Project, default: Instance, clone: Instance, runtime: InstanceRuntime, route: Route}
 */
function devPortWorld(string $publication = 'private'): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $runtime = static fn (Instance $instance, string $address, ?Instance $source, ?int $port): InstanceRuntime => InstanceRuntime::query()->create([
        'instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id,
        'bridge_address' => $address, 't3_port' => $port, 'runtime_version' => 'r', 'agent_version' => 'a', 'clone_source_instance_id' => $source?->id,
        'power_state' => InstanceRuntimePowerState::Running]);
    $default = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/d', 'status' => InstanceState::Active]);
    $runtime($default, '10.253.0.2', null, null)->update(['seed_commit' => str_repeat('c', 40)]);
    $clone = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature', 'checkout_path' => '/f', 'status' => InstanceState::Active]);
    $cloneRuntime = $runtime($clone, '10.253.0.3', $default, 3800);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => $publication, 'domain' => 'feature.tool.test', 'status' => 'pending']);

    return ['node' => $node, 'project' => $project, 'default' => $default, 'clone' => $clone, 'runtime' => $cloneRuntime, 'route' => $route];
}

/** @return list<CaddySite> */
function devPortSites(Instance $instance, Route $route): array
{
    return array_values(array_filter(app(RouteCaddySiteSource::class)->fromSites(collect([app(DevelopmentSiteRepository::class)->workloadSite($instance, $route)])),
        static fn (CaddySite $caddy): bool => str_ends_with($caddy->name, '-dev')));
}

it('serves a clone\'s development servers on Caddy\'s development listener, on the WireGuard address only, at the port the client names', function (): void {
    $world = devPortWorld();

    $sites = devPortSites($world['clone'], $world['route']);

    $id = $world['clone']->id;
    expect(array_map(static fn (CaddySite $site): array => [$site->name, $site->port, $site->listener, $site->hosts], $sites))
        ->toBe([["app-instance-{$id}-dev", 444, CaddyListenerRule::WireGuard, ['feature.tool.test']]])
        ->and($sites[0]->body)->toContain('https://feature.tool.test:444 {')
        ->toContain('bind '.RouteCaddySiteSource::BindPlaceholder)
        ->toContain("tls /etc/caddy/orbit-certificates/app-instance-{$id}/current/cert.pem")
        // The port comes from Host, inside the range; Caddy keeps that Host, which Vite checks.
        ->toContain("string({http.request.port}).matches('^[0-9]+$') && int({http.request.port}) >= 1024 && int({http.request.port}) <= 65535")
        ->toContain('reverse_proxy 10.253.0.3:{http.request.port} {')
        ->toContain('respond "Orbit development port refused" 404')
        ->and(str_contains($sites[0]->body, 'header_up Host'))->toBeFalse();
});

it('serves no development servers on a public Route', function (): void {
    $public = devPortWorld('public');

    expect(devPortSites($public['clone'], $public['route']))->toBe([]);
});

it('serves no development servers for the default, or for a Project without a Route', function (): void {
    $world = devPortWorld();
    $defaultRuntime = InstanceRuntime::query()->where('instance_id', $world['default']->id)->firstOrFail();

    expect(devPortSites($world['default'], $world['route']))->toBe([])
        ->and(app(InstanceContainer::class)->servesDevPorts($world['default'], $defaultRuntime))->toBeFalse();

    $world['project']->update(['type' => ProjectType::NodePackage]);
    expect(app(InstanceContainer::class)->servesDevPorts($world['clone']->refresh(), $world['runtime']))->toBeFalse();
});

it('lets only the Node\'s Caddy reach the development range in the clone, and names it in the instance file', function (): void {
    $world = devPortWorld();

    $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');

    $instanceFile = collect(ValidatedData::object($container['clone'])['files'])->firstWhere('path', '/etc/orbit/instance');
    expect($container['acl_rules']['ingress'][2])
        ->toBe(['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '1024-65535', 'state' => 'enabled'])
        ->and($instanceFile['content'])->toContain("ORBIT_URL=https://feature.tool.test\nORBIT_DEV_PORTS=1024-65535\n");
});

describe('the Node\'s redirect of the development range', function (): void {
    it('leaves the Node\'s own ports and every listening port to their programs, and sends the rest to Caddy', function (): void {
        $world = devPortWorld();
        $world['node']->update(['public_ssh_port' => 2222]);
        DatabaseServer::query()->forceCreate(['slug' => 'pg', 'driver' => 'pgsql', 'node_id' => $world['node']->id, 'tag' => '18', 'port' => 5432,
            'status' => 'active', 'root_password' => 'root-secret']);
        Process::query()->forceCreate(['owner_type' => 'node', 'owner_id' => $world['node']->id, 'name' => 'grafana', 'runtime' => 'docker',
            'runtime_config' => ['image' => 'grafana', 'ports' => ['10.44.0.7:3000:3000', '127.0.0.1:80:80']], 'status' => 'active']);
        Process::query()->forceCreate(['owner_type' => Instance::MorphAlias, 'owner_id' => $world['clone']->id, 'name' => 'queue', 'runtime' => 'docker',
            'runtime_config' => ['image' => 'x', 'ports' => ['9100:9100/tcp']], 'status' => 'active']);

        $ruleset = app(InstanceDevPorts::class)->ruleset($world['node']->refresh());

        expect(app(InstanceDevPorts::class)->excluded($world['node']))
            ->toBe(['2222', '3000', '3128', '3800-3999', '5432', '8318-8319', '9100', '51820'])
            ->and($ruleset)->toContain("table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\n")
            ->toContain('type nat hook prerouting priority dstnat + 10; policy accept;')
            ->toContain('iifname "orbit" ip daddr 10.44.0.7 tcp dport 1024-65535 ct state new jump development')
            ->toContain('elements = { 2222, 3000, 3128, 3800-3999, 5432, 8318-8319, 9100, 51820 }')
            ->toContain("tcp dport @excluded return\n\t\tsocket transparent 0 return\n\t\tmeta l4proto tcp counter redirect to :444");
    });

    it('is part of a build-list Node\'s Instance host converge', function (): void {
        $world = devPortWorld();

        $intent = app(InstanceHostIntent::class)->for($world['node']);

        expect($intent['dev_ports'] ?? null)->toBe(['table' => 'orbit_instance_dev_ports', 'unit' => 'orbit-instance-dev-ports.service',
            'ruleset' => app(InstanceDevPorts::class)->ruleset($world['node'])]);
    });
});
