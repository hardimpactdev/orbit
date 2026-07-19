<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('writes a compose file with the base and projection directory mounted read-only', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    orbitDnsServiceInstaller($this->workdir)->install();

    $compose = File::get($this->workdir.'/docker-compose.yaml');

    expect($compose)
        ->toContain('network_mode: "container:wg-easy"')
        ->toContain('4km3/dnsmasq:latest')
        ->toContain('cap_add:')
        ->toContain('NET_ADMIN')
        ->toContain('restart: unless-stopped')
        ->toContain("{$this->workdir}/dnsmasq.conf:/etc/dnsmasq.conf:ro")
        ->toContain("{$this->workdir}/dnsmasq.d:/etc/dnsmasq.d:ro")
        ->not->toContain('10-node-records.conf:/etc/dnsmasq.d/')
        ->not->toContain('20-proxy-records.conf:/etc/dnsmasq.d/')
        ->not->toContain('networks:')
        ->not->toContain('ports:');
});

it('keeps orbit-dns coupled to the wg-easy container runtime', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    orbitDnsServiceInstaller($this->workdir)->install();

    $compose = File::get($this->workdir.'/docker-compose.yaml');

    expect($compose)
        ->toContain('container_name: orbit-dns')
        ->toContain('network_mode: "container:wg-easy"')
        ->not->toContain('53:53')
        ->not->toContain('host:');
});

it('stages all three owner artifacts before starting the container', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'app-1',
        'wireguard_address' => '10.6.0.3',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    orbitDnsServiceInstaller($this->workdir)->install();

    $base = $this->workdir.'/dnsmasq.conf';
    $nodeRecords = $this->workdir.'/dnsmasq.d/10-node-records.conf';
    $proxyRecords = $this->workdir.'/dnsmasq.d/20-proxy-records.conf';

    expect($base)
        ->toBeFile()
        ->and($nodeRecords)
        ->toBeFile()
        ->and($proxyRecords)
        ->toBeFile()
        ->and(File::get($base))
        ->toBe(new DnsmasqBaseConfigBuilder()->build())
        ->and(File::get($base))
        ->not->toContain('address=/')->and(File::get($nodeRecords))->toContain(
            'address=/orbit.gateway/10.6.0.2',
        )->and(File::get($nodeRecords))->toContain('address=/orbit.app-1/10.6.0.3')->and(File::get(
            $proxyRecords,
        ))->toContain('address=/orbit/10.6.0.2')->and(File::get($proxyRecords))
        ->not->toContain('address=/orbit.gateway/');
});

it('errors when wg-easy is not running', function (): void {
    Process::fake([
        'docker ps*' => Process::result(''),
    ]);

    expect(fn (): mixed => orbitDnsServiceInstaller($this->workdir)->install())
        ->toThrow(RuntimeException::class, 'wg-easy');
});

it('invokes docker compose up after writing files', function (): void {
    Process::fake([
        'docker ps*' => Process::result('wg-easy'),
        '*' => Process::result(),
    ]);

    orbitDnsServiceInstaller($this->workdir)->install();

    Process::assertRan(
        fn ($process): bool => (
            str_contains((string) $process->command, 'docker compose')
            && str_contains((string) $process->command, 'up -d')
        ),
    );
});

it('activates newly staged projections after compose deployment', function (): void {
    Process::fake([
        'docker ps -q -f name=wg-easy' => Process::result("wg-easy-id\n"),
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker container inspect orbit-dns' => Process::result('{}'),
        'docker restart orbit-dns' => Process::result(),
        '*' => Process::result(),
    ]);

    orbitDnsServiceInstaller($this->workdir)->install();

    Process::assertRan(fn ($process): bool => (string) $process->command === 'docker restart orbit-dns');
});

it('deploys the fragment mount with empty placeholders before replacing a legacy monolith', function (): void {
    $legacy = "conf-dir=/etc/dnsmasq.d\naddress=/orbit.gateway/10.6.0.2\n";
    File::put($this->workdir.'/dnsmasq.conf', $legacy);
    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    $stateAtDeploy = [];

    Process::fake(function ($process) use (&$stateAtDeploy) {
        $command = (string) $process->command;

        if ($command === 'docker ps -q -f name=wg-easy') {
            return Process::result("wg-easy-id\n");
        }

        if (str_contains($command, 'docker compose') && str_contains($command, 'up -d')) {
            $stateAtDeploy = [
                'base' => File::get($this->workdir.'/dnsmasq.conf'),
                'node' => File::get($this->workdir.'/dnsmasq.d/10-node-records.conf'),
                'proxy' => File::get($this->workdir.'/dnsmasq.d/20-proxy-records.conf'),
            ];

            return Process::result();
        }

        if ($command === "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns'") {
            return Process::result('');
        }

        if ($command === 'docker ps -a -q -f name=orbit-dns') {
            return Process::result("orbit-dns-id\n");
        }

        if (str_starts_with($command, 'docker inspect --format')) {
            return Process::result(json_encode([[
                'Type' => 'bind',
                'Source' => $this->workdir.'/dnsmasq.d',
                'Destination' => '/etc/dnsmasq.d',
                'RW' => false,
            ]], JSON_THROW_ON_ERROR));
        }

        if ($command === "docker service inspect 'orbit_orbit-dns'") {
            return Process::result(exitCode: 1);
        }

        if ($command === 'docker container inspect orbit-dns' || $command === 'docker restart orbit-dns') {
            return Process::result();
        }

        return Process::result(exitCode: 1, errorOutput: "Unexpected command: {$command}");
    });
    Process::preventStrayProcesses();

    orbitDnsServiceInstaller($this->workdir)->install();

    expect($stateAtDeploy)
        ->toBe([
            'base' => $legacy,
            'node' => "# orbit-managed=node-dns-records\n",
            'proxy' => "# orbit-managed=proxy-dns-records\n",
        ])
        ->and(File::get($this->workdir.'/dnsmasq.conf'))
        ->toBe(new DnsmasqBaseConfigBuilder()->build())
        ->and(File::get($this->workdir.'/dnsmasq.d/10-node-records.conf'))
        ->toContain('address=/orbit.gateway/10.6.0.2');
});

function orbitDnsServiceInstaller(string $rootPath): OrbitDnsServiceInstaller
{
    return new OrbitDnsServiceInstaller(
        reconciler: new DnsmasqReconciler(
            baseConfigBuilder: new DnsmasqBaseConfigBuilder,
            nodeRecordsBuilder: new NodeDnsmasqRecordsBuilder,
            proxyRecordsBuilder: new ProxyDnsmasqRecordsBuilder,
            rootPath: $rootPath,
        ),
        rootPath: $rootPath,
    );
}
