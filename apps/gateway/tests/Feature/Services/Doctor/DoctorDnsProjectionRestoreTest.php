<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorRunRequest;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Doctor\DoctorReportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-doctor-dns-restore-'.bin2hex(random_bytes(4));
    config()->set('orbit.paths.config_root', $this->root);
    File::ensureDirectoryExists($this->root.'/dnsmasq.d');
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns'") {
            return Process::result("orbit-dns-id\n");
        }

        if (str_starts_with($command, 'docker inspect --format')) {
            return Process::result(json_encode([[
                'Type' => 'bind',
                'Source' => $this->root.'/dnsmasq.d',
                'Destination' => '/etc/dnsmasq.d',
                'RW' => false,
            ]], JSON_THROW_ON_ERROR));
        }

        if ($command === "docker service inspect 'orbit_orbit-dns'") {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });
    app()->forgetInstance(DnsmasqReconciler::class);
    app()->forgetInstance(DnsRuntimeProbe::class);
    app()->forgetInstance(DoctorReportRunner::class);
});

it('marks a completed tool DNS action failed when live runtime drift remains after restore', function (): void {
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'tld' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'status' => 'active',
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => '203.0.113.10',
            'dns_ip' => '10.6.0.1',
        ],
    ]);
    File::put($this->root.'/dnsmasq.conf', new DnsmasqBaseConfigBuilder()->build());
    File::put(
        $this->root.'/dnsmasq.d/10-node-records.conf',
        new NodeDnsmasqRecordsBuilder()->buildGatewayState(),
    );
    File::put(
        $this->root.'/dnsmasq.d/20-proxy-records.conf',
        new ProxyDnsmasqRecordsBuilder()->buildGatewayState(),
    );
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if (str_contains($command, 'label=com.docker.swarm.service.name=orbit_orbit-dns')) {
            return Process::result("orbit-dns-id\n");
        }

        if (str_contains($command, "docker exec 'orbit-dns-id'")) {
            return Process::result('udp 0 0 :::53 :::* LISTEN');
        }

        if (str_starts_with($command, 'docker inspect --format')) {
            return Process::result(json_encode([[
                'Source' => '/wrong/dnsmasq.d',
                'Destination' => '/etc/dnsmasq.d',
                'RW' => false,
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result(exitCode: 1);
    });
    app()->forgetInstance(DnsRuntimeProbe::class);
    app()->forgetInstance(DoctorReportRunner::class);
    $runner = app(DoctorReportRunner::class);
    $probe = $runner->probe($gateway, families: ['tool'], key: 'tool.dns_base_config_mismatch');

    expect($runner->restoreRequiresVerification(
        'restore',
        'tool.dns_base_config_mismatch',
        $probe,
    ))->toBeTrue();

    $report = $runner->finalizeRestore(
        $gateway,
        ['tool'],
        'tool.dns_base_config_mismatch',
        \App\Data\Doctor\DoctorTargetScope::none(),
        [[
            'family' => 'tool',
            'node' => 'gateway',
            'key' => 'tool.dns_base_config_mismatch',
            'mode' => 'restore',
            'status' => 'completed',
            'summary' => 'Restored DNS base configuration.',
            'details' => [],
        ]],
    );

    expect($report['healthy'])
        ->toBeFalse()
        ->and($report['issues'][0]['key'])
        ->toBe('tool.dns_base_config_mismatch')
        ->and($report['actions'][0]['status'])
        ->toBe('failed');
});

afterEach(function (): void {
    $root = $this->root ?? null;

    if (is_string($root) && is_dir($root)) {
        File::deleteDirectory($root);
    }
});

it('routes node DNS restore to only the node-owned projection', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    $base = new DnsmasqBaseConfigBuilder()->build();
    File::put($this->root.'/dnsmasq.conf', $base);
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "stale node bytes\n");
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "proxy owner sentinel\n");

    $actions = app(DoctorReportRunner::class)->apply($node, 'restore', [[
        'family' => 'node',
        'node' => $node->name,
        'key' => 'node.dns_mapping_mismatch',
        'code' => 'node.dns_mapping_mismatch',
        'kind' => 'divergent',
        'summary' => 'Node DNS projection is stale.',
        'detail' => ['record_kind' => 'node_host'],
        'restorable' => true,
        'adoptable' => false,
    ]]);

    expect($actions)
        ->toHaveCount(1)
        ->and($actions[0]['family'])
        ->toBe('node')
        ->and($actions[0]['status'])
        ->toBe('completed')
        ->and(File::get($this->root.'/dnsmasq.conf'))
        ->toBe($base)
        ->and(File::get($this->root.'/dnsmasq.d/10-node-records.conf'))
        ->toBe(new NodeDnsmasqRecordsBuilder()->buildGatewayState())
        ->and(File::get($this->root.'/dnsmasq.d/20-proxy-records.conf'))
        ->toBe("proxy owner sentinel\n");
});

it('routes proxy DNS restore to only the proxy-owned projection', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);
    $base = new DnsmasqBaseConfigBuilder()->build();
    File::put($this->root.'/dnsmasq.conf', $base);
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "node owner sentinel\n");
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "stale proxy bytes\n");

    $actions = app(DoctorReportRunner::class)->apply($router, 'restore', [[
        'family' => 'proxy',
        'node' => $router->name,
        'key' => 'proxy.dns_mapping_mismatch',
        'code' => 'proxy.dns_mapping_mismatch',
        'kind' => 'divergent',
        'summary' => 'Proxy DNS projection is stale.',
        'detail' => [],
        'restorable' => true,
        'adoptable' => false,
    ]]);

    expect($actions)
        ->toHaveCount(1)
        ->and($actions[0]['family'])
        ->toBe('proxy')
        ->and($actions[0]['status'])
        ->toBe('completed')
        ->and(File::get($this->root.'/dnsmasq.conf'))
        ->toBe($base)
        ->and(File::get($this->root.'/dnsmasq.d/10-node-records.conf'))
        ->toBe("node owner sentinel\n")
        ->and(File::get($this->root.'/dnsmasq.d/20-proxy-records.conf'))
        ->toBe(new ProxyDnsmasqRecordsBuilder()->buildGatewayState());
});

it('leaves owner projection drift unresolved while the live projection mount is absent', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    File::put($this->root.'/dnsmasq.conf', new DnsmasqBaseConfigBuilder()->build());
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "stale node bytes\n");
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "proxy owner sentinel\n");
    Process::fake(fn () => Process::result(exitCode: 1));
    app()->forgetInstance(DnsmasqReconciler::class);
    app()->forgetInstance(DoctorReportRunner::class);

    $actions = app(DoctorReportRunner::class)->apply($node, 'restore', [[
        'family' => 'node',
        'node' => $node->name,
        'key' => 'node.dns_mapping_mismatch',
        'code' => 'node.dns_mapping_mismatch',
        'kind' => 'divergent',
        'summary' => 'Node DNS projection is stale.',
        'detail' => ['record_kind' => 'node_host'],
        'restorable' => true,
        'adoptable' => false,
    ]]);

    expect($actions)
        ->toHaveCount(1)
        ->and($actions[0]['status'])
        ->toBe('failed')
        ->and(File::get($this->root.'/dnsmasq.d/10-node-records.conf'))
        ->toBe("stale node bytes\n")
        ->and(File::get($this->root.'/dnsmasq.d/20-proxy-records.conf'))
        ->toBe("proxy owner sentinel\n");
});

it('leaves scoped DNS projection drift unresolved while a mounted runtime still uses the legacy monolith', function (
    string $family,
    string $key,
    string $ownedPath,
): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    $legacy = "address=/orbit.database/10.6.0.9\nno-resolv\n";
    File::put($this->root.'/dnsmasq.conf', $legacy);
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "node owner sentinel\n");
    File::put($this->root.'/dnsmasq.d/20-proxy-records.conf', "proxy owner sentinel\n");

    $actions = app(DoctorReportRunner::class)->apply($node, 'restore', [[
        'family' => $family,
        'node' => $node->name,
        'key' => $key,
        'code' => $key,
        'kind' => 'divergent',
        'summary' => 'DNS projection is stale.',
        'detail' => [],
        'restorable' => true,
        'adoptable' => false,
    ]]);

    expect($actions)
        ->toHaveCount(1)
        ->and($actions[0]['status'])
        ->toBe('failed')
        ->and($actions[0]['details']['error'])
        ->toContain('owner-scoped reconciliation')
        ->and(File::get($this->root.'/dnsmasq.conf'))
        ->toBe($legacy)
        ->and(File::get($this->root.'/'.$ownedPath))
        ->toContain('owner sentinel');
})->with([
    'node owner' => ['node', 'node.dns_mapping_mismatch', 'dnsmasq.d/10-node-records.conf'],
    'proxy owner' => ['proxy', 'proxy.dns_mapping_mismatch', 'dnsmasq.d/20-proxy-records.conf'],
]);

it('keeps node DNS drift visible when post-restore verification still finds it', function (): void {
    // Node DNS projection is verified only on the DNS-serving host
    // (gateway-coupled VPN), while mismatches still name their source node.
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'tld' => 'gateway',
            'wireguard_address' => '10.6.0.2',
            'status' => 'active',
        ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => '203.0.113.10',
            'dns_ip' => '10.6.0.1',
        ],
    ]);
    Node::factory()->create([
        'name' => 'database-1',
        'tld' => 'database',
        'wireguard_address' => '10.6.0.9',
        'status' => 'active',
    ]);
    File::put($this->root.'/dnsmasq.conf', new DnsmasqBaseConfigBuilder()->build());
    File::put($this->root.'/dnsmasq.d/10-node-records.conf', "stale node bytes\n");
    File::put(
        $this->root.'/dnsmasq.d/20-proxy-records.conf',
        new ProxyDnsmasqRecordsBuilder()->buildGatewayState(),
    );
    app()->instance(DnsmasqReconciler::class, new class extends DnsmasqReconciler {
        public function __construct() {}

        public function projectionDirectoryIsMounted(): bool
        {
            return true;
        }

        public function reconcileNodeRecords(): bool
        {
            return true;
        }
    });
    app()->forgetInstance(DoctorReportRunner::class);

    $report = app(DoctorReportRunner::class)->run(
        node: $gateway,
        mode: 'restore',
        families: ['node'],
        request: new DoctorRunRequest(key: 'node.dns_mapping_mismatch'),
    );

    expect($report['healthy'])
        ->toBeFalse()
        ->and($report['issues'])
        ->not
        ->toBeEmpty()
        ->and(collect($report['actions'])->firstWhere('key', 'node.dns_mapping_mismatch')['status'] ?? null)
        ->toBe('failed');
});
