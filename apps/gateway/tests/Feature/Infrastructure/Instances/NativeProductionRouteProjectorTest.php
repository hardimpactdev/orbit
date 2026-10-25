<?php

declare(strict_types=1);

use App\Actions\Routes\CreateRouteAction;
use App\Data\Routes\CreateRouteData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\AppDev\DnsmasqPrivateDnsManager;
use App\Infrastructure\AppDev\RemoteAppDevCaddyManager;
use App\Infrastructure\AppDev\RemoteAppDevCertificateManager;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\RouteProjectionAgentAnswers;

it('creates an Instance Route with the native production projector on a separate Router', function (): void {
    [$source, $oldRoute] = orb199_production_route_models(workloadLan: '10.10.0.10', routerLan: '10.10.0.20');
    $oldRoute->targets()->delete();
    $oldRoute->delete();
    $source->delete();
    $instance = Instance::query()->create([
        'project_id' => $source->project_id,
        'node_id' => $source->node_id,
        'name' => 'created',
        'environment' => 'production',
        'checkout_path' => $source->checkout_path,
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'production_user' => $source->production_user,
        'production_home' => $source->production_home,
        'production_php_service' => $source->production_php_service,
        'production_php_pool' => $source->production_php_pool,
        'production_php_socket' => $source->production_php_socket,
        'status' => InstanceState::Active,
    ]);
    [$projector, $agents] = orb199_production_route_projector();
    app()->instance(ProductionRouteProjector::class, $projector);
    app()->instance(ProductionCloneRouteProjector::class, $projector);

    $route = app(CreateRouteAction::class)->executeForRouteCreate(new CreateRouteData(
        domain: 'created.prod.orbit',
        publication: RoutePublication::Private,
        instanceId: $instance->id,
    ))['route'];

    $calls = collect($agents->calls);
    $routerCertificateIndex = $calls->search(static fn (array $call): bool => $call['command'] === 'certificates.publish-leaf' && $call['input']['scope'] === "route-{$route->id}-router");
    $routerSiteIndex = $calls->search(static fn (array $call): bool => $call['command'] === 'caddy.publish' && str_contains($call['input']['content'], "route-{$route->id}-router/current/cert.pem"));
    expect($route->status)->toBe(RouteStatus::Active)
        ->and($route->targets->sole()->instance_id)->toBe($instance->id)
        ->and($routerCertificateIndex)->not->toBeFalse()
        ->and($routerSiteIndex)->not->toBeFalse()
        ->and($routerCertificateIndex)->toBeLessThan($routerSiteIndex)
        ->and(orb199_route_checks($agents))->toBe(['firewall.route-lan', 'routes.verify-certificate']);
});

it('refuses to project production without its dedicated PHP-FPM service', function (): void {
    [$instance, $route] = orb199_production_route_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    $instance->update([
        'production_php_service' => null,
        'production_php_pool' => null,
        'production_php_socket' => null,
    ]);
    [$projector] = orb199_production_route_projector();

    expect(fn () => $projector->prepareRuntime($instance, $route))
        ->toThrow(function (RuntimeConvergenceException $exception): void {
            expect($exception->errorCode)->toBe('app-prod.php_service_missing')
                ->and($exception->getMessage())
                ->toBe('The production Instance has no recorded dedicated PHP-FPM service.');
        });
});

it('projects a production workload through a remote Router over LAN without public infrastructure', function (): void {
    [$instance, $route, $workload, $router] = orb199_production_route_models(
        workloadLan: '10.10.0.10',
        routerLan: '10.10.0.20',
    );
    [$projector, $agents, $processes, $productionPhp, $firewall] = orb199_production_route_projector();

    $projector->prepareRuntime($instance, $route);
    $projector->prepareCertificate($instance, $route);
    $projector->prepareFirewall($instance);
    $projector->prepareWorkloadCaddy($instance, $route);
    $projector->prepareRouterCertificate($instance, $route);
    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);
    $projector->prepareRouterCaddy($instance, $route);
    $projector->prepareDns($route);

    $workloadConfiguration = app(NodeCaddyfileRenderer::class)->render($workload)->content;
    $routerConfiguration = app(NodeCaddyfileRenderer::class)->render($router)->content;
    $dnsConfiguration = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository, hostnames: app()->tagged(ContributedPrivateHostnames::class))->render();
    $pushed = collect(RouteProjectionAgentAnswers::publications($agents));

    expect($productionPhp->converged)->toBe([$instance->id])
        ->and($firewall->converged)->toBe([[$workload->id, RoleName::AppProd, 'orbit']])
        ->and(RouteProjectionAgentAnswers::scopes($agents, 'certificates.publish-leaf'))->toBe([
            ['node' => $workload->name, 'scope' => "app-instance-{$instance->id}"],
            ['node' => $router->name, 'scope' => "route-{$route->id}-router"],
        ])
        ->and(collect($agents->calls)->whereIn('command', ['firewall.route-lan', 'routes.verify-certificate'])->values()->all())->toBe([
            ['node' => $workload->name, 'command' => 'firewall.route-lan', 'input' => [
                'operation' => 'converge', 'route_id' => $route->id, 'router' => '10.10.0.20', 'workload' => '10.10.0.10',
            ]],
            ['node' => $router->name, 'command' => 'routes.verify-certificate', 'input' => [
                'address' => '10.10.0.10', 'port' => 443, 'domain' => $route->domain,
            ]],
        ])
        ->and($workloadConfiguration)->toContain(
            "php_fastcgi unix//run/php/orbit-app-{$instance->project_id}.sock",
            'resolve_root_symlink',
            "tls /etc/caddy/orbit-certificates/app-instance-{$instance->id}/current/cert.pem",
        )
        ->and($routerConfiguration)->toContain(
            'reverse_proxy https://10.10.0.10',
            "header_up Host {$route->domain}",
            "tls_server_name {$route->domain}",
            "tls /etc/caddy/orbit-certificates/route-{$route->id}-router/current/cert.pem",
        )
        ->and($pushed)->toContain(['node' => $workload->name, 'content' => $workloadConfiguration])
        ->and($pushed)->toContain(['node' => $router->name, 'content' => $routerConfiguration])
        ->and($dnsConfiguration)->toContain("host-record={$route->domain},{$router->wireguard_ip}")
        ->and($processes->invocations)->toHaveCount(1)
        ->and(json_encode($agents->calls, JSON_THROW_ON_ERROR))->not->toContain('ingress', 'acme');
});

it('uses WireGuard without a Route-specific firewall rule when workload LAN is absent', function (): void {
    [$instance, $route] = orb199_production_route_models();
    [$projector, $agents] = orb199_production_route_projector();

    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);

    expect($agents->commands())->toBe(['routes.verify-certificate'])
        ->and($agents->calls[0]['input'])->toBe(['address' => '10.44.0.10', 'port' => 443, 'domain' => $route->domain]);
});

it('uses one local production site when Router and workload share a Node', function (): void {
    [$instance, $route, $node] = orb199_production_route_models(coLocated: true);
    [$projector, $agents, $processes] = orb199_production_route_projector();

    $projector->prepareRouterCertificate($instance, $route);
    $projector->prepareRouteFirewall($instance, $route);
    $projector->verifyWorkload($instance, $route);
    $projector->prepareWorkloadCaddy($instance, $route);
    $projector->prepareRouterCaddy($instance, $route);
    $projector->prepareDns($route);

    $sites = new DevelopmentSiteRepository()->forNode($node, $route);
    $configuration = new DevelopmentCaddyConfigRenderer()->render($sites);
    expect($sites)->toHaveCount(1)
        ->and($sites->sole()->isProxy())->toBeFalse()
        ->and($configuration)->toContain('resolve_root_symlink')->not->toContain('reverse_proxy')
        ->and($agents->commands())->toBe(['caddy.publish'])
        ->and($processes->invocations)->toHaveCount(1);
});

it('refuses incompatible LAN and missing Router placement with bounded errors', function (string $case): void {
    [$instance, $route] = orb199_production_route_models(
        workloadLan: $case === 'LAN' ? '10.10.0.10' : null,
        withRouter: $case !== 'Router',
    );
    [$projector] = orb199_production_route_projector();

    $operation = $case === 'LAN'
        ? fn () => $projector->prepareRouteFirewall($instance, $route)
        : fn () => $projector->prepareRouterCertificate($instance, $route);

    expect($operation)->toThrow(function (RuntimeConvergenceException $exception) use ($case): void {
        expect([$exception->step, $exception->errorCode])->toBe($case === 'LAN'
            ? ['route-address', 'route.lan_unreachable']
            : ['router', 'cluster.router_required']);
    });
})->with(['LAN', 'Router']);

it('refuses an invalid workload leaf before Router Caddy and DNS publication', function (): void {
    [$instance, $route] = orb199_production_route_models();
    [$projector, $agents, $processes] = orb199_production_route_projector();
    $agents->refuse('routes.verify-certificate', 'routes.certificate_invalid', ['stderr' => 'untrusted']);

    expect(function () use ($projector, $instance, $route): void {
        $projector->prepareWorkloadCaddy($instance, $route);
        $projector->prepareRouterCertificate($instance, $route);
        $projector->prepareRouteFirewall($instance, $route);
        $projector->verifyWorkload($instance, $route);
        $projector->prepareRouterCaddy($instance, $route);
        $projector->prepareDns($route);
    })->toThrow(function (RuntimeConvergenceException $exception): void {
        expect([$exception->step, $exception->errorCode])
            ->toBe(['workload-certificate', 'app-dev.workload_certificate_invalid']);
    });

    expect($processes->invocations)->toBeEmpty()
        ->and(array_column(RouteProjectionAgentAnswers::publications($agents), 'node'))->toBe([$instance->node->name]);
});

/** @return array{Instance, Route, Node, Node} */
function orb199_production_route_models(
    bool $coLocated = false,
    ?string $workloadLan = null,
    ?string $routerLan = null,
    bool $withRouter = true,
): array {
    $cluster = Cluster::query()->create([
        'name' => 'production-routing-'.Str::lower(Str::random(8)),
        'state' => ClusterState::Active,
    ]);
    $workload = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'production-workload-'.Str::lower(Str::random(8)),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.10',
        'lan_ip' => $workloadLan,
        'user' => 'orbit',
    ]);
    $workload->roles()->create([
        'role' => RoleName::AppProd,
        'status' => LifecycleStatus::Active,
    ]);
    $router = $coLocated
        ? $workload
        : Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'production-router-'.Str::lower(Str::random(8)),
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
            'lan_ip' => $routerLan,
            'user' => 'orbit',
        ]);
    if ($withRouter) {
        $router->roles()->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
    }
    $project = Project::query()->create([
        'name' => 'Production route',
        'slug' => 'production-route-'.Str::lower(Str::random(8)),
        'repository_url' => 'https://example.test/production-route.git',
        'root' => 'public',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $workload->id,
        'name' => 'production',
        'environment' => 'production',
        'checkout_path' => "/home/orbit-app-{$project->id}/releases/initial",
        'root' => 'public',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'production_user' => "orbit-app-{$project->id}",
        'production_home' => "/home/orbit-app-{$project->id}",
        'production_php_service' => "php8.5-fpm-orbit-app-{$project->id}.service",
        'production_php_pool' => "orbit-app-{$project->id}",
        'production_php_socket' => "/run/php/orbit-app-{$project->id}.sock",
        'status' => InstanceState::SourceResolved,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'preview.prod.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);

    return [$instance, $route, $workload, $router];
}

/**
 * One agent runner answers every Node command of the projection, in call order.
 *
 * @return array{
 *     NativeProductionRouteProjector,
 *     FakeAgentCommandRunner,
 *     Orb199ProductionRouteProcessRunner,
 *     Orb199ProductionPhpRuntime,
 *     Orb199ProductionFirewall
 * }
 */
function orb199_production_route_projector(): array
{
    $account = new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
    $accounts = new class($account) implements ManagedUserAccountResolver
    {
        public function __construct(
            private readonly ManagedUserAccount $account,
        ) {}

        public function resolve(Node $node): ManagedUserAccount
        {
            return $this->account;
        }
    };
    $signer = new class implements LeafCertificateSigner
    {
        public function sign(string $domain, string $certificateRequest): string
        {
            return "LEAF CERTIFICATE\n";
        }

        public function rootCertificate(): string
        {
            return "ROOT CERTIFICATE\n";
        }
    };
    $sites = new DevelopmentSiteRepository;
    $processes = new Orb199ProductionRouteProcessRunner;
    $productionPhp = new Orb199ProductionPhpRuntime;
    $firewall = new Orb199ProductionFirewall;
    $agents = RouteProjectionAgentAnswers::register(new FakeAgentCommandRunner()->answer('firewall.route-lan', ['matches' => true]));
    $projector = new NativeProductionRouteProjector(
        $productionPhp,
        new RemoteAppDevCertificateManager($agents, $signer, $accounts),
        $firewall,
        new RemoteAppDevCaddyManager(RouteProjectionAgentAnswers::builds($agents), new FakeAgentCommandRunner),
        new DnsmasqPrivateDnsManager($processes, new DevelopmentDnsConfigRenderer($sites, hostnames: app()->tagged(ContributedPrivateHostnames::class))),
        new Orb199ProductionReleaseLayout,
        $agents,
    );

    return [$projector, $agents, $processes, $productionPhp, $firewall];
}

/** @return list<string> The Route firewall and workload TLS commands, in order. */
function orb199_route_checks(FakeAgentCommandRunner $agents): array
{
    return array_values(array_intersect($agents->commands(), ['firewall.route-lan', 'routes.verify-certificate']));
}

final class Orb199ProductionRouteProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return new CommandResult(0, '', '', 1, false);
    }
}

final class Orb199ProductionPhpRuntime implements ProductionPhpRuntimeManager
{
    /** @var list<int> */
    public array $converged = [];

    public function converge(Instance $instance, ?Route $activating = null): void
    {
        $this->converged[] = $instance->id;
    }

    public function convergeMonitoring(Instance $instance, bool $enabled): void {}

    public function refreshCache(Instance $instance): void {}

    public function remove(Instance $instance): void {}
}

final class Orb199ProductionFirewall implements NodeRoleFirewallManager
{
    /** @var list<array{int, RoleName, string}> */
    public array $converged = [];

    public function convergeBase(Node $node, string $managedUser): void {}

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $this->converged[] = [$node->id, $role, $managedUser];
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void {}

    public function restorePublicSsh(Node $node, string $managedUser): void {}

    public function trustWireGuardMembers(Node $node, string $managedUser): void {}
}

final class Orb199ProductionReleaseLayout implements ProductionReleaseLayout
{
    public function validateCurrent(Instance $instance): void {}

    public function clearCurrent(Instance $instance): void {}
}
