<?php

declare(strict_types=1);

use App\Actions\Doctor\RouteDoctorProbe;
use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Instances\LaravelRouteInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstancePathProxy;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Orbit\Sdk\Requests\Instances\UnsetInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\SetInstancePathProxyRequest;
use Orbit\Sdk\Requests\Instances\ShowInstancePathProxyRequest;

beforeEach(function (): void {
    $this->inspector = Mockery::mock(LaravelRouteInspector::class);
    $this->inspector->shouldReceive('hasCollisions')->andReturn(false)->byDefault();
    app()->instance(LaravelRouteInspector::class, $this->inspector);
});

/** @return array{Node, Instance, Route, Process} */
function path_proxy_fixture(): array
{
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.4', 'name' => 'app-prod', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'status' => 'active']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $cluster = Cluster::query()->create(['name' => 'prod', 'state' => 'active']);
    orbit_test_set_app_placement_role($node, true);
    $node->update(['cluster_id' => $cluster->id]);
    foreach ([RoleName::Router, RoleName::Ingress] as $role) {
        $node->roles()->create(['role' => $role, 'cluster_id' => $cluster->id, 'status' => 'active']);
    }
    $project = Project::query()->create(['repository_url' => 'https://example.test/hauzer.git', 'default_branch' => 'main', 'name' => 'Hauzer', 'slug' => 'hauzer', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'prod', 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => true,
        'production_user' => 'orbit-hauzer', 'production_home' => '/home/orbit-hauzer',
        'checkout_path' => '/home/orbit-hauzer/releases/one', 'selected_php_version' => '8.5',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'hauzer.app',
        'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending',
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $services = Node::query()->create(['public_ssh_host' => '192.0.2.3', 'name' => 'services', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'status' => 'active']);
    $process = $services->processes()->create(['name' => 'powersync-hauzer', 'runtime' => 'docker',
        'runtime_config' => ['image' => 'powersync/service:1', 'ports' => ['10.44.0.3:8080:80/tcp'], 'environment' => ['SECRET' => 'private-service-sentinel']],
        'status' => 'active', 'desired_state' => 'running', 'restart_policy' => 'unless-stopped']);

    return [$node, $instance, $route, $process];
}

function path_proxy_url(Instance $instance): string
{
    return '/api/v1/instances/'.$instance->id.'/path-proxy';
}

it('publishes and replaces one prefix on the existing app Route without exposing Process configuration', function (): void {
    [$node, $instance, $route, $process] = path_proxy_fixture();
    $url = '/api/v1/instances/hauzer.app/path-proxy';
    $this->putJson($url, ['process_id' => $process->id, 'port' => 8080])->assertOk()
        ->assertJsonPath('data.endpoint', 'https://hauzer.app/powersync')->assertJsonPath('data.route_id', $route->id)
        ->assertDontSee('private-service-sentinel');
    $this->getJson($url)->assertOk()->assertJsonPath('data.process_id', $process->id)->assertJsonPath('data.enabled', true);
    $site = app(DevelopmentSiteRepository::class)->forNode($node)->where('domain', 'hauzer.app')->sole();
    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));
    expect($caddy)->toContain('path /powersync /powersync/*', 'uri strip_prefix /powersync', 'reverse_proxy http://10.44.0.3:8080', 'flush_interval -1', 'read_timeout 1h', 'response_header_timeout 1h')
        ->not->toContain('private-service-sentinel');
    $this->putJson($url, ['process_id' => $process->id, 'port' => 8080, 'prefix' => '/sync-data'])->assertOk()->assertJsonPath('data.endpoint', 'https://hauzer.app/sync-data');
    expect(InstancePathProxy::query()->count())->toBe(1)->and(Route::query()->count())->toBe(1)
        ->and($instance->environmentValues()->count())->toBe(0)
        ->and(app(NodeCaddyBuilds::class)->built)->toBe(['app-prod', 'app-prod']);
    $this->deleteJson($url)->assertOk()->assertJsonPath('data.instance_id', $instance->id);
    expect(InstancePathProxy::query()->count())->toBe(0)->and(Route::query()->count())->toBe(1)->and(Process::query()->count())->toBe(1);
    $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'path_proxy.missing');
});

it('rejects invalid and unsupported publication input with 422 before publishing', function (array $fields): void {
    [, $instance, , $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080, ...$fields])->assertUnprocessable();
    expect(app(NodeCaddyBuilds::class)->built)->toBe([]);
})->with([
    'nested path' => [['prefix' => '/power/sync']], 'trailing slash' => [['prefix' => '/powersync/']],
    'unsafe path' => [['prefix' => "/sync\nrespond 200"]], 'mixed case' => [['prefix' => '/PowerSync']],
    'reverb app' => [['prefix' => '/app']], 'reverb apps' => [['prefix' => '/apps']], 'orbit' => [['prefix' => '/__orbit']],
    'zero port' => [['port' => 0]], 'too large port' => [['port' => 65536]], 'string port' => [['port' => '8080']],
    'URL' => [['upstream' => 'http://10.44.0.3:8080']], 'missing process' => [['process_id' => 98765]],
]);

it('rejects a Process without the exact WireGuard TCP published port with 409', function (array $ports): void {
    [, $instance, , $process] = path_proxy_fixture();
    $process->update(['runtime_config' => ['ports' => $ports]]);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertConflict()->assertJsonPath('error.code', 'path_proxy.upstream_invalid');
    expect(app(NodeCaddyBuilds::class)->built)->toBe([]);
})->with([
    'container only' => [['10.44.0.3:9999:8080']], 'loopback' => [['127.0.0.1:8080:80']],
    'wildcard' => [['0.0.0.0:8080:80']], 'implicit wildcard' => [['8080:80']],
    'udp' => [['10.44.0.3:8080:80/udp']], 'other node' => [['10.44.0.99:8080:80']],
]);

it('returns 409 for Laravel collisions before storing intent', function (): void {
    [, $instance, , $process] = path_proxy_fixture();
    $this->inspector->shouldReceive('hasCollisions')->withArgs(fn (Instance $target, array $prefixes): bool => $target->is($instance) && $prefixes === ['/powersync'])->once()->andReturn(true);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_collision');
    expect(InstancePathProxy::query()->count())->toBe(0)->and(app(NodeCaddyBuilds::class)->built)->toBe([]);
});

it('retains set and withdrawal intent after build failures for retry', function (): void {
    [, $instance, $route, $process] = path_proxy_fixture();
    $url = path_proxy_url($instance);
    app(NodeCaddyBuilds::class)->failNext('app-prod');
    $this->putJson($url, ['process_id' => $process->id, 'port' => 8080])->assertStatus(502)->assertJsonPath('error.code', 'path_proxy.publication_failed');
    expect(InstancePathProxy::query()->sole()->enabled)->toBeTrue();
    $this->putJson($url, ['process_id' => $process->id, 'port' => 8080])->assertOk();
    app(NodeCaddyBuilds::class)->failNext('app-prod');
    $this->deleteJson($url)->assertStatus(502)->assertJsonPath('error.code', 'path_proxy.publication_failed');
    $this->getJson($url)->assertOk()->assertJsonPath('data.enabled', false);
    $this->patchJson('/api/v1/routes/'.$route->id, ['publication' => 'private'])->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_in_use');
    $this->deleteJson($url)->assertOk();
    expect(InstancePathProxy::query()->count())->toBe(0);
});

it('refuses Route and Process changes while a publication exists', function (): void {
    [, $instance, $route, $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $this->patchJson('/api/v1/routes/'.$route->id, ['domain' => 'other.app'])->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_in_use');
    $this->deleteJson('/api/v1/routes/'.$route->id.'/target')->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_in_use');
    expect(fn () => app(DestroyInstanceAction::class)->execute($instance, force: true))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('path_proxy.route_in_use');
    });
    expect(fn () => app(DestroyProcessAction::class)->execute($process))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('path_proxy.process_in_use');
    });
});

it('reports new Laravel collisions through Doctor', function (): void {
    [$node, $instance, , $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $this->inspector->shouldReceive('hasCollisions')->andReturn(true);
    $report = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, null, null, null)));
    expect(collect($report->issues)->pluck('code')->all())->toBe(['route.path_proxy_collision']);
});

it('returns 409 for private or unfinished Routes and unsupported Instances', function (array $routeFields, array $instanceFields): void {
    [, $instance, $route, $process] = path_proxy_fixture();
    $route->update($routeFields);
    $instance->update($instanceFields);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertConflict()->assertJsonPath('error.code', 'path_proxy.public_route_invalid');
    expect(InstancePathProxy::query()->count())->toBe(0)->and(app(NodeCaddyBuilds::class)->built)->toBe([]);
})->with([
    'private' => [['publication' => 'private'], []], 'pending' => [['status' => 'pending'], []],
    'target transition' => [['target_set_intent' => ['targets' => []]], []], 'partial targets' => [['target_set_step' => 'router-published'], []],
    'failed transition' => [['failed_step' => 'router-published', 'error_code' => 'route.failed'], []], 'non Laravel' => [[], ['source_is_laravel' => false]],
    'inactive instance' => [[], ['status' => 'source_resolved']],
]);

it('requires access to both the app and upstream Nodes on set and an active peer on reads', function (): void {
    [$node, $instance, , $process] = path_proxy_fixture();
    $caller = Node::query()->create(['public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'name' => 'operator', 'status' => 'active', 'wireguard_ip' => '10.44.0.9']);
    $node->roles()->where('role', RoleName::Gateway)->delete();
    $this->markAsGateway($process->owner);
    $caller->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    expect(InstancePathProxy::query()->count())->toBe(0);
    $caller->accessibleNodes()->attach($process->owner_id);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->getJson(path_proxy_url($instance))->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
});

it('refuses Process updates before executing a runtime change', function (): void {
    [, $instance, , $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $data = new AddProcessData(targetType: ProcessTargetType::Node, targetId: $process->owner_id, name: $process->name,
        runtime: ProcessRuntime::Docker, command: [], image: 'powersync/service:2', workingDirectory: null, environment: [], ports: [], volumes: [], restartPolicy: 'unless-stopped', start: true);
    expect(fn () => app(UpdateProcessAction::class)->execute($process, $data))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('path_proxy.process_in_use');
    });
    expect($process->refresh()->runtime_config['image'])->toBe('powersync/service:1');
});

it('checks every pool target before publication and prevents another Instance from replacing it', function (): void {
    [, $instance, $route, $process] = path_proxy_fixture();
    $other = $instance->replicate();
    $otherNode = Node::query()->create(['public_ssh_host' => '192.0.2.5', 'name' => 'other-prod', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.5', 'status' => 'active', 'cluster_id' => $instance->node->cluster_id]);
    orbit_test_set_app_placement_role($otherNode, true);
    $other->node_id = $otherNode->id;
    $other->name = 'second';
    $other->save();
    $route->targets()->create(['instance_id' => $other->id, 'position' => 1]);
    $this->inspector->shouldReceive('hasCollisions')->withArgs(fn (Instance $target): bool => $target->is($other))->once()->andReturn(true);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_collision');
    $this->inspector->shouldReceive('hasCollisions')->andReturn(false);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $this->putJson(path_proxy_url($other), ['process_id' => $process->id, 'port' => 8080])->assertConflict()->assertJsonPath('error.code', 'path_proxy.route_conflict');
});

it('preserves reserved paths and reports an invalid upstream instead of forwarding to the app after drift', function (): void {
    [$node, $instance, , $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $process->update(['runtime_config' => ['ports' => ['127.0.0.1:8080:80']]]);
    $sites = app(DevelopmentSiteRepository::class)->forNode($node);
    expect(app(DevelopmentCaddyConfigRenderer::class)->render($sites))->toContain('respond "Orbit path proxy unavailable" 503');
    $report = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, null, null, null)));
    expect(collect($report->issues)->pluck('code')->all())->toBe(['route.path_proxy_upstream_invalid']);
});

it('reports an unverifiable collision check without calling the Node when it is unreachable', function (): void {
    [$node, $instance, , $process] = path_proxy_fixture();
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk();
    $this->inspector->shouldNotReceive('hasCollisions');
    $report = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)));
    expect(collect($report->issues)->pluck('code')->all())->toBe(['route.path_proxy_inspection_failed']);
});

it('exposes set show and unset through generated MCP tools', function (): void {
    [, $instance, , $process] = path_proxy_fixture();
    $call = function (string $operation, array $arguments): array {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'instance-path-proxy-'.$operation, 'arguments' => $arguments],
        ])->assertOk();
        expect($response->json('result.isError'))->toBeFalse();

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    };
    expect($call('set', ['instance' => 'hauzer.app', 'process_id' => $process->id, 'port' => 8080])['data']['endpoint'])->toBe('https://hauzer.app/powersync');
    expect($call('show', ['instance' => (string) $instance->id])['data']['enabled'])->toBeTrue();
    expect($call('unset', ['instance' => 'hauzer.app'])['data']['instance_id'])->toBe($instance->id);
});

it('records path proxy success and refusal response fixtures', function (): void {
    [, $instance, , $process] = path_proxy_fixture();
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    record_fixture($this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertOk(),
        'path-proxies/instance-path-proxy-set/default', SetInstancePathProxyRequest::class,
        'PUT /api/v1/instances/{instance}/path-proxy');
    record_fixture($this->getJson(path_proxy_url($instance))->assertOk(), 'path-proxies/instance-path-proxy-show/default',
        ShowInstancePathProxyRequest::class, 'GET /api/v1/instances/{instance}/path-proxy');
    record_fixture($this->deleteJson(path_proxy_url($instance))->assertOk(), 'path-proxies/instance-path-proxy-unset/default',
        UnsetInstancePathProxyRequest::class, 'DELETE /api/v1/instances/{instance}/path-proxy');
    record_fixture($this->getJson(path_proxy_url($instance))->assertNotFound(), 'path-proxies/instance-path-proxy-show/missing',
        ShowInstancePathProxyRequest::class, 'GET /api/v1/instances/{instance}/path-proxy');
});

it('recreates enabled intent and withdraws disabled intent through the Node convergence Caddy source', function (): void {
    [$node, $instance, , $process] = path_proxy_fixture();
    $builds = app(NodeCaddyBuilds::class);
    $builds->failNext($node->name);
    $this->putJson(path_proxy_url($instance), ['process_id' => $process->id, 'port' => 8080])->assertStatus(502);
    $renderer = new NodeCaddyfileRenderer([
        app(RouteCaddySiteSource::class),
    ]);
    $published = $renderer->render($node);
    expect($published->problems)->toBe([])
        ->and($published->content)->toContain('path /powersync /powersync/*', 'reverse_proxy http://10.44.0.3:8080');
    $builds->failNext($node->name);
    $this->deleteJson(path_proxy_url($instance))->assertStatus(502);
    $withdrawn = $renderer->render($node);
    expect($withdrawn->problems)->toBe([])
        ->and($withdrawn->content)->not->toContain('path /powersync', 'reverse_proxy http://10.44.0.3:8080')
        ->and($withdrawn->version)->not->toBe($published->version);
});
