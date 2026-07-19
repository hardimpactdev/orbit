<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-dns-reconciler-'.bin2hex(random_bytes(4));
    $this->recordsDir = $this->workdir.'/dnsmasq.d';
    $this->basePath = $this->workdir.'/dnsmasq.conf';
    $this->nodePath = $this->recordsDir.'/10-node-records.conf';
    $this->proxyPath = $this->recordsDir.'/20-proxy-records.conf';

    File::ensureDirectoryExists($this->recordsDir);
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('reconciles only the tool-owned base file', function (): void {
    fakeDnsmasqRestart();
    File::put($this->nodePath, 'node-owner-sentinel');
    File::put($this->proxyPath, 'proxy-owner-sentinel');

    $changed = dnsmasqReconciler($this->workdir)->reconcileBase();

    expect($changed)
        ->toBeTrue()
        ->and(File::get($this->basePath))
        ->toBe(new DnsmasqBaseConfigBuilder()->build())
        ->and(File::get($this->nodePath))
        ->toBe('node-owner-sentinel')
        ->and(File::get($this->proxyPath))
        ->toBe('proxy-owner-sentinel');

    assertDnsmasqRestartedOnce();
});

it('reconciles only the node-owned records file', function (): void {
    fakeDnsmasqRestart();
    File::put($this->basePath, 'tool-owner-sentinel');
    File::put($this->proxyPath, 'proxy-owner-sentinel');

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    $changed = dnsmasqReconciler($this->workdir)->reconcileNodeRecords();

    expect($changed)
        ->toBeTrue()
        ->and(File::get($this->nodePath))
        ->toBe("# orbit-managed=node-dns-records\naddress=/orbit.gateway/10.6.0.2\n")
        ->and(File::get($this->basePath))
        ->toBe('tool-owner-sentinel')
        ->and(File::get($this->proxyPath))
        ->toBe('proxy-owner-sentinel');

    assertDnsmasqRestartedOnce();
});

it('reconciles only the proxy-owned records file', function (): void {
    fakeDnsmasqRestart();
    File::put($this->basePath, 'tool-owner-sentinel');
    File::put($this->nodePath, 'node-owner-sentinel');

    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $changed = dnsmasqReconciler($this->workdir)->reconcileProxyRecords();

    expect($changed)
        ->toBeTrue()
        ->and(File::get($this->proxyPath))
        ->toBe("# orbit-managed=proxy-dns-records\naddress=/orbit/10.6.0.2\nlocal=/orbit/\n")
        ->and(File::get($this->basePath))
        ->toBe('tool-owner-sentinel')
        ->and(File::get($this->nodePath))
        ->toBe('node-owner-sentinel');

    assertDnsmasqRestartedOnce();
});

it('reconciles both record owners without mutating the tool-owned base', function (): void {
    fakeDnsmasqRestart();
    File::put($this->basePath, 'tool-owner-sentinel');

    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $changed = dnsmasqReconciler($this->workdir)->reconcileRecords();

    expect($changed)
        ->toBeTrue()
        ->and(File::get($this->basePath))
        ->toBe('tool-owner-sentinel')
        ->and(File::get($this->nodePath))
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->and(File::get($this->proxyPath))
        ->toContain('address=/orbit/10.6.0.2');

    assertDnsmasqRestartedOnce();
});

it('materializes all three artifacts for an explicit layout migration and restarts dnsmasq only once', function (): void {
    fakeMountedDnsmasqRestart($this->workdir);

    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    $changed = dnsmasqReconciler($this->workdir)->migrateLegacyLayout();

    expect($changed)
        ->toBeTrue()
        ->and($this->basePath)
        ->toBeFile()
        ->and($this->nodePath)
        ->toBeFile()
        ->and($this->proxyPath)
        ->toBeFile()
        ->and(File::get($this->basePath))
        ->not
        ->toContain('address=/')
        ->and(File::get($this->nodePath))
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->and(File::get($this->proxyPath))
        ->toContain('address=/orbit/10.6.0.2');

    assertDnsmasqRestartedOnce();
});

it('does not restart when every owner artifact already matches', function (): void {
    $router = Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $router->id,
        'domain' => 'metrics.orbit',
        'owner_type' => 'router',
        'kind' => 'proxy',
    ]);

    File::put($this->basePath, new DnsmasqBaseConfigBuilder()->build());
    File::put($this->nodePath, new NodeDnsmasqRecordsBuilder()->buildGatewayState());
    File::put($this->proxyPath, new ProxyDnsmasqRecordsBuilder()->buildGatewayState());
    fakeMountedDnsmasqRestart($this->workdir);

    $changed = dnsmasqReconciler($this->workdir)->migrateLegacyLayout();

    expect($changed)->toBeFalse();
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'restart'));
});

it('does not rewrite the compose topology while reconciling a selected owner', function (): void {
    fakeDnsmasqRestart();
    File::put($this->workdir.'/docker-compose.yaml', <<<'YAML'
        services:
          orbit-dns:
            network_mode: "container:wg-easy"

        YAML);

    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);

    dnsmasqReconciler($this->workdir)->reconcileNodeRecords();

    expect(File::get($this->workdir.'/docker-compose.yaml'))->toBe(<<<'YAML'
        services:
          orbit-dns:
            network_mode: "container:wg-easy"

        YAML);
});

it('refuses owner-scoped reconciliation of a legacy monolith before the records directory is mounted', function (): void {
    $legacy = "address=/orbit.gateway/10.6.0.2\nno-resolv\n";
    File::put($this->basePath, $legacy);
    File::put($this->workdir.'/docker-compose.yaml', <<<'YAML'
        services:
          orbit-dns:
            volumes:
              - /tmp/dnsmasq.conf:/etc/dnsmasq.conf:ro

        YAML);
    Process::fake();

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileBase())
        ->toThrow(RuntimeException::class, 'legacy monolithic dnsmasq.conf');

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileNodeRecords())
        ->toThrow(RuntimeException::class, 'legacy monolithic dnsmasq.conf');

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileProxyRecords())
        ->toThrow(RuntimeException::class, 'legacy monolithic dnsmasq.conf');

    expect(File::get($this->basePath))->toBe($legacy);
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'restart'));
});

it('refuses owner-scoped reconciliation of a legacy monolith after the records directory is mounted', function (): void {
    $legacy = "address=/orbit.gateway/10.6.0.2\nno-resolv\n";
    File::put($this->basePath, $legacy);
    fakeMountedDnsmasqRestart($this->workdir);

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileBase())
        ->toThrow(RuntimeException::class, 'owner-scoped reconciliation');

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileNodeRecords())
        ->toThrow(RuntimeException::class, 'owner-scoped reconciliation');

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileProxyRecords())
        ->toThrow(RuntimeException::class, 'owner-scoped reconciliation');

    expect(File::get($this->basePath))->toBe($legacy);
    Process::assertNotRan(fn ($process): bool => str_contains((string) $process->command, 'restart'));
});

it('fails reconciliation when an existing standalone DNS container cannot restart', function (): void {
    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker container inspect orbit-dns' => Process::result(output: "{}\n"),
        'docker restart orbit-dns' => Process::result(errorOutput: 'restart failed', exitCode: 1),
    ]);

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileNodeRecords())
        ->toThrow(RuntimeException::class, 'Failed to restart orbit-dns: restart failed');

    expect($this->nodePath)->not->toBeFile();
});

it('rolls published bytes back after activation failure so a retry activates them', function (): void {
    Node::factory()->create([
        'name' => 'gateway',
        'tld' => 'gateway',
        'wireguard_address' => '10.6.0.2',
    ]);
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker container inspect orbit-dns' => Process::result(output: "{}\n"),
        'docker restart orbit-dns' => Process::result(errorOutput: 'restart failed', exitCode: 1),
    ]);

    expect(fn (): bool => dnsmasqReconciler($this->workdir)->reconcileNodeRecords())
        ->toThrow(RuntimeException::class, 'Failed to restart orbit-dns');
    expect($this->nodePath)->not->toBeFile();

    fakeDnsmasqRestart();

    expect(dnsmasqReconciler($this->workdir)->reconcileNodeRecords())
        ->toBeTrue()
        ->and($this->nodePath)
        ->toBeFile();
    Process::assertRanTimes(
        fn ($process): bool => str_contains((string) $process->command, 'docker restart orbit-dns'),
        2,
    );
});

it('captures gateway intent only after acquiring the shared projection lock', function (): void {
    $reconciler = new class($this->workdir) extends DnsmasqReconciler {
        public bool $observedProjectionLock = false;

        public function __construct(
            private readonly string $testRoot,
        ) {
            parent::__construct(rootPath: $testRoot);
        }

        protected function gatewayRecordProjections(): array
        {
            $lock = fopen($this->testRoot.'/.dnsmasq-projections.lock', 'c+');

            if ($lock === false) {
                throw new RuntimeException('Could not inspect the DNS projection lock.');
            }

            $acquired = flock($lock, LOCK_EX | LOCK_NB);
            $this->observedProjectionLock = ! $acquired;

            if ($acquired) {
                flock($lock, LOCK_UN);
            }

            fclose($lock);

            return [self::NodeRecords => "# orbit-managed=node-dns-records\n"];
        }
    };

    expect($reconciler->stageAllForInstall())
        ->toBeTrue()
        ->and($reconciler->observedProjectionLock)
        ->toBeTrue();
});

function dnsmasqReconciler(string $rootPath): DnsmasqReconciler
{
    return new DnsmasqReconciler(
        baseConfigBuilder: new DnsmasqBaseConfigBuilder,
        nodeRecordsBuilder: new NodeDnsmasqRecordsBuilder,
        proxyRecordsBuilder: new ProxyDnsmasqRecordsBuilder,
        rootPath: $rootPath,
    );
}

function fakeDnsmasqRestart(): void
{
    Process::fake([
        "docker service inspect 'orbit_orbit-dns'" => Process::result(exitCode: 1),
        'docker container inspect orbit-dns' => Process::result(output: "{}\n"),
        'docker restart orbit-dns' => Process::result(),
    ]);
}

function fakeMountedDnsmasqRestart(string $rootPath): void
{
    Process::fake(function ($process) use ($rootPath) {
        $command = (string) $process->command;

        if ($command === "docker ps -q --filter 'label=com.docker.swarm.service.name=orbit_orbit-dns'") {
            return Process::result("orbit-dns-id\n");
        }

        if (str_starts_with($command, 'docker inspect --format')) {
            return Process::result(json_encode([[
                'Type' => 'bind',
                'Source' => $rootPath.'/dnsmasq.d',
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

        return Process::result(exitCode: 1);
    });
}

function assertDnsmasqRestartedOnce(): void
{
    Process::assertRanTimes(
        fn ($process): bool => str_contains((string) $process->command, 'docker restart orbit-dns'),
        1,
    );
}
