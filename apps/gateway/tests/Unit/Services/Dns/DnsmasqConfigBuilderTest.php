<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqConfigBuilder;
use Illuminate\Database\Eloquent\Collection;

it('renders only the resolver baseline when fleet has no resolvable nodes', function (): void {
    $config = new DnsmasqConfigBuilder()->build(new Collection);

    expect($config)
        ->toContain('no-resolv')
        ->and($config)
        ->toContain('server=1.1.1.1')
        ->and($config)
        ->toContain('server=8.8.8.8')
        ->and($config)
        ->toContain('conf-dir=/etc/dnsmasq.d/,*.conf')
        ->and($config)
        ->toContain('log-queries')
        ->and($config)
        ->toContain('log-facility=-')
        ->and($config)
        ->not->toContain('address=/');
});

it('emits concrete records for every active node and wildcard records only for development roles', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(
            ['name' => 'app-1', 'tld' => 'test', 'wireguard_address' => '10.6.0.3'],
            [NodeRoleName::AppDevelopment],
        ),
    ]);

    $config = new DnsmasqConfigBuilder()->build($nodes);

    expect($config)
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->and($config)
        ->not
        ->toContain('address=/gateway/10.6.0.2')
        ->and($config)
        ->toContain('address=/orbit.test/10.6.0.3')
        ->and($config)
        ->toContain('address=/test/10.6.0.3')
        ->and($config)
        ->toContain('local=/test/');
});

it('emits concrete orbit node-host records for active nodes with tld and wireguard address', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'mini', 'tld' => 'mini', 'wireguard_address' => '10.6.0.8']),
        dnsmasq_node(['name' => 'app-1', 'tld' => 'test', 'wireguard_address' => '10.6.0.7']),
    ]);

    $config = new DnsmasqConfigBuilder()->build($nodes);

    expect($config)->toContain('address=/orbit.mini/10.6.0.8')->and($config)->toContain('address=/orbit.test/10.6.0.7');
});

it('keeps the concrete node record distinct from router-owned orbit service routes', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => 'orbit', 'wireguard_address' => '10.6.0.2']);
    $route = new ProxyRoute([
        'domain' => 'websocket.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $route->setRelation('node', $router);

    $config = new DnsmasqConfigBuilder()->build(new Collection([$router]), new Collection([$route]));

    expect($config)
        ->toContain('address=/orbit/10.6.0.2')
        ->and($config)
        ->toContain('address=/orbit.orbit/10.6.0.2');
});

it('emits router-owned orbit service routes as an orbit tld mapping to the router wireguard address', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $route = new ProxyRoute([
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $route->setRelation('node', $router);

    $config = new DnsmasqConfigBuilder()->build(new Collection([$router]), new Collection([$route]));

    expect($config)
        ->toContain('address=/orbit/10.6.0.2')
        ->and($config)
        ->toContain('local=/orbit/')
        ->and($config)
        ->not->toContain('address=/metrics.orbit/');
});

it('emits concrete s3 backend records before the router orbit wildcard', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $storage = dnsmasq_node(
        ['name' => 'services1', 'tld' => 'services1', 'wireguard_address' => '10.6.0.14'],
        [NodeRoleName::S3],
    );
    $route = new ProxyRoute([
        'domain' => 's3.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
        'config' => [
            'protocol' => 's3',
            'upstreams' => [
                ['scheme' => 'http', 'host' => 'services1.s3.orbit', 'port' => 8333],
            ],
        ],
    ]);
    $route->setRelation('node', $router);

    $config = new DnsmasqConfigBuilder()->build(
        new Collection([$router, $storage]),
        new Collection([$route]),
    );

    expect($config)
        ->toContain('address=/services1.s3.orbit/10.6.0.14')
        ->and(strpos(haystack: $config, needle: 'address=/services1.s3.orbit/10.6.0.14'))
        ->toBeLessThan(strpos(haystack: $config, needle: 'address=/orbit/10.6.0.2'));
});

it('emits a single private orbit tld mapping for multiple router-owned orbit service routes', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $metricsRoute = new ProxyRoute([
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $metricsRoute->setRelation('node', $router);
    $analyticsRoute = new ProxyRoute([
        'domain' => 'analytics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $analyticsRoute->setRelation('node', $router);

    $config = new DnsmasqConfigBuilder()->build(
        new Collection([$router]),
        new Collection([$metricsRoute, $analyticsRoute]),
    );

    expect($config)
        ->toContain('address=/orbit/10.6.0.2')
        ->and(substr_count($config, 'address=/orbit/10.6.0.2'))
        ->toBe(1);
});

it('resolves router-owned orbit service routes without requiring a loaded node relation', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => null, 'wireguard_address' => '10.6.0.2']);
    $router->id = 42;
    $route = new ProxyRoute([
        'node_id' => 42,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $config = new DnsmasqConfigBuilder()->build(new Collection([$router]), new Collection([$route]));

    expect($config)->toContain('address=/orbit/10.6.0.2')->and($config)->toContain('local=/orbit/');
});

it('skips nodes missing tld', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(['name' => 'app-untagged', 'tld' => null, 'wireguard_address' => '10.6.0.3']),
    ]);

    $config = new DnsmasqConfigBuilder()->build($nodes);

    expect($config)
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->and($config)
        ->not->toContain('10.6.0.3');
});

it('skips nodes missing wireguard address', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'app-1', 'tld' => 'app-one', 'wireguard_address' => '10.6.0.3']),
        dnsmasq_node(['name' => 'app-pending', 'tld' => 'pending', 'wireguard_address' => null]),
    ]);

    $config = new DnsmasqConfigBuilder()->build($nodes);

    expect($config)
        ->toContain('address=/orbit.app-one/10.6.0.3')
        ->and($config)
        ->not->toContain('pending');
});

it('emits address lines in stable alphabetical order by tld', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'z-app', 'tld' => 'zeta', 'wireguard_address' => '10.6.0.5']),
        dnsmasq_node(['name' => 'a-app', 'tld' => 'alpha', 'wireguard_address' => '10.6.0.4']),
        dnsmasq_node(['name' => 'm-app', 'tld' => 'mu', 'wireguard_address' => '10.6.0.6']),
    ]);

    $config = new DnsmasqConfigBuilder()->build($nodes);

    $alphaPos = strpos(haystack: $config, needle: 'address=/orbit.alpha/');
    $muPos = strpos(haystack: $config, needle: 'address=/orbit.mu/');
    $zetaPos = strpos(haystack: $config, needle: 'address=/orbit.zeta/');

    expect($alphaPos)
        ->toBeInt()
        ->and($muPos)
        ->toBeInt()
        ->and($zetaPos)
        ->toBeInt()
        ->and($alphaPos)
        ->toBeLessThan($muPos)
        ->and($muPos)
        ->toBeLessThan($zetaPos);
});

it('produces byte-identical output for identical inputs', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(['name' => 'app-1', 'tld' => 'test', 'wireguard_address' => '10.6.0.3']),
    ]);

    $first = new DnsmasqConfigBuilder()->build($nodes);
    $second = new DnsmasqConfigBuilder()->build($nodes);

    expect($first)->toBe($second);
});

it('terminates with a trailing newline', function (): void {
    $config = new DnsmasqConfigBuilder()->build(new Collection);

    expect(str_ends_with($config, "\n"))->toBeTrue();
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  list<NodeRoleName>  $roles
 */
function dnsmasq_node(array $attributes, array $roles = []): Node
{
    $node = new Node([
        'status' => NodeStatus::Active,
        ...$attributes,
    ]);

    $node->setRelation(
        'roleAssignments',
        new Collection(array_map(
            static fn (NodeRoleName $role): NodeRoleAssignment => new NodeRoleAssignment([
                'role' => $role->value,
                'status' => NodeRoleStatus::Active,
            ]),
            $roles,
        )),
    );

    return $node;
}
