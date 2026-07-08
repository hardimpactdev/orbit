<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Doctor\DnsRuntimeProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-probe-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);

    $this->probe = new DnsRuntimeProbe(
        configBuilder: new DnsmasqConfigBuilder,
        rootPath: $this->workdir,
    );
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('reports dns.container_missing when orbit-dns is absent', function (): void {
    Process::fake([
        'docker ps*' => Process::result(''),
    ]);

    $drift = $this->probe->probe();

    expect($drift)->toHaveCount(1)->and($drift[0]->key)->toBe('dns.container_missing');
});

it('reports dns.port_not_listening when port 53 is silent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec*' => Process::result(''),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = new DnsmasqConfigBuilder()->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->toContain('dns.port_not_listening');
});

it('reports dns.config_drift when on-disk dnsmasq.conf differs from intent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    File::put($this->workdir.'/dnsmasq.conf', "stale content\n");

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->toContain('dns.config_drift');
});

it('does not report drift when runtime is healthy and config matches intent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = new DnsmasqConfigBuilder()->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);

    $drift = $this->probe->probe();

    expect($drift)->toBe([]);
});

it('recognizes the swarm dns task as the dns runtime container', function (): void {
    Process::fake([
        'docker ps -a -q -f name=orbit-dns' => Process::result(''),
        "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns'" => Process::result(
            'swarm-dns-task',
        ),
        'docker exec*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = new DnsmasqConfigBuilder()->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);

    $drift = $this->probe->probe();

    expect($drift)->toBe([]);
});

it('reports dns.forwarding_missing when swarm vpn dns forwarding is absent', function (): void {
    create_dns_runtime_probe_swarm_stack_marker($this->workdir);
    write_dns_runtime_probe_expected_config($this->workdir);
    fake_dns_runtime_probe_swarm_runtime_without_forwarding();

    $drift = $this->probe->probe();
    $entry = collect($drift)->first(fn ($entry): bool => $entry->key === 'dns.forwarding_missing');

    expect($entry)
        ->not
        ->toBeNull()
        ->and($entry->summary)
        ->toBe('VPN DNS forwarding from WireGuard peers to orbit-dns is missing.')
        ->and($entry->detail['vpn_service'])
        ->toBe('orbit_orbit-vpn')
        ->and($entry->detail['dns_service'])
        ->toBe('orbit_orbit-dns');
});

it('does not report dns.forwarding_missing when swarm vpn dns forwarding is present', function (): void {
    create_dns_runtime_probe_swarm_stack_marker($this->workdir);
    write_dns_runtime_probe_expected_config($this->workdir);
    fake_dns_runtime_probe_swarm_runtime_with_forwarding();

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->not->toContain('dns.forwarding_missing');
});

it('reports dns.client_dns_drift when wg-easy client DNS is not pinned to the vpn dns endpoint', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = new DnsmasqConfigBuilder()->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);
    createDnsRuntimeProbeWgEasyDatabase($this->workdir.'/wg-easy/wg-easy.db', defaultDns: '["10.6.0.1"]', clients: [
        ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
        ['name' => 'app-1', 'ipv4_address' => '10.6.0.4', 'dns' => '["10.6.0.1"]'],
    ]);

    $drift = $this->probe->probe();
    $entry = collect($drift)->first(fn ($entry): bool => $entry->key === 'dns.client_dns_drift');

    expect($entry)
        ->not
        ->toBeNull()
        ->and($entry->summary)
        ->toBe('wg-easy client DNS is not pinned to the VPN DNS endpoint.')
        ->and($entry->detail['expected_dns'])
        ->toBe('10.6.0.1')
        ->and($entry->detail['clients'])
        ->toBe([
            [
                'name' => 'operator',
                'ipv4_address' => '10.6.0.3',
                'dns' => ['10.6.0.1', '1.1.1.1'],
            ],
        ]);
});

it('does not report client dns drift when wg-easy default and client DNS match intent', function (): void {
    Process::fake([
        'docker ps*' => Process::result('orbit-dns-id'),
        'docker exec*' => Process::result('udp 0 0 :::53 :::* LISTEN'),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $expected = new DnsmasqConfigBuilder()->build(Node::query()->get());
    File::put($this->workdir.'/dnsmasq.conf', $expected);
    createDnsRuntimeProbeWgEasyDatabase($this->workdir.'/wg-easy/wg-easy.db', defaultDns: '["10.6.0.1"]', clients: [
        ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1"]'],
    ]);

    $drift = $this->probe->probe();

    expect(collect($drift)->pluck('key')->all())->not->toContain('dns.client_dns_drift');
});

it('restores wg-easy client dns drift by updating persisted default and client DNS', function (): void {
    createDnsRuntimeProbeWgEasyDatabase(
        $this->workdir.'/wg-easy/wg-easy.db',
        defaultDns: '["10.6.0.1","1.1.1.1"]',
        clients: [
            ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
            ['name' => 'app-1', 'ipv4_address' => '10.6.0.4', 'dns' => '["1.1.1.1"]'],
        ],
    );

    $result = $this->probe->restore('dns.client_dns_drift');

    expect($result)
        ->toBeTrue()
        ->and(readDnsRuntimeProbeWgEasyDefaultDns($this->workdir.'/wg-easy/wg-easy.db'))
        ->toBe('["10.6.0.1"]')
        ->and(readDnsRuntimeProbeWgEasyClientDns($this->workdir.'/wg-easy/wg-easy.db'))
        ->toBe([
            'app-1' => '["10.6.0.1"]',
            'operator' => '["10.6.0.1"]',
        ]);
});

it('marks the five drift kinds as restorable', function (): void {
    expect($this->probe->isRestorable('dns.container_missing'))
        ->toBeTrue()
        ->and($this->probe->isRestorable('dns.port_not_listening'))
        ->toBeTrue()
        ->and($this->probe->isRestorable('dns.config_drift'))
        ->toBeTrue()
        ->and($this->probe->isRestorable('dns.client_dns_drift'))
        ->toBeTrue()
        ->and($this->probe->isRestorable('dns.forwarding_missing'))
        ->toBeTrue()
        ->and($this->probe->isRestorable('dns.unknown'))
        ->toBeFalse();
});

it('does not mark dns runtime drift as adoptable', function (): void {
    expect($this->probe->isAdoptable('dns.config_drift'))
        ->toBeFalse()
        ->and($this->probe->isAdoptable('dns.container_missing'))
        ->toBeFalse()
        ->and($this->probe->isAdoptable('dns.port_not_listening'))
        ->toBeFalse()
        ->and($this->probe->isAdoptable('dns.client_dns_drift'))
        ->toBeFalse()
        ->and($this->probe->isAdoptable('dns.forwarding_missing'))
        ->toBeFalse();
});

it('restores config drift by rewriting dnsmasq.conf and restarting orbit-dns', function (): void {
    Process::fake([
        'docker restart orbit-dns' => Process::result(),
    ]);

    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    File::put($this->workdir.'/dnsmasq.conf', "stale\n");

    $result = $this->probe->restore('dns.config_drift');

    expect($result)
        ->toBeTrue()
        ->and(File::get($this->workdir.'/dnsmasq.conf'))
        ->toContain('address=/gateway/10.6.0.2');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker restart orbit-dns'));
});

it('restores config drift in swarm by forcing the orbit dns service update and reconverging forwarding', function (): void {
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(),
        "docker service update --force 'orbit_orbit-dns'" => Process::result(),
        "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'" => Process::result(
            "vpn-container-id\n",
        ),
        'docker exec*' => Process::result(),
    ]);

    File::ensureDirectoryExists($this->workdir.'/swarm');
    File::put($this->workdir.'/swarm/orbit-vpn-dns-stack.yml', "services:\n  orbit-dns: {}\n");
    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    File::put($this->workdir.'/dnsmasq.conf', "stale\n");

    $result = $this->probe->restore('dns.config_drift');

    expect($result)
        ->toBeTrue()
        ->and(File::get($this->workdir.'/dnsmasq.conf'))
        ->toContain('address=/gateway/10.6.0.2');

    Process::assertRan(
        fn ($process): bool => (string) $process->command === "docker service update --force 'orbit_orbit-dns'",
    );
    Process::assertRan(
        fn ($process): bool => (
            str_contains((string) $process->command, "docker exec 'vpn-container-id' sh -lc")
            && str_contains((string) $process->command, 'PREROUTING')
            && str_contains((string) $process->command, 'MASQUERADE')
        ),
    );
});

it('restores missing swarm vpn dns forwarding by converging the vpn task namespace', function (): void {
    Process::fake([
        "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-vpn'" => Process::result(
            "vpn-container-id\n",
        ),
        'docker exec*' => Process::result(),
    ]);

    $result = $this->probe->restore('dns.forwarding_missing');

    expect($result)->toBeTrue();

    Process::assertRan(
        fn ($process): bool => (
            str_contains((string) $process->command, "docker exec 'vpn-container-id' sh -lc")
            && str_contains((string) $process->command, 'getent hosts')
            && str_contains((string) $process->command, 'orbit-dns')
            && str_contains((string) $process->command, 'PREROUTING')
            && str_contains((string) $process->command, 'MASQUERADE')
        ),
    );
});

/**
 * @param  list<array{name: string, ipv4_address: string, dns: string}>  $clients
 */
function createDnsRuntimeProbeWgEasyDatabase(string $path, string $defaultDns, array $clients): PDO
{
    File::ensureDirectoryExists(dirname($path));

    $database = new PDO("sqlite:{$path}");
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('create table user_configs_table (default_dns text not null)');
    $database->exec(<<<'SQL'
        create table clients_table (
            name text not null,
            ipv4_address text not null,
            dns text not null,
            enabled integer not null
        )
        SQL);
    $statement = $database->prepare('insert into user_configs_table (default_dns) values (:default_dns)');
    $statement->execute(['default_dns' => $defaultDns]);

    $statement = $database->prepare(
        'insert into clients_table (name, ipv4_address, dns, enabled) values (:name, :ipv4_address, :dns, 1)',
    );

    foreach ($clients as $client) {
        $statement->execute($client);
    }

    return $database;
}

function readDnsRuntimeProbeWgEasyDefaultDns(string $path): string
{
    $database = new PDO("sqlite:{$path}");
    $value = $database->query('select default_dns from user_configs_table limit 1')->fetchColumn();

    expect($value)->toBeString();

    return $value;
}

function create_dns_runtime_probe_swarm_stack_marker(string $workdir): void
{
    File::ensureDirectoryExists($workdir.'/swarm');
    File::put($workdir.'/swarm/orbit-vpn-dns-stack.yml', "services:\n  orbit-vpn: {}\n  orbit-dns: {}\n");
}

function write_dns_runtime_probe_expected_config(string $workdir): void
{
    Node::factory()->create([
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    File::put($workdir.'/dnsmasq.conf', new DnsmasqConfigBuilder()->build(Node::query()->get()));
}

function fake_dns_runtime_probe_swarm_runtime_with_forwarding(): void
{
    fake_dns_runtime_probe_swarm_runtime(forwardingFailure: null);
}

function fake_dns_runtime_probe_swarm_runtime_without_forwarding(): void
{
    fake_dns_runtime_probe_swarm_runtime(forwardingFailure: "iptables: Bad rule\n");
}

function fake_dns_runtime_probe_swarm_runtime(?string $forwardingFailure): void
{
    Process::fake(function ($process) use ($forwardingFailure) {
        $command = (string) $process->command;

        if ($command === 'docker ps -a -q -f name=orbit-dns') {
            return Process::result('');
        }

        if (str_contains($command, 'label=com.docker.swarm.service.name=orbit_orbit-dns')) {
            return Process::result("swarm-dns-task\n");
        }

        if (str_contains($command, "docker exec 'swarm-dns-task'")) {
            return Process::result('udp 0 0 :::53 :::* LISTEN');
        }

        if (str_contains($command, 'label=com.docker.swarm.service.name=orbit_orbit-vpn')) {
            return Process::result("vpn-container-id\n");
        }

        if (str_contains($command, "docker exec 'vpn-container-id'")) {
            return $forwardingFailure === null
                ? Process::result()
                : Process::result(exitCode: 1, errorOutput: $forwardingFailure);
        }

        return Process::result(exitCode: 1, errorOutput: "Unexpected command: {$command}");
    });
}

/**
 * @return array<string, string>
 */
function readDnsRuntimeProbeWgEasyClientDns(string $path): array
{
    $database = new PDO("sqlite:{$path}");
    $rows = $database->query('select name, dns from clients_table order by name')->fetchAll(PDO::FETCH_KEY_PAIR);

    expect($rows)->toBeArray();

    return $rows;
}
