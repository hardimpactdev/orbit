<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Dns\DnsmasqReconciler;
use App\Services\Dns\NodeDnsmasqRecordsBuilder;
use App\Services\Dns\ProxyDnsmasqRecordsBuilder;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Vpn\VpnDnsSwarmInstaller;
use App\Services\Vpn\VpnDnsSwarmManager;
use App\Services\Vpn\VpnDnsSwarmStackRenderer;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', '');

    $this->root = sys_get_temp_dir().'/orbit-vpn-dns-swarm-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->root);
});

afterEach(function (): void {
    if (isset($this->root) && is_string($this->root) && is_dir($this->root)) {
        File::deleteDirectory($this->root);
    }
});

it('deploys the colocated vpn and dns Swarm services and converges forwarding', function (): void {
    $commands = [];

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

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        if ($process->command === "docker info --format '{{.Swarm.LocalNodeState}}'") {
            return Process::result(output: "active\n");
        }

        if ($process->command === "docker info --format '{{.Swarm.NodeID}}'") {
            return Process::result(output: "node-123\n");
        }

        if (str_contains((string) $process->command, 'docker network inspect')) {
            return Process::result(output: "overlay swarm true\n");
        }

        if (str_contains((string) $process->command, 'docker ps -q --filter')) {
            return Process::result(output: "vpn-container-id\n");
        }

        if (str_contains((string) $process->command, 'public-key')) {
            return Process::result(output: "server-public-key\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $installer = vpnDnsSwarmInstaller($this->root);

    $installer->install(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
    );

    expect("{$this->root}/dnsmasq.conf")
        ->toBeFile()
        ->and(File::get("{$this->root}/dnsmasq.conf"))
        ->toBe(new DnsmasqBaseConfigBuilder()->build())
        ->and(File::get("{$this->root}/dnsmasq.conf"))
        ->not
        ->toContain('address=/')
        ->and("{$this->root}/dnsmasq.d/10-node-records.conf")
        ->toBeFile()
        ->and(File::get("{$this->root}/dnsmasq.d/10-node-records.conf"))
        ->toContain('address=/orbit.gateway/10.6.0.2')
        ->and("{$this->root}/dnsmasq.d/20-proxy-records.conf")
        ->toBeFile()
        ->and(File::get("{$this->root}/dnsmasq.d/20-proxy-records.conf"))
        ->toContain('address=/orbit/10.6.0.2')
        ->and("{$this->root}/swarm/orbit-vpn-dns-stack.yml")
        ->toBeFile()
        ->and(File::get("{$this->root}/swarm/orbit-vpn-dns-stack.yml"))
        ->toContain('orbit-vpn:')
        ->and(File::get("{$this->root}/swarm/orbit-vpn-dns-stack.yml"))
        ->toContain('orbit-dns:')
        ->and($installer->publicKey())
        ->toBe('server-public-key');

    $installer->configurePeers([
        [
            'name' => 'operator',
            'private_key' => 'operator-private',
            'public_key' => 'operator-public',
            'pre_shared_key' => 'operator-psk',
            'address' => '10.6.0.3',
        ],
    ]);

    expect($commands)
        ->toContain(
            "docker node update --label-add 'orbit.role.gateway=true' --label-add 'orbit.role.vpn=true' 'node-123'",
        )
        ->and($commands)
        ->toContain("docker stack deploy -c '{$this->root}/swarm/orbit-vpn-dns-stack.yml' 'orbit'")
        ->and($commands)
        ->toContain("docker service update --force 'orbit_orbit-dns'")
        ->and($commands)
        ->toContain("set -e\nchmod 0777 '{$this->root}/wg-easy'\nchmod 0666 '{$this->root}/wg-easy/wg-easy.db'")
        ->and($commands)
        ->toContain("docker exec 'vpn-container-id' wg show wg0 public-key")
        ->and(implode("\n", $commands))
        ->toContain("docker exec 'vpn-container-id' sh -lc")
        ->and(implode("\n", $commands))
        ->toContain('PREROUTING')
        ->and(implode("\n", $commands))
        ->toContain('wg set wg0 peer');
});

it('uses the Swarm wg-easy state path for inherited state commands', function (): void {
    $installer = new class($this->root) extends VpnDnsSwarmInstaller {
        public function __construct(string $root)
        {
            parent::__construct(rootPath: $root);
        }

        public function exposedStatePath(): string
        {
            return $this->statePath();
        }
    };

    expect($installer->exposedStatePath())->toBe($this->root.'/wg-easy');
});

it('removes a live peer from the current Swarm VPN task container', function (): void {
    $commands = [];
    Process::fake(static function ($process) use (&$commands) {
        $command = (string) $process->command;
        $commands[] = $command;

        if (str_starts_with($command, 'docker ps -q --filter')) {
            return Process::result(output: "current-vpn-task\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();
    $installer = new class($this->root) extends VpnDnsSwarmInstaller {
        public function __construct(string $root)
        {
            parent::__construct(rootPath: $root);
        }

        public function removeLivePeer(string $publicKey): void
        {
            $this->removeRuntimePeer($publicKey);
        }
    };

    $installer->removeLivePeer('swarm-public-key');

    expect($commands)
        ->toContain("docker exec 'current-vpn-task' wg set wg0 peer 'swarm-public-key' remove");
});

it('propagates a failed live peer removal from the Swarm VPN task', function (): void {
    Process::fake(static function ($process) {
        $command = (string) $process->command;

        if (str_starts_with($command, 'docker ps -q --filter')) {
            return Process::result(output: "current-vpn-task\n");
        }

        return Process::result(exitCode: 1, errorOutput: 'private runtime detail');
    });
    Process::preventStrayProcesses();
    $installer = new class($this->root) extends VpnDnsSwarmInstaller {
        public function __construct(string $root)
        {
            parent::__construct(rootPath: $root);
        }

        public function removeLivePeer(string $publicKey): void
        {
            $this->removeRuntimePeer($publicKey);
        }
    };

    expect(fn (): mixed => $installer->removeLivePeer('swarm-public-key'))
        ->toThrow(RuntimeException::class, 'Failed to remove the live WireGuard peer.');
});

it('marks installer and renderer password parameters as sensitive', function (): void {
    foreach ([
        [WgEasyServiceInstaller::class,   'install'],
        [VpnDnsSwarmInstaller::class,     'install'],
        [VpnDnsSwarmStackRenderer::class, 'render'],
    ] as [$class, $method]) {
        $password = new ReflectionMethod($class, $method)->getParameters()[2];

        expect($password->getName())
            ->toBe('password')
            ->and($password->getAttributes(SensitiveParameter::class))
            ->toHaveCount(1);
    }
});

it('reports safe Swarm task diagnostics when vpn and dns do not become ready', function (): void {
    Process::fake(function ($process) {
        $command = (string) $process->command;

        if ($command === "docker info --format '{{.Swarm.LocalNodeState}}'") {
            return Process::result(output: "active\n");
        }

        if ($command === "docker info --format '{{.Swarm.NodeID}}'") {
            return Process::result(output: "node-123\n");
        }

        if (str_contains($command, 'docker network inspect')) {
            return Process::result(output: "overlay swarm true\n");
        }

        if (str_contains($command, 'for i in $(seq 1 60)')) {
            return Process::result(exitCode: 1);
        }

        if (str_contains($command, 'docker service ps --no-trunc --format')) {
            return Process::result(output: "orbit_orbit-vpn.1\tRejected\tport is already allocated\n");
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    $password = bin2hex(random_bytes(16));

    try {
        vpnDnsSwarmInstaller($this->root)
            ->install(
                publicHost: '203.0.113.10',
                username: 'orbit',
                password: $password,
            );

        test()->fail('Expected the Swarm readiness probe to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('orbit_orbit-vpn.1')
            ->toContain('port is already allocated')
            ->not->toContain($password)->and(json_encode($exception->getTrace(), JSON_THROW_ON_ERROR))
            ->not->toContain($password);
    }
});

function vpnDnsSwarmInstaller(string $root): VpnDnsSwarmInstaller
{
    $renderer = new VpnDnsSwarmStackRenderer;

    return new VpnDnsSwarmInstaller(
        rootPath: $root,
        statePath: $root.'/wg-easy',
        swarm: new GatewaySwarmManager(configRoot: $root),
        renderer: $renderer,
        manager: new VpnDnsSwarmManager($renderer),
        reconciler: new DnsmasqReconciler(
            baseConfigBuilder: new DnsmasqBaseConfigBuilder,
            nodeRecordsBuilder: new NodeDnsmasqRecordsBuilder,
            proxyRecordsBuilder: new ProxyDnsmasqRecordsBuilder,
            rootPath: $root,
        ),
    );
}
