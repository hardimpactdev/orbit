<?php

declare(strict_types=1);

use App\Actions\Doctor\RouteDoctorProbe;
use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\UpdateProcessAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Processes\AddProcessData;
use App\Data\Processes\UpdateProcessData;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\CustomProxyRouteInspector;
use App\Domain\Doctor\CustomProxyRouteObservation;
use App\Domain\Doctor\DoctorInspectionScope;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Routes\CustomProxyRouteProjector;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Firewall\NativeLiveUfwReader;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Models\Cluster;
use App\Models\FirewallRule;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteCustomProxy;
use App\Models\RouteTarget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeCustomProxyRouteProjector;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;
use Tests\Support\ProcessesApiFakeRuntimeManager;
use Tests\Support\RouteSwampGatewayFixture;
use Tests\TestCase;

function customProxyAppRouteFixture(Project $project, Node $node, string $domain, Instance $target): Route
{
    $route = Route::query()->create([
        'kind' => RouteKind::App,
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => $domain,
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['instance_id' => $target->id, 'position' => 0]);

    return $route;
}

require_once __DIR__.'/../../Support/CompletesRouteOperations.php';

beforeEach(function (): void {
    \Tests\Support\completesRouteOperations();
    $this->projector = new FakeCustomProxyRouteProjector;
    $this->removal = new FakeRouteRemovalProjector;
    app()->instance(CustomProxyRouteProjector::class, $this->projector);
    app()->instance(RouteRemovalProjector::class, $this->removal);

    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->routeSwamp = new RouteSwampGatewayFixture($this);
    $this->routeSwamp->agents->answer('firewall.inspect', static fn (array $input, Node $node): array => [
        'backend' => 'active', 'ownerships' => [], 'matches' => true,
        'live' => [['comment' => 'orbit:wireguard-members', 'action' => 'allow', 'direction' => 'in',
            'source' => 'any', 'destination' => $node->wireguard_ip, 'port' => 'any', 'protocol' => 'any',
            'in_interface' => 'orbit', 'out_interface' => null, 'family' => 'v4'],
            ...$node->firewallRules()->get()->map(static fn ($rule): array => (function () use ($rule): array {
                $shape = FirewallInspectionTarget::fromRule($rule)->shape;

                return new UfwRuleShape(...get_object_vars($shape))->toAgentInput();
            })())->all()],
    ]);
    app()->instance(NativeLiveUfwReader::class,
        new NativeLiveUfwReader($this->routeSwamp->agents, app(AgentContract::class)));

    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->node = custom_proxy_node('beast', '10.44.0.7');
    $this->target = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'checkout_path' => '/srv/orbit/apps/acme/main',
        'status' => InstanceState::Active,
    ]);
});

describe('custom proxy Route create', function (): void {
    it('creates a Node-owned custom proxy from an upstream URL', function (string $domain): void {
        $created = $this
            ->postJson('/api/v1/routes', [
                'domain' => $domain,
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.node_id', $this->node->id)
            ->assertJsonPath('data.cluster_id', null)
            ->assertJsonPath('data.domain', $domain)
            ->assertJsonPath('data.publication', 'private')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.targets', [])
            ->assertJsonPath('data.process_id', null)
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788');

        $routeId = $created->json('data.id');

        expect(Route::query()->findOrFail($routeId)->kind)
            ->toBe(RouteKind::CustomProxy)
            ->and(RouteCustomProxy::query()->where('route_id', $routeId)->sole()->upstream)
            ->toBe('http://127.0.0.1:4788')
            ->and(RouteTarget::query()->where('route_id', $routeId)->count())
            ->toBe(0)
            ->and(array_column(array_filter($this->routeSwamp->foreign->calls, static fn ($call): bool => $call['kind'] === 'route.caddy_converge'), 'node_id'))
            ->toBe([$this->node->id]);
    })->with([
        'executor.orbit',
        'grafana.internal',
        'foo.bar',
        'something.test',
        'cli-proxy-api.orbit',
        'ai-router.orbit',
        'collector.cli-proxy-api.orbit',
    ]);

    it('creates a custom proxy from a Node Process listener', function (): void {
        $process = custom_proxy_process($this->node, 'executor', ['127.0.0.1:4788:80/tcp']);

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'process_id' => $process->id,
            ])
            ->completeRoute()->assertOk()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.process_id', $process->id)
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788');
    });
});

describe('custom proxy Route refusals', function (): void {
    it('refuses reserved platform hostnames', function (string $domain): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => $domain,
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.domain_conflict');

        expect(Route::query()->count())->toBe(0);
    })->with([
        'gateway.orbit',
        'metrics.orbit',
        'collector.ai-router.orbit',
    ]);

    it('refuses stealing a Project Route domain', function (): void {
        customProxyAppRouteFixture($this->orbitApp, $this->node, 'executor.orbit', $this->target);

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.domain_conflict');

        expect(Route::query()->where('kind', RouteKind::CustomProxy)->count())->toBe(0);
    });

    it('refuses a remote or non-loopback upstream', function (string $upstream): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => $upstream,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'route.upstream_invalid');
    })->with([
        'remote' => ['http://10.44.0.8:4788'],
        'https' => ['https://127.0.0.1:4788'],
        'path' => ['http://127.0.0.1:4788/status'],
    ]);

    it('refuses mixing a Project owner with a custom proxy upstream', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'project_id' => $this->orbitApp->id,
                'domain' => 'executor.orbit',
                'publication' => 'private',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->assertUnprocessable();

        expect(Route::query()->count())->toBe(0);
    });

    it('returns the existing Route on an identical custom proxy retry', function (): void {
        $first = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk();

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Route::query()->count())->toBe(1);
    });

    it('refuses a conflicting custom proxy retry', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk();

        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:9000',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.retry_conflict');

        expect(RouteCustomProxy::query()->sole()->upstream)->toBe('http://127.0.0.1:4788');
    });
});

describe('custom proxy Route list, show, destroy, and App mutations', function (): void {
    it('lists and shows kind and upstream instead of App targets', function (): void {
        $created = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk();
        $routeId = $created->json('data.id');

        $this
            ->getJson('/api/v1/routes')
            ->completeRoute()->assertOk()
            ->assertJsonPath('data.0.id', $routeId)
            ->assertJsonPath('data.0.kind', 'custom_proxy')
            ->assertJsonPath('data.0.upstream', 'http://127.0.0.1:4788')
            ->assertJsonPath('data.0.targets', []);

        $this
            ->getJson("/api/v1/routes/{$routeId}")
            ->completeRoute()->assertOk()
            ->assertJsonPath('data.kind', 'custom_proxy')
            ->assertJsonPath('data.upstream', 'http://127.0.0.1:4788')
            ->assertJsonPath('data.process_id', null);
    });

    it('destroys a custom proxy without touching a Project Route', function (): void {
        $appRoute = customProxyAppRouteFixture($this->orbitApp, $this->node, 'app.example.test', $this->target)->id;
        $proxyRoute = custom_proxy_seed($this, [
            'domain' => 'executor.orbit',
            'node_id' => $this->node->id,
            'upstream' => 'http://127.0.0.1:4788',
        ])
            ->completeRoute()->assertOk()
            ->json('data.id');

        $this->deleteJson("/api/v1/routes/{$proxyRoute}")->completeRoute()->assertOk();

        expect(Route::query()->whereKey($proxyRoute)->exists())
            ->toBeFalse()
            ->and(RouteCustomProxy::query()->count())
            ->toBe(0)
            ->and(Route::query()->whereKey($appRoute)->exists())
            ->toBeTrue()
            ->and(RouteTarget::query()->where('route_id', $appRoute)->count())
            ->toBe(1);
    });

    it('refuses Set, Update, and Clear on a custom proxy Route', function (): void {
        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk()
            ->json('data.id');

        $this
            ->putJson("/api/v1/routes/{$routeId}/target", ['instance_id' => $this->target->id])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this
            ->patchJson("/api/v1/routes/{$routeId}", ['domain' => 'other.orbit'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');
        $this
            ->deleteJson("/api/v1/routes/{$routeId}/target")
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');

        expect(Route::query()->findOrFail($routeId)->domain)
            ->toBe('executor.orbit')
            ->and(RouteTarget::query()->where('route_id', $routeId)->count())
            ->toBe(0);
    });
});

describe('custom proxy ownership guards', function (): void {
    it('refuses Process removal while a custom proxy targets the Process', function (): void {
        $process = custom_proxy_process($this->node, 'executor', ['127.0.0.1:4788:80/tcp']);
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'process_id' => $process->id,
            ])
            ->completeRoute()->assertOk();

        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('remove');

        expect(fn () => new DestroyProcessAction($runtime, new ProcessTargetResolver, guards: app()->tagged(ProcessRemovalGuard::class))->execute($process))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.has_routes');
            })
            ->and($process->fresh())
            ->not->toBeNull();
    });

    it('refuses Node removal while a custom proxy references the Node', function (): void {
        $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk();

        expect(fn () => app(RouteRemovalGuard::class)->assertNodeRemovable($this->node))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.reconciliation_required');
            })
            ->and(fn () => app(RemoveNodeAction::class)->execute($this->node, $this->gateway, offline: true, force: true))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('route.reconciliation_required');
            })
            ->and($this->node->fresh())
            ->not->toBeNull();
    });
});

describe('custom proxy AppDev sites', function (): void {
    it('emits a Node-direct local HTTP site even when the serving Node is in a Cluster', function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $router = custom_proxy_node('edge-router', '10.45.0.10');
        $router->update(['cluster_id' => $cluster->id]);
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        $this->node->update(['cluster_id' => $cluster->id]);

        $routeId = $this
            ->postJson('/api/v1/routes', [
                'domain' => 'executor.orbit',
                'node_id' => $this->node->id,
                'upstream' => 'http://127.0.0.1:4788',
            ])
            ->completeRoute()->assertOk()
            ->json('data.id');

        $sites = new DevelopmentSiteRepository;
        $onNode = $sites->forNode($this->node->refresh());
        $onRouter = $sites->forNode($router->refresh());

        expect($onNode)
            ->toHaveCount(1)
            ->and($onNode->first()?->domain)
            ->toBe('executor.orbit')
            ->and($onNode->first()?->nodeId)
            ->toBe($this->node->id)
            ->and($onNode->first()?->localHttpUpstream)
            ->toBe('127.0.0.1:4788')
            ->and($onNode->first()?->isLocalHttpProxy())
            ->toBeTrue()
            ->and($onNode->first()?->scope)
            ->toBe("route-{$routeId}")
            ->and($onRouter)
            ->toHaveCount(0);
    });
});

function custom_proxy_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

function custom_proxy_process(Node $node, string $name, array $ports): Process
{
    return Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'executor:latest',
            'command' => ['executor'],
            'environment' => [],
            'ports' => $ports,
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

/** @return array{domain: string, ingress_node_id: int, process_id: int, publication: string} */
function public_process_body(Node $node): array
{
    $cluster = Cluster::query()->create(['name' => 'public-edge', 'state' => ClusterState::Active]);
    $ingress = custom_proxy_node('public-ingress', '10.44.0.4');
    $ingress->update(['cluster_id' => $cluster->id]);
    foreach ([RoleName::Router, RoleName::Ingress] as $role) {
        $ingress->roles()->create(['cluster_id' => $cluster->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }
    $process = custom_proxy_process($node, 'powersync', [$node->wireguard_ip.':8095:80/tcp']);

    return ['domain' => 'sync.example.com', 'ingress_node_id' => $ingress->id, 'process_id' => $process->id, 'publication' => 'public'];
}

describe('public Process hostnames', function (): void {
    it('activates a Cluster-scoped Process outside the Cluster and renders only on its Ingress', function (): void {
        $edge = new FakePublicRouteEdgeProjector;
        app()->instance(PublicRouteEdgeProjector::class, $edge);
        $body = public_process_body($this->node);
        $created = $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()
            ->assertJsonPath('data.kind', 'custom_proxy')->assertJsonPath('data.node_id', null)
            ->assertJsonPath('data.cluster_id', Node::query()->findOrFail($body['ingress_node_id'])->cluster_id)->assertJsonPath('data.process_id', $body['process_id'])
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.replacement_step', 'ingress-firewall');
        $route = Route::query()->findOrFail($created->json('data.id'));
        $ingress = Node::query()->where('name', 'public-ingress')->sole();
        $sites = new DevelopmentSiteRepository;
        $site = $sites->forNode($ingress)->sole();
        expect($site->publicListener)->toBeTrue()
            ->and($site->localHttpUpstream)->toBe('10.44.0.7:8095')
            ->and($sites->forNode($this->node))->toBeEmpty()
            ->and($this->node->fresh()->cluster_id)->toBeNull()
            ->and($this->projector->routeIds)->toBeEmpty();
        $block = new DevelopmentCaddyConfigRenderer()->render(collect([$site]));
        expect($block)->toContain('tls force_automate', 'reverse_proxy 10.44.0.7:8095', 'flush_interval -1', 'read_timeout 1h')
            ->not->toContain('tls_insecure_skip_verify', 'header_up Host');
        $effects = count($this->routeSwamp->foreign->calls);
        expect(array_column($this->routeSwamp->foreign->calls, 'kind'))->toContain('route.public_verify', 'route.public_firewall');
        $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.id', $route->id);
        expect($this->routeSwamp->foreign->calls)->toHaveCount($effects);
    });

    it('resumes a failed public activation without creating a second Route', function (string $failure): void {
        $body = public_process_body($this->node);
        $this->routeSwamp->foreign->observe = function (Operation $child) use ($failure): void {
            $stage = $child->parent?->state['route_active_stage'] ?? null;
            if ($stage === $failure) {
                $this->routeSwamp->foreign->failures[$child->child_key] = 1;
                $this->routeSwamp->foreign->observe = null;
            }
        };
        $failed = $this->routeSwamp->finish($this->postJson('/api/v1/routes', $body));
        expect($failed->status->value)->toBe('failed');
        $route = Route::query()->where('domain', $body['domain'])->sole();
        expect($route->status->value)->toBe('activating')->and($route->failed_step)->toBe($failure);
        $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.id', $route->id)
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.failed_step', null)
            ->assertJsonPath('data.replacement_step', 'ingress-firewall');
        expect(Route::query()->count())->toBe(1);
    })->with(['public-edge-verified', 'public-activated', 'ingress-firewall']);

    it('refuses reserved public names before publishing with 409', function (string $domain): void {
        $body = public_process_body($this->node);
        $this->postJson('/api/v1/routes', [...$body, 'domain' => $domain])->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
        expect(Route::query()->count())->toBe(0);
    })->with(['gateway.orbit', 'collector.ai-router.orbit']);

    it('refuses missing or mixed public scope with 422', function (array $changes): void {
        $body = public_process_body($this->node);
        $this->postJson('/api/v1/routes', [...$body, ...$changes])->assertUnprocessable();
        expect(Route::query()->count())->toBe(0);
    })->with([[['cluster_id' => 9]], [['node_id' => 1]], [['upstream' => 'http://127.0.0.1:8095']], [['instance_id' => 1]]]);

    it('refuses Process and workload Node removal while the public Route exists', function (): void {
        app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
        $body = public_process_body($this->node);
        $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk();
        $process = Process::query()->findOrFail($body['process_id']);
        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('remove');
        expect(fn () => new DestroyProcessAction($runtime, new ProcessTargetResolver, guards: app()->tagged(ProcessRemovalGuard::class))->execute($process))
            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('process.has_routes'));
        expect(fn () => app(RouteRemovalGuard::class)->assertNodeRemovable($this->node))->toThrow(ResourceOperationException::class);
    });

    it('destroys a live public Process Route while retaining its Process', function (): void {
        app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
        $body = public_process_body($this->node);
        $id = custom_proxy_seed($this, $body)->completeRoute()->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/routes/'.$id)->completeRoute()->assertOk();
        expect(Route::query()->whereKey($id)->exists())->toBeFalse()
            ->and(RouteCustomProxy::query()->where('process_id', $body['process_id'])->exists())->toBeFalse()
            ->and(Process::query()->whereKey($body['process_id'])->exists())->toBeTrue();
    });
});

it('requires access to the public edge and the separate Process Node', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $unrelated = custom_proxy_node('unrelated-member', '10.44.0.91');
    $unrelated->update(['cluster_id' => Node::query()->findOrFail($body['ingress_node_id'])->cluster_id]);
    $caller = custom_proxy_node('limited-operator', '10.44.0.90');
    $ingress = Node::query()->where('name', 'public-ingress')->sole();
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $ingress->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->postJson('/api/v1/routes', $body)->assertForbidden();
    expect(Route::query()->count())->toBe(0);
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $this->node->id]);
    $id = custom_proxy_seed($this, $body)->completeRoute()->assertOk()->json('data.id');
    $this->getJson('/api/v1/routes/'.$id)->completeRoute()->assertOk();
    NodeAccess::query()->where('serving_node_id', $this->node->id)->delete();
    $this->deleteJson('/api/v1/routes/'.$id)->assertForbidden();
    expect(Route::query()->whereKey($id)->exists())->toBeTrue();
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $this->node->id]);
    $this->deleteJson('/api/v1/routes/'.$id)->completeRoute()->assertOk();
});

it('reports public Process Route drift on the Ingress and leaves the service Node out of Route inspection', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $id = $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->json('data.id');
    $inspector = Mockery::mock(CustomProxyRouteInspector::class);
    $inspector->shouldReceive('inspect')->once()->andReturn(new CustomProxyRouteObservation(true, false, true, false, false));
    app()->instance(CustomProxyRouteInspector::class, $inspector);
    $probe = app(RouteDoctorProbe::class);
    $ingress = Node::query()->where('name', 'public-ingress')->sole();
    $context = new DoctorNodeContext($ingress, new NodeInspectionData(true, null, null, null));
    $scope = new DoctorInspectionScope([
        $ingress->id => $context,
        $this->node->id => new DoctorNodeContext($this->node, $context->inspection),
    ]);
    $report = $probe->inspect($context->withScope($scope));
    expect($report->checked)->toBe(1)
        ->and(array_map(fn ($issue) => $issue->code, $report->issues))->toBe(['route.certificate_mismatch', 'route.upstream_unreachable', 'route.firewall_mismatch'])
        ->and($report->issues[0]->resourceId)->toBe($id);
    expect($probe->resources($this->node))->toBeEmpty();
});

it('keeps the Ingress ports open while any public Route remains and closes them after the last one leaves', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $first = custom_proxy_seed($this, $body)->completeRoute()->assertOk()->json('data.id');
    $second = custom_proxy_seed($this, [...$body, 'domain' => 'sync.other.example.com'])->completeRoute()->assertOk()->json('data.id');
    $ingress = Node::query()->where('name', 'public-ingress')->sole();
    $catalog = new NodeFirewallRuleCatalog;
    expect($catalog->forRole($ingress, RoleName::Ingress))->toHaveCount(2);
    $this->deleteJson('/api/v1/routes/'.$first)->completeRoute()->assertOk();
    expect($catalog->forRole($ingress->fresh(), RoleName::Ingress))->toHaveCount(2);
    $this->routeSwamp->foreign->failures['firewall.node-'.$ingress->id] = 1;
    $failed = $this->routeSwamp->finish($this->deleteJson('/api/v1/routes/'.$second));
    expect($failed->status->value)->toBe('failed');
    expect(Route::query()->findOrFail($second)->failed_step)->toBe('firewall');
    $this->deleteJson('/api/v1/routes/'.$second)->completeRoute()->assertOk();
    expect($catalog->forRole($ingress->fresh(), RoleName::Ingress))->toBeEmpty();
    expect($catalog->desiredBaseline($this->node)[1]->shape->comment ?? $catalog->desiredBaseline($this->node)[0]->shape->comment)->toBe('orbit:wireguard-members');
});

it('refuses an owned domain and conflicting public Process intent with 409', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk();
    $other = custom_proxy_process($this->node, 'other-sync', [$this->node->wireguard_ip.':8096:80/tcp']);
    $this->postJson('/api/v1/routes', [...$body, 'process_id' => $other->id])->assertConflict()->assertJsonPath('error.code', 'route.retry_conflict');
    customProxyAppRouteFixture($this->orbitApp, $this->node, 'owned.example.com', $this->target);
    $this->postJson('/api/v1/routes', [...$body, 'domain' => 'owned.example.com'])->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
});

it('refuses a public Process Route without an active Ingress before storing intent', function (): void {
    $body = public_process_body($this->node);
    NodeRole::query()->where('role', RoleName::Ingress)->delete();
    $this->postJson('/api/v1/routes', $body)->assertConflict()->assertJsonPath('error.code', 'route.ingress_required');
    expect(Route::query()->count())->toBe(0);
});

it('replays an Ingress build after a public handler checkpoint whose build may not have finished', function (): void {
    $edge = new FakePublicRouteEdgeProjector;
    app()->instance(PublicRouteEdgeProjector::class, $edge);
    $body = public_process_body($this->node);
    $id = custom_proxy_seed($this, $body)->completeRoute()->assertOk()->json('data.id');
    Route::query()->whereKey($id)->update(['status' => 'activating', 'replacement_step' => 'public-activated']);
    $edge->calls = [];
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.status', 'active');
    expect(array_column($this->routeSwamp->foreign->calls, 'kind'))->toContain('route.caddy_converge', 'route.public_firewall');
});

it('preserves private Routes across the persistence upgrade and refuses a downgrade with public intent', function (): void {
    $private = ['domain' => 'private.example.test', 'node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:4788'];
    $id = $this->postJson('/api/v1/routes', $private)->completeRoute()->assertOk()->json('data.id');
    $migration = require database_path('migrations/2027_01_01_000000_allow_public_process_routes.php');
    $migration->down();
    $migration->up();
    $this->postJson('/api/v1/routes', $private)->completeRoute()->assertOk()->assertJsonPath('data.id', $id);
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk();
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Remove public Process Routes before rolling back.');
});

it('keeps the public site buildable when its Process or workload Node becomes unhealthy', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk();
    $process = Process::query()->findOrFail($body['process_id']);
    $process->update(['status' => 'failed', 'failed_step' => 'start', 'error_code' => 'process.start_failed']);
    $this->node->update(['status' => 'failed']);
    $ingress = Node::query()->where('name', 'public-ingress')->sole();
    expect(new DevelopmentSiteRepository()->forNode($ingress)->sole()->localHttpUpstream)->toBe('10.44.0.7:8095');
});

it('defaults to the Process Cluster Ingress without moving the Process Node or requiring a Router', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    $this->node->update(['cluster_id' => $ingress->cluster_id]);
    NodeRole::query()->where('role', RoleName::Router)->delete();
    Cluster::query()->findOrFail($ingress->cluster_id)->update(['state' => 'inactive']);
    unset($body['ingress_node_id']);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.cluster_id', $ingress->cluster_id)
        ->assertJsonPath('data.replacement_step', 'ingress-firewall');
    expect($this->node->fresh()->cluster_id)->toBe($ingress->cluster_id);
});

it('defaults an unclustered Process to the single Ingress serving public app Routes', function (): void {
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    $appRoute = customProxyAppRouteFixture($this->orbitApp, $this->node, 'mealou.app', $this->target);
    $appRoute->update(['node_id' => null, 'cluster_id' => $ingress->cluster_id, 'publication' => 'public', 'status' => 'active', 'replacement_step' => 'ingress-firewall']);
    unset($body['ingress_node_id']);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.cluster_id', $ingress->cluster_id);
    expect($this->node->fresh()->cluster_id)->toBeNull();
});

it('refuses missing and ambiguous default public app Ingresses and permits explicit selection', function (): void {
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    unset($body['ingress_node_id']);
    $this->postJson('/api/v1/routes', $body)->assertConflict()->assertJsonPath('error.code', 'route.ingress_required');
    $first = customProxyAppRouteFixture($this->orbitApp, $this->node, 'mealou.app', $this->target);
    $first->update(['node_id' => null, 'cluster_id' => $ingress->cluster_id, 'publication' => 'public', 'status' => 'active', 'replacement_step' => 'ingress-firewall']);
    $cluster = Cluster::query()->create(['name' => 'second-edge', 'state' => 'active']);
    $other = custom_proxy_node('second-ingress', '10.44.0.80');
    $other->update(['cluster_id' => $cluster->id]);
    foreach ([RoleName::Ingress, RoleName::Router] as $role) {
        $other->roles()->create(['cluster_id' => $cluster->id, 'role' => $role, 'status' => 'active']);
    }
    $secondTarget = $this->target->replicate();
    $secondTarget->name = 'second-app';
    $secondTarget->checkout_path = '/srv/orbit/apps/acme/second-app';
    $secondTarget->save();
    $second = customProxyAppRouteFixture($this->orbitApp, $this->node, 'hauzer.app', $secondTarget);
    $second->update(['node_id' => null, 'cluster_id' => $cluster->id, 'publication' => 'public', 'status' => 'active', 'replacement_step' => 'ingress-firewall']);
    $this->postJson('/api/v1/routes', $body)->assertConflict()->assertJsonPath('error.code', 'route.ingress_ambiguous');
    $this->postJson('/api/v1/routes', [...$body, 'ingress_node_id' => $other->id])->completeRoute()->assertOk()->assertJsonPath('data.cluster_id', $cluster->id);
});

it('refuses a missing LAN listener before storing intent and leaves private service sites unchanged', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    $ingress->update(['lan_ip' => '10.0.0.4']);
    $this->node->update(['lan_ip' => '10.0.0.6']);
    foreach (['vault.orbit', 'recall.orbit', 'executor.orbit', 'ai-router.orbit'] as $domain) {
        $this->postJson('/api/v1/routes', ['domain' => $domain, 'node_id' => $this->node->id, 'upstream' => 'http://127.0.0.1:8080'])->completeRoute()->assertOk();
    }
    $before = new DevelopmentSiteRepository()->forNode($this->node);
    $this->postJson('/api/v1/routes', $body)->assertConflict()->assertJsonPath('error.code', 'route.upstream_unresolved');
    Process::query()->findOrFail($body['process_id'])->update(['runtime_config' => ['ports' => ['10.44.0.7:8095:8080/tcp', '10.0.0.6:8095:8080/tcp']]]);
    FirewallRule::query()->create(['node_id' => $this->node->id, 'name' => 'listener-fixture', 'action' => 'allow', 'source' => '10.0.0.4', 'port' => '8095', 'protocol' => 'tcp', 'status' => 'active']);
    $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk();
    expect(new DevelopmentSiteRepository()->forNode($ingress)->sole()->localHttpUpstream)->toBe('10.0.0.6:8095')
        ->and(new DevelopmentSiteRepository()->forNode($this->node))->toEqual($before)
        ->and($this->node->fresh()->cluster_id)->toBeNull();
});

it('refuses an explicit Node without an active Ingress role', function (string $change): void {
    $body = public_process_body($this->node);
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    match ($change) {
        'node' => $ingress->update(['status' => 'failed']),
        'role' => $ingress->roles()->where('role', RoleName::Ingress)->update(['status' => 'failed']),
        'missing' => $ingress->roles()->where('role', RoleName::Ingress)->delete(),
    };
    $this->postJson('/api/v1/routes', $body)->assertConflict()->assertJsonPath('error.code', 'route.ingress_required');
    expect(Route::query()->count())->toBe(0);
})->with(['node', 'role', 'missing']);

it('lists a public Process Route only with access to both Ingress and Process', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $body = public_process_body($this->node);
    $id = $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->json('data.id');
    $ingress = Node::query()->findOrFail($body['ingress_node_id']);
    $unrelated = custom_proxy_node('unrelated-list-member', '10.44.0.91');
    $unrelated->update(['cluster_id' => $ingress->cluster_id]);
    $caller = custom_proxy_node('list-operator', '10.44.0.90');
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $unrelated->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->getJson('/api/v1/routes')->completeRoute()->assertOk()->assertJsonCount(0, 'data');
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $ingress->id]);
    $this->getJson('/api/v1/routes')->completeRoute()->assertOk()->assertJsonCount(0, 'data');
    NodeAccess::query()->create(['consumer_node_id' => $caller->id, 'serving_node_id' => $this->node->id]);
    $this->getJson('/api/v1/routes')->completeRoute()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    NodeAccess::query()->where('consumer_node_id', $caller->id)->where('serving_node_id', $ingress->id)->delete();
    $this->getJson('/api/v1/routes')->completeRoute()->assertOk()->assertJsonCount(0, 'data');
});

describe('Process updates with targeting Routes', function (): void {
    beforeEach(function (): void {
        \Tests\Support\completesRouteOperations();
        $this->runtime = new ProcessesApiFakeRuntimeManager;
        app()->instance(ProcessRuntimeManager::class, $this->runtime);
        $this->edge = new FakePublicRouteEdgeProjector;
        app()->instance(PublicRouteEdgeProjector::class, $this->edge);
    });

    it('waits for an update before resolving and reserving a Process Route', function (string $publication, string $change, string $entry): void {
        [$process, $data, $address, $retained] = process_route_race_fixture($this, $publication);
        $ports = $change === 'drop' ? [$retained] : [$retained, $address.':8096:8080/tcp'];
        $update = process_route_ports_update($process, $ports);
        $updater = app(UpdateProcessAction::class);
        $this->runtime->duringStatus = function (): void {
            $this->runtime->duringStatus = null;
            Fiber::suspend();
        };
        $peer = new Fiber(fn (): Process => $updater->execute($process, $update));
        $peer->start();
        expect($peer->isSuspended())->toBeTrue();

        app()->instance(ProcessRuntimeLease::class, new NativeProcessRuntimeLease);
        $projectionEntries = 0;
        $projection = Mockery::mock(DevelopmentProjectionOperationLock::class);
        $projection->shouldReceive('run')->andReturnUsing(function (Closure $operation) use (&$projectionEntries): mixed {
            $projectionEntries++;

            return $operation();
        });
        app()->instance(DevelopmentProjectionOperationLock::class, $projection);
        $creator = app(CreateRouteAction::class);
        $waited = false;
        Sleep::whenFakingSleep(function () use ($peer, $data, &$waited, &$projectionEntries): void {
            expect($projectionEntries)->toBe(0)
                ->and(Route::query()->where('domain', $data->domain)->exists())->toBeFalse();
            $waited = true;
            $peer->resume();
        });

        if ($change === 'drop') {
            try {
                $creator->{$entry}($data);
                test()->fail('Creation must refuse the removed listener.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode)->toBe('route.upstream_unresolved');
            }
            expect(Route::query()->where('domain', $data->domain)->exists())->toBeFalse();
        } else {
            $created = $creator->{$entry}($data)['route'];
            expect($created->status->value)->toBe('active')
                ->and($created->customProxy->upstream)->toBe('http://'.$address.':8096');
        }

        expect($waited)->toBeTrue()
            ->and($peer->isTerminated())->toBeTrue()
            ->and($process->refresh()->runtime_config['ports'])->toBe($ports);
    })->with(['private', 'public'])->with(['drop', 'replace'])->with(['execute', 'executeForRouteCreate']);

    it('protects a Process Route reservation from a concurrent listener removal', function (string $publication, string $entry): void {
        [$process, $data, , $retained] = process_route_race_fixture($this, $publication);
        $update = process_route_ports_update($process, [$retained]);
        $updater = app(UpdateProcessAction::class);
        $before = $process->runtime_config;
        $attempted = false;
        Route::creating(function (Route $route) use ($data, $updater, $process, $update, &$attempted): void {
            if ($route->domain !== $data->domain) {
                return;
            }
            $attempted = true;
            try {
                $updater->execute($process, $update);
                test()->fail('The update must not pass an in-progress Route reservation.');
            } catch (ProcessOperationException $exception) {
                expect($exception->errorCode)->toBe('process.runtime_lock_failed');
            }
        });
        app()->instance(ProcessRuntimeLease::class, new NativeProcessRuntimeLease);

        $route = app(CreateRouteAction::class)->{$entry}($data)['route'];

        expect($attempted)->toBeTrue()
            ->and($route->status->value)->toBe('active')
            ->and($process->refresh()->runtime_config)->toBe($before);
        try {
            $updater->execute($process, $update);
            test()->fail('The reserved Route must protect its listener.');
        } catch (ResourceOperationException $exception) {
            expect($exception->errorCode)->toBe('process.route_upstream_changed')
                ->and($exception->details['route_id'])->toBe($route->id);
        }
        expect($this->runtime->convergedProcessIds)->toBe([]);
    })->with(['private', 'public'])->with(['execute', 'executeForRouteCreate']);

    it('returns the Process lock error without reserving a Route when its wait expires', function (string $publication, float $budget): void {
        [$process, $data] = process_route_race_fixture($this, $publication);
        $deadline = new CommandDeadline;
        $deadline->start($budget);
        app()->instance(ProcessRuntimeLease::class, new NativeProcessRuntimeLease($deadline));
        $key = "orbit:process-runtime:{$process->owner_id}:{$process->id}";
        $owner = Cache::lock($key, 3_600);
        expect($owner->get())->toBeTrue();
        $waited = false;
        Sleep::whenFakingSleep(function () use (&$waited): void {
            $waited = true;
        });
        $body = array_filter([
            'domain' => $data->domain, 'publication' => $publication, 'process_id' => $process->id,
            'node_id' => $data->nodeId, 'ingress_node_id' => $data->ingressNodeId,
        ], fn (mixed $value): bool => $value !== null);
        $startedAt = now();

        try {
            $this->postJson('/api/v1/routes', $body)->assertStatus(502)
                ->assertJsonPath('error.code', 'process.runtime_lock_failed')
                ->assertJsonPath('error.details.step', 'lock-runtime');
            expect($waited)->toBeTrue()
                ->and(Route::query()->where('domain', $data->domain)->exists())->toBeFalse()
                ->and($startedAt->diffInSeconds(now()))->toBeLessThanOrEqual(min(30.0, $budget))
                ->and(Cache::lock($key, 60)->get())->toBeFalse();
        } finally {
            $owner->release();
        }

        $this->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->assertJsonPath('data.status', 'active');
    })->with(['private', 'public'])->with([2.0, 60.0]);

    it('keeps the Route active after a listener-preserving update', function (string $scope, string $change): void {
        [$process, $route, $address] = process_update_route_fixture($this, $scope);
        $before = $route->getAttributes();
        $proxy = $route->customProxy->getAttributes();
        $config = $process->runtime_config;
        $patch = match ($change) {
            'environment' => ['environment' => ['POWERSYNC_SYNC_CONFIG_B64' => 'test-sync-rules']],
            'image' => ['image' => 'powersync:new'],
            'container-port' => ['ports' => [$address.':8095:8081/tcp']],
            'udp-alongside-tcp' => ['ports' => [$address.':8095:8080/tcp', $address.':8096:8081/udp']],
            'other-address' => ['ports' => [$address.':8095:8080/tcp', '192.0.2.99:8096:8081/tcp']],
        };
        $config = [...$config, ...$patch];
        $edgeCalls = $this->edge->calls;
        $siteNode = $scope === 'private' ? $this->node : Node::query()->where('name', 'public-ingress')->sole();
        $sites = new DevelopmentSiteRepository()->forNode($siteNode);

        $this->patchJson('/api/v1/processes/'.$process->id, $patch)->assertAccepted()
            ->assertJsonPath('data.kind', 'process.update')->assertJsonPath('data.status', 'queued');

        expect($process->refresh()->runtime_config)->toBe($config)
            ->and($this->runtime->convergedProcessIds)->toBe([])
            ->and($route->refresh()->getAttributes())->toBe($before)
            ->and($route->customProxy->getAttributes())->toBe($proxy)
            ->and($this->edge->calls)->toBe($edgeCalls)
            ->and(new DevelopmentSiteRepository()->forNode($siteNode))->toEqual($sites);
    })->with(['private', 'wireguard', 'lan'])->with(['environment', 'image', 'container-port', 'udp-alongside-tcp', 'other-address']);

    it('applies the Route listener contract through MCP', function (string $scope): void {
        [$process, $route] = process_update_route_fixture($this, $scope);
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'process-update', 'arguments' => ['process' => $process->id, 'image' => 'powersync:new']],
        ])->completeRoute()->assertOk()->assertJsonPath('result.isError', false);
        expect($process->refresh()->runtime_config['image'])->toBe('powersync:new');

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'process-update', 'arguments' => ['process' => $process->id, 'ports' => []]],
        ])->completeRoute()->assertOk()->assertJsonPath('result.isError', true);
        $error = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        expect($error['error']['code'])->toBe('process.route_upstream_changed')
            ->and($error['error']['details']['route_id'])->toBe($route->id)
            ->and($route->refresh()->status->value)->toBe('active')
            ->and($this->runtime->convergedProcessIds)->toBe([]);
    })->with(['private', 'wireguard']);

    it('keeps listener reachability visible to Doctor after an update', function (string $scope, bool $reachable): void {
        [$process, $route] = process_update_route_fixture($this, $scope);
        $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'powersync:new'])->assertAccepted();
        $inspector = Mockery::mock(CustomProxyRouteInspector::class);
        $inspector->shouldReceive('inspect')->once()->withArgs(fn (RouteCustomProxy $proxy): bool => $proxy->route_id === $route->id)
            ->andReturn(new CustomProxyRouteObservation(true, true, true, $reachable, true));
        app()->instance(CustomProxyRouteInspector::class, $inspector);
        $serving = $scope === 'private' ? $this->node : Node::query()->where('name', 'public-ingress')->sole();
        $inspection = new NodeInspectionData(true, null, null, null);
        $context = new DoctorNodeContext($serving, $inspection);
        $selected = [$serving->id => $context, $this->node->id => new DoctorNodeContext($this->node, $inspection)];

        $report = app(RouteDoctorProbe::class)->inspect($context->withScope(new DoctorInspectionScope($selected)));

        expect($report->checked)->toBe(1)
            ->and(array_map(fn ($issue) => $issue->code, $report->issues))->toBe($reachable ? [] : ['route.upstream_unreachable'])
            ->and($route->refresh()->status->value)->toBe('active');
    })->with(['private', 'wireguard'])->with([true, false]);

    it('refuses a changed or unresolved Route listener before runtime work', function (string $scope, string $change): void {
        [$process, $route, $address] = process_update_route_fixture($this, $scope);
        $before = $process->refresh()->getAttributes();
        $routeBefore = $route->getAttributes();
        $patch = match ($change) {
            'drop' => ['ports' => []],
            'host-port' => ['ports' => [$address.':8096:8080/tcp']],
            'address' => ['ports' => ['192.0.2.99:8095:8080/tcp']],
            'ambiguous' => ['ports' => [$address.':8095:8080/tcp', $address.':8096:8081/tcp']],
            'udp' => ['ports' => [$address.':8095:8080/udp']],
            'runtime' => ['runtime' => 'systemd', 'command' => ['/usr/bin/server'], 'image' => null, 'ports' => [], 'volumes' => [], 'restart_policy' => 'always'],
        };
        $runtime = Mockery::mock(ProcessRuntimeManager::class);
        $runtime->shouldNotReceive('status', 'assertCanStart', 'converge', 'remove');
        app()->instance(ProcessRuntimeManager::class, $runtime);

        $this->patchJson('/api/v1/processes/'.$process->id, $patch)->assertConflict()
            ->assertJsonPath('error.code', 'process.route_upstream_changed')
            ->assertJsonPath('error.details.route_id', $route->id)
            ->assertJsonPath('error.details.domain', $route->domain)
            ->assertJsonPath('error.message', "Update would change or remove the upstream listener for Route [{$route->domain}] ({$route->id}).");

        expect($process->refresh()->getAttributes())->toBe($before)
            ->and($route->refresh()->getAttributes())->toBe($routeBefore);
    })->with(['private', 'wireguard', 'lan'])->with(['drop', 'host-port', 'address', 'ambiguous', 'udp', 'runtime']);
});

/** @return array{Process, Route, string} */
function process_update_route_fixture(TestCase $test, string $scope): array
{
    $node = Node::query()->where('name', 'beast')->sole();
    $address = '127.0.0.1';
    if ($scope === 'private') {
        $process = custom_proxy_process($node, 'sync', [$address.':8095:8080/tcp']);
        $body = ['domain' => 'sync.orbit', 'node_id' => $node->id, 'process_id' => $process->id];
    } else {
        $body = public_process_body($node);
        $process = Process::query()->findOrFail($body['process_id']);
        $address = $node->wireguard_ip;
        if ($scope === 'lan') {
            $node->update(['lan_ip' => '10.0.0.6']);
            Node::query()->findOrFail($body['ingress_node_id'])->update(['lan_ip' => '10.0.0.4']);
            $address = '10.0.0.6';
            FirewallRule::query()->create(['node_id' => $node->id, 'name' => 'process-lan-fixture',
                'action' => 'allow', 'source' => '10.0.0.4', 'destination' => $address, 'port' => '8095', 'protocol' => 'tcp', 'status' => 'active']);
            $process->update(['runtime_config' => [...$process->runtime_config, 'ports' => [$address.':8095:8080/tcp']]]);
        }
    }
    $id = $test->postJson('/api/v1/routes', $body)->completeRoute()->assertOk()->json('data.id');

    return [$process, Route::query()->findOrFail($id)->load('customProxy'), $address];
}

/** @return array{Process, CreateRouteData, string, string} */
function process_route_race_fixture(TestCase $test, string $publication): array
{
    $node = Node::query()->where('name', 'beast')->sole();
    $public = public_process_body($node);
    $process = Process::query()->findOrFail($public['process_id']);
    $process->update(['runtime_config' => [...$process->runtime_config, 'ports' => ['10.44.0.7:8095:8080/tcp', '127.0.0.1:8095:8080/tcp']]]);
    $private = ['domain' => 'private-sync.orbit', 'node_id' => $node->id, 'process_id' => $process->id];
    $test->postJson('/api/v1/routes', $publication === 'private' ? $public : $private)->completeRoute()->assertOk();
    $data = new CreateRouteData(
        domain: $publication === 'private' ? $private['domain'] : $public['domain'],
        publication: RoutePublication::from($publication),
        nodeId: $publication === 'private' ? $node->id : null,
        processId: $process->id,
        ingressNodeId: $publication === 'public' ? $public['ingress_node_id'] : null,
    );

    return [$process, $data, $publication === 'private' ? '127.0.0.1' : '10.44.0.7',
        $publication === 'private' ? '10.44.0.7:8095:8080/tcp' : '127.0.0.1:8095:8080/tcp'];
}

/** @param list<string> $ports */
function process_route_ports_update(Process $process, array $ports): UpdateProcessData
{
    return new UpdateProcessData(new AddProcessData(
        targetType: ProcessTargetType::Node,
        targetId: $process->owner_id,
        name: $process->name,
        runtime: ProcessRuntime::Docker,
        command: ['executor'],
        image: 'executor:latest',
        workingDirectory: '/app',
        environment: [], ports: $ports, volumes: [], restartPolicy: 'unless-stopped', start: false,
    ), ['ports']);
}

/** A stored serving Route is the precondition of removal and access tests. */
function custom_proxy_seed(TestCase $test, array $body): TestResponse
{
    $result = app(CreateRouteAction::class)->reserveForRouteCreate(new CreateRouteData(
        domain: $body['domain'], publication: RoutePublication::from($body['publication'] ?? 'private'),
        nodeId: $body['node_id'] ?? null, upstream: $body['upstream'] ?? null,
        processId: $body['process_id'] ?? null, ingressNodeId: $body['ingress_node_id'] ?? null,
    ));
    $route = $result['route'];
    $route->publishSites();
    $route->update(['status' => 'active', 'replacement_step' => $route->publication === RoutePublication::Public ? 'ingress-firewall' : null]);

    return $test->getJson('/api/v1/routes/'.$route->id)->assertOk();
}
