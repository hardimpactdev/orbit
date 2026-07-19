<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use Illuminate\Database\Eloquent\Collection;

it('renders the exact tool-owned resolver base without semantic records', function (): void {
    $config = new DnsmasqBaseConfigBuilder()->build();

    expect($config)->toBe(<<<'CONF'
        # orbit-managed=dnsmasq-base
        no-resolv
        server=1.1.1.1
        server=8.8.8.8
        conf-file=/etc/dnsmasq.d/10-node-records.conf
        conf-file=/etc/dnsmasq.d/20-proxy-records.conf
        log-queries
        log-facility=-
        CONF."\n");
});

it('renders only node-owned concrete and role-derived wildcard records', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'tools', 'tld' => 'tools', 'wireguard_address' => '10.6.0.4'], [
            NodeRoleName::Agent,
        ]),
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(['name' => 'app-1', 'tld' => 'test', 'wireguard_address' => '10.6.0.3'], [
            NodeRoleName::AppDevelopment,
        ]),
    ]);

    $records = new NodeDnsmasqRecordsBuilder()->build($nodes);

    expect($records)->toBe(<<<'CONF'
        # orbit-managed=node-dns-records
        address=/orbit.gateway/10.6.0.2
        address=/orbit.test/10.6.0.3
        address=/test/10.6.0.3
        local=/test/
        address=/orbit.tools/10.6.0.4
        address=/tools/10.6.0.4
        local=/tools/
        CONF."\n");
});

it('keeps tool and proxy directives out of the node-owned projection', function (): void {
    $records = new NodeDnsmasqRecordsBuilder()->build(new Collection([
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
    ]));

    expect($records)
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->not->toContain('address=/orbit/10.6.0.2')
        ->not->toContain('.s3.orbit/')
        ->not->toContain('no-resolv')
        ->not->toContain('server=')
        ->not->toContain('conf-file=')
        ->not->toContain('log-queries');
});

it('skips inactive or incomplete nodes in the node-owned projection', function (): void {
    $inactive = dnsmasq_node([
        'name' => 'inactive',
        'status' => NodeStatus::Inactive,
        'tld' => 'inactive',
        'wireguard_address' => '10.6.0.5',
    ]);

    $records = new NodeDnsmasqRecordsBuilder()->build(new Collection([
        dnsmasq_node(['name' => 'ready', 'tld' => 'ready', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(['name' => 'missing-tld', 'tld' => null, 'wireguard_address' => '10.6.0.3']),
        dnsmasq_node(['name' => 'missing-address', 'tld' => 'pending', 'wireguard_address' => null]),
        $inactive,
    ]));

    expect($records)
        ->toBe("# orbit-managed=node-dns-records\naddress=/orbit.ready/10.6.0.2\n")
        ->not->toContain('missing-tld')
        ->not->toContain('pending')
        ->not->toContain('inactive');
});

it('does not emit node records into the reserved private service namespace', function (): void {
    $records = new NodeDnsmasqRecordsBuilder()->build(new Collection([
        dnsmasq_node(['name' => 'legacy', 'tld' => 'orbit', 'wireguard_address' => '10.6.0.9'], [
            NodeRoleName::AppDevelopment,
        ]),
    ]));

    expect($records)
        ->toBe("# orbit-managed=node-dns-records\n")
        ->not->toContain('address=/orbit.orbit/')
        ->not->toContain('address=/orbit/');
});

it('renders only proxy-owned private service and backend records', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']);
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

    $records = new ProxyDnsmasqRecordsBuilder()->build(
        new Collection([$router, $storage]),
        new Collection([$route]),
    );

    expect($records)->toBe(<<<'CONF'
        # orbit-managed=proxy-dns-records
        address=/services1.s3.orbit/10.6.0.14
        address=/orbit/10.6.0.2
        local=/orbit/
        CONF."\n");
});

it('keeps tool and node directives out of the proxy-owned projection', function (): void {
    $router = dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']);
    $route = new ProxyRoute([
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $route->setRelation('node', $router);

    $records = new ProxyDnsmasqRecordsBuilder()->build(
        new Collection([$router]),
        new Collection([$route]),
    );

    expect($records)
        ->toBe("# orbit-managed=proxy-dns-records\naddress=/orbit/10.6.0.2\nlocal=/orbit/\n")
        ->not->toContain('address=/orbit.gateway/')
        ->not->toContain('no-resolv')
        ->not->toContain('server=')
        ->not->toContain('conf-file=')
        ->not->toContain('log-queries');
});

it('produces byte-identical owner projections for identical inputs', function (): void {
    $nodes = new Collection([
        dnsmasq_node(['name' => 'gateway', 'tld' => 'gateway', 'wireguard_address' => '10.6.0.2']),
        dnsmasq_node(['name' => 'app-1', 'tld' => 'test', 'wireguard_address' => '10.6.0.3']),
    ]);

    expect(new NodeDnsmasqRecordsBuilder()->build($nodes))
        ->toBe(new NodeDnsmasqRecordsBuilder()->build($nodes));
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
