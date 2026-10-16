<?php

declare(strict_types=1);

use App\Domain\AppDev\ContributedPrivateDnsRecords;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;

final class ProxyCliDnsConfigRendererTestTakesCore implements ContributedPrivateDnsRecords
{
    public function records(): array
    {
        return ['gateway.orbit' => '10.44.0.99'];
    }
}

final class ProxyCliDnsConfigRendererTestTakesRoute implements ContributedPrivateDnsRecords
{
    public function records(): array
    {
        return ['shop.test' => '10.44.0.99'];
    }
}

final class ProxyCliDnsConfigRendererTestTakesCollector implements ContributedPrivateDnsRecords
{
    public function records(): array
    {
        return [ProxyCliHostname::Value => '10.44.0.99'];
    }
}

it('publishes collector.cli-proxy-api.orbit on the collector Node, not the Gateway or the apex', function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $collector = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    app(ProxyCliState::class)->enable(
        $collector->id,
        'valkey',
        'http://127.0.0.1:8317',
        'management-key',
        'read-token',
        'control-token',
    );

    $configuration = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository)->render();

    expect($configuration)
        ->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.8')
        ->not
        ->toContain('host-record=cli-proxy-api.orbit,')
        ->not
        ->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.1'.PHP_EOL)
        ->not
        ->toContain('collector.proxycli.orbit');
});

it('publishes one collector record while a custom proxy Route still serves the name', function (): void {
    $collector = Node::query()->create([
        'name' => 'services',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.17',
    ]);
    $route = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $collector->id,
        'domain' => 'collector.cli-proxy-api.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->customProxy()->create(['node_id' => $collector->id, 'upstream' => 'http://127.0.0.1:8787']);
    $route->update(['status' => RouteStatus::Active]);
    app(ProxyCliState::class)->enable($collector->id, 'valkey', 'http://127.0.0.1:8317', 'key', 'read', 'control');

    $configuration = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository)->render();

    expect(substr_count($configuration, 'host-record=collector.cli-proxy-api.orbit,'))->toBe(1)
        ->and($configuration)->toContain('host-record=collector.cli-proxy-api.orbit,10.44.0.17'.PHP_EOL);
});

it('omits collector.cli-proxy-api.orbit when the fleet feature is disabled', function (): void {
    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $state = app(ProxyCliState::class);
    $state->enable($node->id, 'valkey', 'http://127.0.0.1:8317', 'key', 'read', 'control');
    $state->disable();

    $configuration = new DevelopmentDnsConfigRenderer(new DevelopmentSiteRepository)->render();

    expect($configuration)
        ->not
        ->toContain('collector.cli-proxy-api.orbit')
        ->not
        ->toContain('host-record=cli-proxy-api.orbit,');
});

it('renders the records and the catalog that main rendered for the live fleet, byte for byte', function (): void {
    private_dns_live_fleet();
    $renderer = app(DevelopmentDnsConfigRenderer::class);

    expect($renderer->render())->toBe(file_get_contents(base_path('tests/Fixtures/PrivateDns/live-fleet-dnsmasq-main.conf')))
        ->and(private_dns_published_catalog($renderer))->toBe(file_get_contents(base_path('tests/Fixtures/PrivateDns/live-fleet-catalog-main.json')));
});

it('refuses a contributed record that no extension reserves, or that another contribution publishes', function (string $contribution, string $message): void {
    private_dns_live_fleet();
    app()->tag([$contribution], ContributedPrivateDnsRecords::class);

    expect(fn (): string => app(DevelopmentDnsConfigRenderer::class)->render())
        ->toThrow(LogicException::class, "The contribution [{$contribution}] publishes the private DNS record {$message}");
})->with([
    'a core hostname' => [ProxyCliDnsConfigRendererTestTakesCore::class, '[gateway.orbit], which no extension reserves.'],
    'a Route domain' => [ProxyCliDnsConfigRendererTestTakesRoute::class, '[shop.test], which no extension reserves.'],
    'the collector hostname' => [ProxyCliDnsConfigRendererTestTakesCollector::class, '[collector.cli-proxy-api.orbit], which another contribution publishes.'],
]);

/**
 * The live fleet's private DNS: the `vpn` and `gateway` Nodes, `services` with the collector, Reverb and analytics,
 * the `dev` Cluster with its Router `beast` and the `metrics` role, and the `prod` Cluster.
 *
 * @return array<string, Node>
 */
function private_dns_live_fleet(): array
{
    $dev = Cluster::query()->create(['name' => 'dev', 'tld' => 'test', 'state' => ClusterState::Active]);
    $prod = Cluster::query()->create(['name' => 'prod', 'state' => ClusterState::Active]);
    $node = static function (string $name, string $address, array $roles, array $attributes = []): Node {
        $node = Node::query()->create([
            'name' => $name,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "{$name}.example.test",
            'user' => 'orbit',
            'wireguard_ip' => $address,
            ...$attributes,
        ]);

        foreach ($roles as $role) {
            $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active, 'cluster_id' => in_array($role, [RoleName::Router, RoleName::Ingress], true) ? $node->cluster_id : null]);
        }

        return $node;
    };
    $nodes = [
        'vpn' => $node('vpn', '10.44.0.1', [RoleName::Vpn]),
        'gateway' => $node('gateway', '10.44.0.2', [RoleName::Gateway]),
        'services' => $node('services', '10.44.0.3', [RoleName::AppDev, RoleName::Database, RoleName::WebSocket, RoleName::Analytics]),
        'app-prod' => $node('app-prod', '10.44.0.4', [RoleName::Router, RoleName::Ingress, RoleName::AppProd], ['tld' => 'prod', 'cluster_id' => $prod->id]),
        'beast' => $node('beast', '10.44.0.7', [RoleName::Router, RoleName::AppDev, RoleName::Metrics, RoleName::Database], ['tld' => 'test', 'cluster_id' => $dev->id, 'lan_ip' => '192.168.6.20']),
        'sabre' => $node('sabre', '10.44.0.10', [], ['cluster_id' => $dev->id, 'lan_ip' => '192.168.6.30']),
        'shark' => $node('shark', '10.44.0.11', [RoleName::AppDev, RoleName::Database], ['cluster_id' => $dev->id, 'lan_ip' => '192.168.6.40']),
        'mini' => $node('mini', '10.44.0.9', [], ['platform' => 'macos']),
    ];
    $project = Project::query()->create(['name' => 'shop', 'slug' => 'shop', 'repository_url' => 'https://example.test/shop.git', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $nodes['shark']->id,
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/shop',
        'root' => 'public',
        'selected_php_version' => '8.5',
        'status' => InstanceState::Active,
    ]);
    $shop = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $dev->id,
        'domain' => 'shop.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $shop->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $shop->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $management = Route::query()->create([
        'kind' => RouteKind::CustomProxy,
        'node_id' => $nodes['services']->id,
        'domain' => 'cli-proxy-api.orbit',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $management->customProxy()->create(['node_id' => $nodes['services']->id, 'upstream' => 'http://127.0.0.1:8317']);
    $management->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    app(ProxyCliState::class)->enable($nodes['services']->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');

    return array_map(static fn (Node $node): Node => $node->fresh('roles') ?? $node, $nodes);
}

/** The private DNS catalog that publication writes, with each requester's Node by name. */
function private_dns_published_catalog(DevelopmentDnsConfigRenderer $renderer): string
{
    $names = Node::query()->pluck('name', 'id')->all();

    return json_encode([
        'requesters' => array_map(static fn (int $id): string => $names[$id], $renderer->registeredRequesters()),
        ...$renderer->catalog()->toPublished(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
}
