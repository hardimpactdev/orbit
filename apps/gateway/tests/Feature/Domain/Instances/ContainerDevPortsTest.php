<?php

declare(strict_types=1);

use App\Data\Processes\AddProcessData;
use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceDevPorts;
use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\InstanceNodeServiceTargets;
use App\Domain\Instances\InstanceOwnSite;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteTarget;
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
    RouteTarget::query()->create(['route_id' => $route->id, 'instance_id' => $clone->id, 'position' => 0]);

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

it('serves no development servers for the default, or for a clone without a Route', function (): void {
    $world = devPortWorld();
    $defaultRuntime = InstanceRuntime::query()->where('instance_id', $world['default']->id)->firstOrFail();

    expect(devPortSites($world['default'], $world['route']))->toBe([])
        ->and(app(InstanceContainer::class)->servesDevPorts($world['default'], $defaultRuntime))->toBeFalse()
        ->and(app(InstanceContainer::class)->servesDevPorts($world['clone'], $world['runtime']))->toBeTrue();

    RouteTarget::query()->where('instance_id', $world['clone']->id)->delete();
    expect(app(InstanceContainer::class)->servesDevPorts($world['clone']->refresh(), $world['runtime']))->toBeFalse();
});

it('decides the ACL and the instance file by the same rule as the site: a clone with a public Route gets neither', function (): void {
    $world = devPortWorld('public');

    $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');

    $instanceFile = collect(ValidatedData::object($container['clone'])['files'])->firstWhere('path', '/etc/orbit/instance');
    expect(collect($container['acl_rules']['ingress'])->pluck('destination_port')->all())->toBe(['8080', '3773'])
        ->and(str_contains($instanceFile['content'], 'ORBIT_DEV_PORTS'))->toBeFalse();
});

it('binds the development site to the WireGuard address only, and aborts every client outside the VPN and every task VM', function (): void {
    $world = devPortWorld();
    config(['task_vms.enabled' => true, 'task_vms.wireguard_range' => '10.44.0.128/25']);
    $site = devPortSites($world['clone'], $world['route'])[0];
    $source = new class($site) implements NodeCaddySiteSource
    {
        public function __construct(private CaddySite $site) {}

        public function order(): int
        {
            return 1;
        }

        public function sites(Node $node): array
        {
            return [$this->site];
        }
    };

    $caddyfile = (new NodeCaddyfileRenderer([$source]))->render($world['node']);

    expect($caddyfile->content)->toContain("https://feature.tool.test:444 {\n    bind 10.44.0.7\n")
        ->toContain('@orbit_outside not remote_ip 10.44.0.0/24')
        ->toContain('abort @orbit_outside')
        ->toContain('@orbit_task_vms remote_ip 10.44.0.128/25')
        ->toContain('abort @orbit_task_vms')
        ->and(str_contains($caddyfile->content, 'bind 0.0.0.0'))->toBeFalse()
        ->and(str_contains($caddyfile->content, '192.168.'))->toBeFalse();
});

it('lets only the Node\'s Caddy reach the development range in the clone, and names it in the instance file', function (): void {
    $world = devPortWorld();

    $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');

    $instanceFile = collect(ValidatedData::object($container['clone'])['files'])->firstWhere('path', '/etc/orbit/instance');
    expect($container['acl_rules']['ingress'][2])
        ->toBe(['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '1024-65535', 'state' => 'enabled'])
        ->and($instanceFile['content'])->toContain("ORBIT_URL=https://feature.tool.test\nORBIT_DEV_PORTS=1024-65535\nORBIT_DEV_PORTS_TAKEN=3128,3800-3999,8318-8319,51820\n");
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
            'runtime_config' => ['image' => 'x', 'ports' => ['9100:9100/tcp', '3900:3900', '8317:8317']], 'status' => 'active']);

        $ruleset = app(InstanceDevPorts::class)->ruleset($world['node']->refresh());

        expect(app(InstanceDevPorts::class)->excluded($world['node']))
            // 3900 lies in the T3 range and 8317 touches the forwarder's range: an nftables interval set refuses overlaps, so they merge.
            ->toBe(['2222', '3000', '3128', '3800-3999', '5432', '8317-8319', '9100', '51820'])
            ->and($ruleset)->toContain("table inet orbit_instance_dev_ports\ndelete table inet orbit_instance_dev_ports\n")
            ->toContain('type nat hook prerouting priority dstnat + 10; policy accept;')
            ->toContain('iifname "orbit" ip daddr 10.44.0.7 tcp dport 1024-65535 ct state new jump development')
            ->toContain('elements = { 2222, 3000, 3128, 3800-3999, 5432, 8317-8319, 9100, 51820 }')
            ->toContain("tcp dport @excluded return\n\t\tsocket transparent 0 return\n\t\tmeta l4proto tcp counter redirect to :444");
    });

    it('keeps Reverb\'s own listener out of the development range on a websocket Node only', function (): void {
        $world = devPortWorld();
        expect(app(InstanceDevPorts::class)->excluded($world['node']))->not->toContain('8443');
        $world['node']->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);

        expect(app(InstanceDevPorts::class)->excluded($world['node']->refresh()))->toContain('8443');
    });

    it('is part of a build-list Node\'s Instance host converge', function (): void {
        $world = devPortWorld();

        $intent = app(InstanceHostIntent::class)->for($world['node']);

        expect(array_keys($intent['dev_ports'] ?? []))->toBe(['table', 'unit', 'ruleset'])
            ->and($intent['dev_ports']['table'])->toBe('orbit_instance_dev_ports')
            ->and($intent['dev_ports']['unit'])->toBe('orbit-instance-dev-ports.service')
            ->and($intent['dev_ports']['ruleset'])->toContain('ip daddr 10.44.0.7 tcp dport 1024-65535 ct state new jump development');
    });
});

/** The Caddyfile that origin/main rendered for the site set of ownSiteFixtureSites, before container own sites. */
const OwnSiteGolden = __DIR__.'/../../../Fixtures/Caddy/route-sites-before-own-site.caddy';

/**
 * Today's kinds of Route site on one Node: a checkout with Vite and hibernation, a container default, a container clone on a private
 * Route with Vite and sleep, a public Ingress clone, a production checkout and a custom proxy.
 *
 * @return list<DevelopmentSite>
 */
function ownSiteFixtureSites(Node $node): array
{
    $site = static fn (string $scope, string $domain, array $more = []): DevelopmentSite => new DevelopmentSite(...[
        'nodeId' => $node->id, 'nodeAddress' => '10.44.0.7', 'scope' => $scope, 'checkoutPath' => '', 'documentRoot' => '', 'phpVersion' => null,
        'domain' => $domain, 'projectSlug' => 'tool', ...$more]);

    return [
        $site('app-instance-11', 'tool.test', ['localHttpUpstream' => '10.253.0.2:8080', 'asleepMarker' => '/var/lib/orbit/power/app-instance-11.asleep']),
        $site('app-instance-12', 'feature.tool.test', ['localHttpUpstream' => '10.253.0.3:8080', 'devUpstream' => '10.253.0.3',
            'viteUpstream' => '10.253.0.3:5173', 'asleepMarker' => '/var/lib/orbit/power/app-instance-12.asleep']),
        $site('app-instance-13', 'blog.test', ['checkoutPath' => '/home/orbit/apps/blog', 'documentRoot' => 'public', 'phpVersion' => '8.4', 'vitePort' => 5174]),
        $site('app-instance-14', 'shop.example.com', ['publicListener' => true, 'localHttpUpstream' => '10.253.0.5:8080']),
        $site('app-instance-15', 'shop.test', ['checkoutPath' => '/home/shop/current', 'phpVersion' => '8.4', 'environment' => 'production',
            'productionUser' => 'shop', 'productionHome' => '/home/shop']),
        $site('route-7', 'api.test', ['upstreamAddresses' => ['10.44.0.9'], 'upstreamAddress' => '10.44.0.9']),
    ];
}

/** @param list<CaddySite> $sites */
function ownSiteRender(Node $node, array $sites): NodeCaddyfile
{
    $source = new class($sites) implements NodeCaddySiteSource
    {
        /** @param list<CaddySite> $sites */
        public function __construct(private array $sites) {}

        public function order(): int
        {
            return 1;
        }

        public function sites(Node $node): array
        {
            return $this->sites;
        }
    };

    return (new NodeCaddyfileRenderer([$source]))->render($node);
}

/** A Node with a WireGuard and a LAN address, an Instance bridge, and task VMs configured, so every guard renders. */
function ownSiteNode(): Node
{
    config(['task_vms.enabled' => true, 'task_vms.wireguard_range' => '10.44.0.128/25']);
    $node = containerDefaultNode();
    $node->forceFill(['lan_ip' => '192.168.1.5'])->save();

    return $node->refresh();
}

it('renders every WireGuard and LAN site byte for byte as origin/main did', function (): void {
    $node = ownSiteNode();

    $caddyfile = ownSiteRender($node, app(RouteCaddySiteSource::class)->fromSites(collect(ownSiteFixtureSites($node))));
    $own = array_values(array_filter($caddyfile->blocks, static fn (array $block): bool => str_ends_with($block['name'], '-bridge')));
    $without = $caddyfile->content;
    foreach ($own as $block) {
        $without = str_replace(PHP_EOL.$block['block'], '', $without);
    }

    // Only the private clone gets own sites: the default, the checkout, the public, production and proxy sites get none.
    expect(array_map(static fn (array $block): string => $block['name'], $own))->toBe(['app-instance-12-bridge', 'app-instance-12-dev-bridge'])
        ->and($caddyfile->problems)->toBe([])
        ->and($without)->toBe((string) file_get_contents(OwnSiteGolden));
});

it('serves a clone\'s own site and development ports on the bridge gateway address, to its own bridge address alone', function (): void {
    $node = ownSiteNode();
    $sites = app(RouteCaddySiteSource::class)->fromSites(collect(ownSiteFixtureSites($node)));
    $own = array_values(array_filter($sites, static fn (CaddySite $site): bool => $site->listener === CaddyListenerRule::Bridge));

    $caddyfile = ownSiteRender($node, $sites);

    expect(array_map(static fn (CaddySite $site): array => [$site->name, $site->port, $site->hosts, $site->client], $own))->toBe([
        ['app-instance-12-bridge', 443, ['feature.tool.test'], '10.253.0.3'],
        ['app-instance-12-dev-bridge', 444, ['feature.tool.test'], '10.253.0.3'],
    ])
        ->and($caddyfile->content)->toContain(
            "https://feature.tool.test {\n    bind 10.253.0.1\n    @orbit_outside not remote_ip 10.253.0.3/32\n    abort @orbit_outside\n",
            "https://feature.tool.test:444 {\n    bind 10.253.0.1\n    @orbit_outside not remote_ip 10.253.0.3/32\n    abort @orbit_outside\n",
        )
        // The own site never admits the task VM range, a sibling, or the VPN: one /32 is its only client.
        ->and(substr_count($caddyfile->content, 'bind 10.253.0.1'))->toBe(2)
        ->and(substr_count($caddyfile->content, 'remote_ip 10.253.0.3/32'))->toBe(2)
        // The address check skips the bridge address: a down bridge must not fail the Node's build.
        ->and($caddyfile->listenAddresses)->toBe(['10.44.0.7', '192.168.1.5'])
        ->and(collect($caddyfile->blocks)->firstWhere('name', 'app-instance-12-bridge')['block'])->not->toContain('@orbit_task_vms');
});

it('gives a default and a clone without a Route no own site', function (): void {
    $world = devPortWorld();
    $own = static fn (array $sites): array => array_values(array_filter($sites, static fn (CaddySite $site): bool => $site->listener === CaddyListenerRule::Bridge));
    $repository = app(DevelopmentSiteRepository::class);

    expect($own(app(RouteCaddySiteSource::class)->fromSites(collect([$repository->workloadSite($world['clone'], $world['route'])]))))->toHaveCount(2)
        ->and($own(app(RouteCaddySiteSource::class)->fromSites(collect([$repository->workloadSite($world['default'], $world['route'])]))))->toBe([]);

    RouteTarget::query()->where('instance_id', $world['clone']->id)->delete();
    expect($own(app(RouteCaddySiteSource::class)->sites($world['node']->refresh())))->toBe([]);
});

it('gives a clone on a public Route no own site', function (): void {
    $public = devPortWorld('public');
    $sites = app(RouteCaddySiteSource::class)->fromSites(collect([app(DevelopmentSiteRepository::class)->workloadSite($public['clone'], $public['route'])]));

    expect(array_filter($sites, static fn (CaddySite $site): bool => $site->listener === CaddyListenerRule::Bridge))->toBe([]);
});

it('reports a clone\'s own site on a Node without an Instance bridge instead of rendering it', function (): void {
    $node = containerDefaultNode('other', '10.44.0.8', listed: false);
    $site = new CaddySite(source: 'app-dev', name: 'app-instance-12-bridge', listener: CaddyListenerRule::Bridge, hosts: ['feature.tool.test'],
        port: 443, body: "https://feature.tool.test {\n    bind __B__\n}\n", bindPlaceholder: '__B__', client: '10.253.0.3');

    $caddyfile = ownSiteRender($node, [$site]);

    expect($caddyfile->problems)->toBe(['The app-dev site app-instance-12-bridge needs the Instance bridge of Node [other].'])
        ->and($caddyfile->content)->not->toContain('feature.tool.test');
});

describe('the bridge redirect of the development band', function (): void {
    it('keeps the WireGuard rule as it was and redirects only the band 5173-5199, with no exit', function (): void {
        $world = devPortWorld();

        $ruleset = app(InstanceDevPorts::class)->ruleset($world['node']);

        expect(InstanceDevPorts::BridgeBand)->toBe('5173-5199')
            ->and($ruleset)->toContain(
                "\tchain prerouting {\n\t\ttype nat hook prerouting priority dstnat + 10; policy accept;\n"
                ."\t\tiifname \"orbit\" ip daddr 10.44.0.7 tcp dport 1024-65535 ct state new jump development\n\t}\n",
                "\tchain development {\n\t\ttcp dport @excluded return\n\t\tsocket transparent 0 return\n\t\tmeta l4proto tcp counter redirect to :444\n\t}\n",
                // Before Docker's address mapping, and no `@excluded` or socket exit: every port of the band meets Caddy.
                "\tchain bridge_prerouting {\n\t\ttype nat hook prerouting priority dstnat - 10; policy accept;\n"
                ."\t\tiifname \"orbitinst0\" ip daddr 10.253.0.1 tcp dport 5173-5199 ct state new jump bridge_development\n\t}\n"
                ."\tchain bridge_development {\n\t\tmeta l4proto tcp counter redirect to :444\n\t}\n",
            )
            ->and(substr_count($ruleset, 'jump bridge_development'))->toBe(1);
    });

    it('takes the redirect and the NIC ACL dev rule from the one band, and leaves Mailpit and a same-Node service to their own rows', function (): void {
        $world = devPortWorld();
        $docker = static fn (string $name, array $ports): Process => Process::query()->forceCreate(['owner_type' => Node::class, 'owner_id' => $world['node']->id,
            'name' => $name, 'runtime' => 'docker', 'runtime_config' => ['image' => $name, 'ports' => $ports], 'status' => 'active', 'desired_state' => 'running']);
        $docker('mailpit', ['1025:1025', '127.0.0.1:8025:8025']);
        $search = $docker('meilisearch', ['10.253.0.1:7700:7700/tcp']);
        InstanceNodeServiceTarget::query()->forceCreate(['instance_id' => $world['clone']->id, 'node_id' => $world['node']->id, 'process_id' => $search->id,
            'protocol' => 'tcp', 'port' => 7700, 'host_env_key' => 'MEILISEARCH_HOST', 'port_env_key' => 'MEILISEARCH_PORT']);

        $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');
        $dev = collect($container['acl_rules']['egress'])->firstWhere('description', 'Orbit own development ports');
        $redirected = (string) preg_replace('/\A.*tcp dport (\S+) ct state new jump bridge_development.*\z/s', '$1', app(InstanceDevPorts::class)->ruleset($world['node']));

        expect($dev)->toBe(InstanceOwnSite::nicEgress($world['node']))
            ->and($dev['destination_port'])->toBe('5173-5199')
            ->and($redirected)->toBe($dev['destination_port'])
            // Mail (the container's 1025 and 2525 proxies go to the gateway's 1025) and the service keep their own rows, outside the band.
            ->and(collect($container['acl_rules']['egress'])->where('destination', '10.253.0.1/32')->pluck('destination_port')->values()->all())
            ->toBe(['7700', '1025', '443', '5173-5199'])
            ->and(array_filter([1025, 2525, 7700], InstanceDevPorts::inBand(...)))->toBe([]);
    });
});

describe('the reserved development band', function (): void {
    it('refuses a Node publication in the band on every address or on the bridge address, and allows one outside it or on WireGuard only', function (): void {
        $world = devPortWorld();
        $refusal = static function (?string $bind, int $port) use ($world): ?string {
            try {
                InstanceDevPorts::assertOutsideBand($world['node'], new DockerPublishedPort($bind, $port, 80));
            } catch (ResourceOperationException $exception) {
                return $exception->errorCode;
            }

            return null;
        };

        expect($refusal(null, 5173))->toBe('instance.dev_band_reserved')
            ->and($refusal('0.0.0.0', 5199))->toBe('instance.dev_band_reserved')
            ->and($refusal('10.253.0.1', 5180))->toBe('instance.dev_band_reserved')
            ->and($refusal('0.0.0.0', 5172))->toBeNull()
            ->and($refusal('0.0.0.0', 5200))->toBeNull()
            ->and($refusal('10.44.0.7', 5173))->toBeNull()
            ->and($refusal('127.0.0.1', 5173))->toBeNull();
    });

    it('refuses to attach a same-Node service in the band, and attaches one outside it', function (): void {
        $world = devPortWorld();
        $docker = static fn (string $name, string $port): Process => Process::query()->forceCreate(['owner_type' => Node::class, 'owner_id' => $world['node']->id,
            'name' => $name, 'runtime' => 'docker', 'runtime_config' => ['image' => $name, 'ports' => [$port]], 'status' => 'active', 'desired_state' => 'running']);

        expect(fn () => InstanceNodeServiceTargets::resolvePort($world['clone'], $docker('band', '5180:5180'), 'tcp', 5180))
            ->toThrow(ResourceOperationException::class, 'development band')
            ->and(InstanceNodeServiceTargets::resolvePort($world['clone'], $docker('search', '7700:7700'), 'tcp', 7700))->toBe(7700);
    });

    it('refuses a Docker Process that publishes in the band on every address, and allows the band on the WireGuard address', function (): void {
        $world = devPortWorld();
        $target = new ProcessTarget(node: $world['node'], user: 'orbit', checkoutPath: '/home/orbit');
        $data = static fn (string $port): AddProcessData => new AddProcessData(ProcessTargetType::Node, $world['node']->id, 'web', ProcessRuntime::Docker,
            [], 'nginx', null, [], [$port], [], 'unless-stopped', false);

        expect(fn () => app(ProcessSpecification::class)->attributes($data('5173:5173'), $target))
            ->toThrow(ResourceOperationException::class, 'development band')
            ->and(app(ProcessSpecification::class)->attributes($data('10.44.0.7:5173:5173'), $target)['runtime_config']['ports'])->toBe(['10.44.0.7:5173:5173'])
            ->and(app(ProcessSpecification::class)->attributes($data('5200:5200/tcp'), $target)['runtime_config']['ports'])->toBe(['5200:5200/tcp'])
            ->and(app(ProcessSpecification::class)->attributes($data('5173:5173/udp'), $target)['runtime_config']['ports'])->toBe(['5173:5173/udp']);
    });
});

describe('the host firewall of the own site', function (): void {
    it('admits 443 and the development listener in from the bridge, and never the raw range', function (): void {
        $world = devPortWorld();

        $intent = app(InstanceHostIntent::class)->for($world['node']);
        $rules = app(NodeFirewallRuleCatalog::class)->instanceHostInput($world['node']);

        expect($intent['own_site']['acl'])->toBe([
            ['action' => 'allow', 'destination' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '443', 'state' => 'enabled'],
            ['action' => 'allow', 'destination' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '444', 'state' => 'enabled'],
        ])
            ->and(array_map(static fn ($rule): array => $rule->arguments, array_slice($rules, 2)))->toBe([
                ['sudo', 'ufw', 'allow', 'in', 'on', 'orbitinst0', 'proto', 'tcp', 'from', 'any', 'to', '10.253.0.1', 'port', '443', 'comment', 'orbit:instances-own-site'],
                ['sudo', 'ufw', 'allow', 'in', 'on', 'orbitinst0', 'proto', 'tcp', 'from', 'any', 'to', '10.253.0.1', 'port', '444', 'comment', 'orbit:instances-own-dev'],
            ])
            ->and($intent['own_site']['rules'])->toBe(array_map(static fn ($rule): array => $rule->shape->toAgentInput(), array_slice($rules, 2)))
            ->and(array_filter($rules, static fn ($rule): bool => str_contains($rule->shape->port, '-')))->toBe([]);
    });
});

describe('the container side of the own site', function (): void {
    it('gives a clone on a private Route one target per own name and the dev rule, so the hosts line, NO_PROXY and the ACL follow', function (): void {
        $world = devPortWorld();
        $second = Route::query()->create(['kind' => 'app', 'project_id' => $world['project']->id, 'node_id' => $world['node']->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'api.feature.tool.test', 'status' => 'pending', 'web_root' => 'api']);
        RouteTarget::query()->create(['route_id' => $second->id, 'instance_id' => $world['clone']->id, 'position' => 0]);

        $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');
        $own = array_values(array_filter($container['acl_rules']['egress'], static fn (array $rule): bool => str_starts_with($rule['description'] ?? '', 'Orbit own')));

        expect($own)->toBe([
            ['action' => 'allow', 'destination' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '443', 'state' => 'enabled', 'description' => 'Orbit own site'],
            ['action' => 'allow', 'destination' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '443', 'state' => 'enabled', 'description' => 'Orbit own site'],
            InstanceOwnSite::nicEgress($world['node']),
        ])
            ->and($container['private_names'])->toBe([
                ['name' => 'feature.tool.test', 'host' => '10.253.0.1', 'port' => 443],
                ['name' => 'api.feature.tool.test', 'host' => '10.253.0.1', 'port' => 443],
            ])
            ->and($container['proxy_environment']['NO_PROXY'])->toEndWith(',feature.tool.test,api.feature.tool.test')
            ->and($container['config']['environment.NO_PROXY'])->toBe($container['proxy_environment']['NO_PROXY'])
            // The clone script writes the same value into the copy's drop-ins before its first start, so the first boot has the names.
            ->and($container['clone']['proxy_environment'])->toBe($container['proxy_environment'])
            // The Node's own-site rows admit the bridge side: no bridge endpoint and no per-Instance UFW rule for the gateway address.
            ->and(array_filter($container['bridge_endpoints'], static fn (array $endpoint): bool => $endpoint['host'] === '10.253.0.1'))->toBe([])
            ->and(array_filter(app(InstanceContainer::class)->nodeEndpoints($world['node']), static fn (array $endpoint): bool => $endpoint['host'] === '10.253.0.1'))->toBe([])
            ->and(array_filter($container['firewall'], static fn (array $rule): bool => ($rule['to_ip'] ?? null) === '10.253.0.1'))->toBe([]);

        $paths = app(InstanceContainer::class)->connectionPaths($world['clone'], $world['runtime']);
        expect($paths['reconciled'])->toBe(['descriptions' => ['Orbit websocket app', 'Orbit own site', 'Orbit own development ports'], 'egress' => $own])
            ->and($paths['private_names'])->toBe($container['private_names']);
    });

    it('gives a default and a clone without a Route no own target and no dev rule', function (): void {
        $world = devPortWorld();
        $defaultRuntime = InstanceRuntime::query()->where('instance_id', $world['default']->id)->firstOrFail();
        $own = Route::query()->create(['kind' => 'app', 'project_id' => $world['project']->id, 'node_id' => $world['node']->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'tool.test', 'status' => 'pending']);
        RouteTarget::query()->create(['route_id' => $own->id, 'instance_id' => $world['default']->id, 'position' => 0]);
        $plain = static fn (array $container): bool => ! array_any($container['acl_rules']['egress'], static fn (array $rule): bool => str_starts_with($rule['description'] ?? '', 'Orbit own'))
            && ! isset($container['private_names']);

        expect($plain(app(InstanceContainer::class)->intent($world['default'], $defaultRuntime)))->toBeTrue();

        RouteTarget::query()->where('instance_id', $world['clone']->id)->delete();
        expect($plain(app(InstanceContainer::class)->intent($world['clone']->refresh(), $world['runtime'])))->toBeTrue()
            ->and(app(InstanceContainer::class)->connectionPaths($world['clone'], $world['runtime'])['reconciled']['egress'])->toBe([]);
    });

    it('gives a clone on a public Route no own target and no dev rule', function (): void {
        $world = devPortWorld('public');

        $container = app(InstanceContainer::class)->intent($world['clone'], $world['runtime'], url: 'https://feature.tool.test');

        expect(array_any($container['acl_rules']['egress'], static fn (array $rule): bool => str_starts_with($rule['description'] ?? '', 'Orbit own')))->toBeFalse()
            ->and($container)->not->toHaveKey('private_names');
    });
});
