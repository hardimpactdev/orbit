<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\CustomProxyProcessListener;
use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyListeners;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;

it('renders admitted candidate addresses and TLD without changing Node or Route rows', function (): void {
    $node = Node::query()->create(['name' => 'prospective-route', 'public_ssh_host' => '192.0.2.31', 'wireguard_ip' => '10.44.0.31', 'tld' => 'old.test', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Candidate', 'slug' => 'candidate', 'repository_url' => 'https://example.test/candidate.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'development', 'checkout_path' => '/srv/candidate', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'literal.old.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $stored = $node->fresh()->getAttributes();
    $context = app(RouteRenderContext::class);
    expect(app(RouteRenderContext::class))->toBe($context);
    $rendered = $context->render(new NativeRouteNodeRender($node->id, '10.44.0.41', 'next.test'), function () use ($node): array {
        return [app(DevelopmentDnsConfigRenderer::class)->render(),
            app(DevelopmentSiteRepository::class)->forNode($node)->firstOrFail()->nodeAddress,
            NodeCaddyListeners::forSites($node)->wireGuard,
            app(DevelopmentDnsConfigRenderer::class)->registeredRequesters()];
    });
    expect($rendered[0])->toContain('address=/.next.test/10.44.0.41')->toContain('host-record=literal.old.test,10.44.0.41')->not->toContain('address=/.old.test/')
        ->and($rendered[1])->toBe('10.44.0.41')->and($rendered[2])->toBe('10.44.0.41')
        ->and($rendered[3]['10.44.0.41'])->toBe($node->id)->and($node->fresh()->getAttributes())->toBe($stored)
        ->and($route->fresh()->domain)->toBe('literal.old.test');
    expect(app(DevelopmentDnsConfigRenderer::class)->render())->toContain('address=/.old.test/10.44.0.31')
        ->and(NodeCaddyListeners::forSites($node)->wireGuard)->toBe('10.44.0.31');
});

it('includes a provisioning admitted guest whose stored address is absent and clears context on error', function (): void {
    $node = Node::query()->create(['name' => 'prospective-new', 'public_ssh_host' => '192.0.2.32', 'wireguard_ip' => null, 'tld' => null, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'provisioning']);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'provisioning']);
    $context = app(RouteRenderContext::class);
    $candidate = new NativeRouteNodeRender($node->id, '10.44.0.42', 'fresh.test');
    $rendered = $context->render($candidate, fn (): string => app(DevelopmentDnsConfigRenderer::class)->render());
    expect($rendered)->toContain('address=/.fresh.test/10.44.0.42')->and($node->fresh()->wireguard_ip)->toBeNull();
    expect(fn () => $context->render($candidate, fn () => throw new RuntimeException('render failed')))->toThrow(RuntimeException::class);
    expect($context->nodeId())->toBeNull()->and($context->wireguardIp($node))->toBeNull();
    expect(fn () => new NativeRouteNodeRender($node->id, 'bad-ip', 'fresh.test'))->toThrow(ResourceOperationException::class);
});

it('renders candidate workload upstreams on a different stored Router and restores the ordinary render', function (): void {
    $cluster = Cluster::query()->create(['name' => 'prospective-peer', 'tld' => 'peer.test', 'state' => 'active']);
    $workload = instance_analytics_node('prospective-workload', '10.44.0.51', $cluster, RoleName::AppDev);
    $router = instance_analytics_node('prospective-router', '10.44.0.52', $cluster, RoleName::Router);
    $workload->update(['lan_ip' => null]);
    $project = Project::query()->create(['name' => 'Prospective Peer', 'slug' => 'prospective-peer', 'repository_url' => 'https://example.test/peer.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $workload->id, 'name' => 'development', 'checkout_path' => '/srv/peer', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'literal.peer.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $source = app(RouteCaddySiteSource::class);
    $stored = $source->sites($router)[0]->body;
    $candidate = app(RouteRenderContext::class)->render(new NativeRouteNodeRender($workload->id, '10.44.0.61', null), fn (): string => $source->sites($router)[0]->body);
    expect($stored)->toContain('10.44.0.51')->and($candidate)->toContain('10.44.0.61')->not->toContain('10.44.0.51')
        ->and($source->sites($router)[0]->body)->toBe($stored)->and($workload->fresh()->wireguard_ip)->toBe('10.44.0.51');
});

it('preserves an explicitly recorded public Process port during prospective Node rendering', function (): void {
    $node = Node::query()->create(['name' => 'prospective-process', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.71', 'wireguard_ip' => '10.44.0.71', 'user' => 'orbit']);
    $ingress = Node::query()->create(['name' => 'prospective-ingress', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.72', 'wireguard_ip' => '10.44.0.72', 'user' => 'orbit']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'explicit-upstream',
        'runtime' => ProcessRuntime::Docker, 'working_directory' => '/app',
        'runtime_config' => ['image' => 'executor:latest', 'command' => ['executor'], 'environment' => [], 'ports' => ['10.44.0.71:8095:80/tcp'], 'volumes' => []],
        'restart_policy' => 'unless-stopped', 'desired_state' => DesiredProcessState::Running, 'status' => 'active']);
    $listener = app(CustomProxyProcessListener::class);
    $upstream = app(RouteRenderContext::class)->render(new NativeRouteNodeRender($node->id, '10.44.0.81', null), fn () => $listener->resolvePublic($process, $ingress));
    expect($upstream->host)->toBe('10.44.0.71')->and($upstream->port)->toBe(8095)
        ->and($process->fresh()->runtime_config['ports'])->toBe(['10.44.0.71:8095:80/tcp']);
});
