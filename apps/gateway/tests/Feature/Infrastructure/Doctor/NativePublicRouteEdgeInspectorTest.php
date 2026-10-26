<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Doctor\NativePublicRouteEdgeInspector;
use App\Infrastructure\Routes\NativePublicRouteEdgeProjector;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeAgentCommandRunner;

describe('an Ingress whose role is converging', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        public_edge_node('edge-router', '10.44.0.20', '10.10.0.20', $cluster, [RoleName::Router]);
        $this->ingress = public_edge_node('edge-ingress', '10.44.0.30', '10.10.0.30', $cluster, [RoleName::Ingress]);
        $workload = public_edge_node('edge-workload', '10.44.0.40', '10.10.0.40', $cluster, [RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $workload);
    });

    it('keeps the public site in a Node Caddy build while the role serves', function (
        LifecycleStatus $status,
        ?string $failedStep,
        bool $serves,
    ): void {
        $this->ingress->roles()->where('role', RoleName::Ingress)->update([
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'node_role.convergence_failed',
        ]);

        $caddyfile = app(NodeCaddyfileRenderer::class)->render($this->ingress)->content;

        expect(str_contains($caddyfile, "{$this->route->domain} {"))->toBe($serves);
    })->with([
        'active' => [LifecycleStatus::Active, null, true],
        'converging' => [LifecycleStatus::Provisioning, null, true],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config', true],
        'being removed' => [LifecycleStatus::Removing, null, false],
    ]);

    it('starts a public activation only on an active Ingress', function (
        LifecycleStatus $status,
        ?string $failedStep,
        bool $starts,
    ): void {
        $this->ingress->roles()->where('role', RoleName::Ingress)->update([
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'node_role.convergence_failed',
        ]);
        $eligibility = new PublicRouteEligibility;

        expect($eligibility->canStartActivation($this->route->refresh()))->toBe($starts)
            ->and($eligibility->canActivate($this->route))->toBe($status !== LifecycleStatus::Removing);
    })->with([
        'active' => [LifecycleStatus::Active, null, true],
        'converging' => [LifecycleStatus::Provisioning, null, false],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config', false],
        'being removed' => [LifecycleStatus::Removing, null, false],
    ]);
});

describe('an Ingress that runs the workload while the Router is on another Node', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $this->router = public_edge_node('gateway', '10.44.0.1', null, $cluster, [RoleName::Router]);
        $this->ingress = public_edge_node('app-prod', '10.44.0.3', null, $cluster, [RoleName::Ingress, RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $this->ingress);
    });

    it('builds one public site for the host that serves the local workload', function (): void {
        $ingress = app(NodeCaddyfileRenderer::class)->render($this->ingress);
        $domain = $this->route->domain;

        expect($ingress->problems)->toBe([])
            ->and(substr_count($ingress->content, "{$domain} {"))->toBe(1)
            ->and($ingress->content)
            ->toContain("# orbit: ingress route-{$this->route->id}-ingress\n{$domain} {\n    bind 0.0.0.0 10.44.0.3\n    tls force_automate")
            ->toContain('php_fastcgi unix//run/php/orbit-app-')
            ->not->toContain("https://{$domain} {")
            ->not->toContain('reverse_proxy https://10.44.0.1');
    });

    it('lets the Router reach the public site through the Node system roots', function (): void {
        $router = app(NodeCaddyfileRenderer::class)->render($this->router)->content;
        $block = strstr((string) strstr($router, "# orbit: app-dev route-{$this->route->id}-router"), '# orbit:', true) ?: (string) strstr($router, "# orbit: app-dev route-{$this->route->id}-router");

        expect($block)->toContain('reverse_proxy https://10.44.0.3')
            ->not->toContain('tls_trusted_ca_certs');
    });

    it('builds the private workload site again once the Route is private', function (): void {
        $this->route->update(['publication' => RoutePublication::Private, 'replacement_step' => null]);
        $ingress = app(NodeCaddyfileRenderer::class)->render($this->ingress)->content;

        expect($ingress)->toContain("https://{$this->route->domain} {")
            ->not->toContain('tls force_automate');
    });

    it('verifies both selected hops through the agent and maps a refused hop', function (): void {
        $agents = new FakeAgentCommandRunner()->answer('route.verify-certificate', []);
        app()->instance(AgentCommandRunner::class, $agents);
        $projector = app(NativePublicRouteEdgeProjector::class);

        $projector->verifyPublicEdge($this->route);

        expect($agents->calls)->toBe([
            ['node' => $this->ingress->name, 'command' => 'route.verify-certificate', 'input' => ['address' => '10.44.0.1', 'port' => 443, 'domain' => $this->route->domain]],
            ['node' => $this->router->name, 'command' => 'route.verify-certificate', 'input' => ['address' => '10.44.0.3', 'port' => 443, 'domain' => $this->route->domain]],
        ])->and($agents->deadlines)->toBe([15.0, 15.0]);
        $agents->refuse('route.verify-certificate', 'route.certificate_invalid', ['stderr' => 'untrusted']);

        expect(fn () => $projector->verifyPublicEdge($this->route))
            ->toThrow(function (RuntimeConvergenceException $exception): void {
                expect([$exception->step, $exception->errorCode])->toBe(['ingress-forwarding', 'route.public_edge_unverified']);
            });
        expect($agents->calls)->toHaveCount(3);
    });

    it('builds the Router with the Ingress when the public edge activates', function (): void {
        $builds = app(NodeCaddyBuilds::class);

        app(NativePublicRouteEdgeProjector::class)->activatePublicHandler($this->route);

        expect($builds->built)->toBe(['app-prod', 'gateway']);
    });

});

describe('an Ingress that is also the Router, with the workload on another Node', function (): void {
    beforeEach(function (): void {
        $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
        $this->ingress = public_edge_node('edge', '10.44.0.1', null, $cluster, [RoleName::Router, RoleName::Ingress]);
        $workload = public_edge_node('app-prod', '10.44.0.3', null, $cluster, [RoleName::AppProd]);
        $this->route = public_edge_route($cluster, $workload);
    });

    it('builds the public site on every address and on the WireGuard address', function (): void {
        $caddyfile = app(NodeCaddyfileRenderer::class)->render($this->ingress);

        expect($caddyfile->problems)->toBe([])
            ->and($caddyfile->content)
            ->toContain("# orbit: ingress route-{$this->route->id}-ingress\n{$this->route->domain} {\n    bind 0.0.0.0 10.44.0.1\n    tls force_automate");
    });

});

function public_edge_route(Cluster $cluster, Node $workload): Route
{
    static $number = 0;
    $number++;
    $project = Project::query()->create([
        'name' => "Shop {$number}",
        'slug' => "shop-{$number}",
        'repository_url' => "https://git.example.test/acme/shop-{$number}.git",
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $user = "orbit-app-{$project->id}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/{$user}/releases/initial",
        'production_user' => $user,
        'production_home' => "/home/{$user}",
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => "shop-{$number}.example.com",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update([
        'status' => RouteStatus::Active,
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);

    return $route->refresh();
}

/** @param list<RoleName> $roles */
function public_edge_node(string $name, string $wireguardIp, ?string $lanIp, Cluster $cluster, array $roles): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $wireguardIp,
        'wireguard_ip' => $wireguardIp,
        'lan_ip' => $lanIp,
        'cluster_id' => $cluster->id,
        'user' => 'orbit',
    ]);

    foreach ($roles as $role) {
        $node->roles()->create([
            'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $cluster->id : null,
            'role' => $role,
            'status' => LifecycleStatus::Active,
        ]);
    }

    return $node;
}

it('checks the public Process listener directly from the Ingress without a Router hop', function (): void {
    $port = 8081;
    $cluster = Cluster::query()->create(['name' => 'process-edge', 'state' => ClusterState::Active]);
    public_edge_node('process-router', '10.44.0.20', '10.10.0.20', $cluster, [RoleName::Router]);
    $ingress = public_edge_node('process-ingress', '10.44.0.30', '10.10.0.30', $cluster, [RoleName::Ingress]);
    $services = Node::query()->create(['name' => 'services', 'wireguard_ip' => '127.0.0.1', 'lan_ip' => '10.0.0.6', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.6', 'user' => 'orbit', 'status' => 'active']);
    $process = $services->processes()->create(['name' => 'sync', 'runtime' => 'docker', 'runtime_config' => ['ports' => ['127.0.0.1:'.$port.':80/tcp']], 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'custom_proxy', 'cluster_id' => $cluster->id, 'domain' => 'sync.example.com', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
    $route->customProxy()->create(['node_id' => $services->id, 'process_id' => $process->id, 'upstream' => 'http://127.0.0.1:'.$port]);
    $route->update(['status' => 'active', 'sites_published' => true, 'replacement_step' => 'ingress-firewall']);
    $agents = public_edge_agents();
    $inspector = new NativePublicRouteEdgeInspector($agents);

    expect($inspector->inspect($ingress, $route)->privateForwardingMatches)->toBeTrue()
        ->and($agents->calls[0]['input']['forwarding'])->toBe(['127.0.0.1:8081']);
});

function public_edge_agents(): FakeAgentCommandRunner
{
    return new FakeAgentCommandRunner()
        ->answer('doctor.inspect-public-route', ['ingress' => true, 'forwarding' => true, 'tls' => true])
        ->answer('firewall.inspect', ['backend' => 'active', 'ownerships' => [], 'matches' => true, 'live' => []]);
}

/** @return array{Node, Route} */
function public_edge_fixture(bool $routerHere = false, bool $workloadHere = false): array
{
    $cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $roles = [RoleName::Ingress];
    if ($routerHere) {
        $roles[] = RoleName::Router;
    } else {
        public_edge_node('router', '10.44.0.20', null, $cluster, [RoleName::Router]);
    }
    if ($workloadHere) {
        $roles[] = RoleName::AppProd;
    }
    $ingress = public_edge_node('ingress', '10.44.0.30', null, $cluster, $roles);
    $workload = $workloadHere ? $ingress : public_edge_node('workload', '10.44.0.40', null, $cluster, [RoleName::AppProd]);

    return [$ingress, public_edge_route($cluster, $workload)];
}

it('sends the composed site and forwarding intent for the selected Ingress placement', function (bool $routerHere, bool $workloadHere, array $forwarding): void {
    [$ingress, $route] = public_edge_fixture($routerHere, $workloadHere);
    $agents = public_edge_agents();

    $result = new NativePublicRouteEdgeInspector($agents)->inspect($ingress, $route);

    expect((array) $result)->toBe(['ingressProjectionMatches' => true, 'privateForwardingMatches' => true, 'publicTlsMatches' => true, 'firewallMatches' => true]);
    expect($agents->commands())->toBe(['doctor.inspect-public-route', 'firewall.inspect'])
        ->and($agents->calls[0]['node'])->toBe($ingress->name)
        ->and($agents->calls[0]['input'])->toMatchArray([
            'domain' => $route->domain,
            'certificates' => '/etc/caddy/orbit-certificates/route-'.$route->id.'-ingress/current',
            'forwarding' => $forwarding,
        ])
        ->and($agents->calls[0]['input']['expected_site'])->toContain("{$route->domain} {", 'tls force_automate');
})->with([
    'separate' => [false, false, ['10.44.0.20:443']],
    'Router on Ingress' => [true, false, ['10.44.0.40:443']],
    'workload on Ingress' => [false, true, []],
    'all on Ingress' => [true, true, []],
]);

it('maps native drift without changing other observations', function (): void {
    [$node, $route] = public_edge_fixture();
    $agents = public_edge_agents()->answer('doctor.inspect-public-route', ['ingress' => false, 'forwarding' => true, 'tls' => false]);

    expect((array) new NativePublicRouteEdgeInspector($agents)->inspect($node, $route))
        ->toBe(['ingressProjectionMatches' => false, 'privateForwardingMatches' => true, 'publicTlsMatches' => false, 'firewallMatches' => true]);
});

it('reports native firewall drift', function (): void {
    [$node, $route] = public_edge_fixture();
    $agents = public_edge_agents()->answer('firewall.inspect', ['backend' => 'active', 'ownerships' => [], 'matches' => false, 'live' => []]);

    expect(new NativePublicRouteEdgeInspector($agents)->inspect($node, $route)->firewallMatches)->toBeFalse();
});

it('reports failed and malformed observations as unavailable', function (string $failure): void {
    [$node, $route] = public_edge_fixture();
    $agents = public_edge_agents();
    match ($failure) {
        'refused' => $agents->refuse('doctor.inspect-public-route', 'doctor.public_route_unavailable'),
        'incomplete' => $agents->answerMalformed('doctor.inspect-public-route', ['ingress' => true]),
        'firewall' => $agents->answerMalformed('firewall.inspect', ['matches' => 'private']),
    };

    expect(fn () => new NativePublicRouteEdgeInspector($agents)->inspect($node, $route))->toThrow(DoctorInspectionException::class);
})->with(['refused', 'incomplete', 'firewall']);

it('refuses an Ingress with no intended public site before any agent call', function (): void {
    [$node, $route] = public_edge_fixture();
    $route->update(['publication' => RoutePublication::Private]);
    $agents = public_edge_agents();

    expect(fn () => new NativePublicRouteEdgeInspector($agents)->inspect($node, $route))->toThrow(DoctorInspectionException::class)
        ->and($agents->calls)->toBe([]);
});

it('keeps public Route observation behind the agent boundary', function (): void {
    foreach (['DevelopmentSshExecutor', 'RemoteCommand', '<<<', 'openssl', 'bash'] as $forbidden) {
        expect(file_get_contents(app_path('Infrastructure/Doctor/NativePublicRouteEdgeInspector.php')))->not->toContain($forbidden);
    }
});
