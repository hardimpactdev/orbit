<?php

declare(strict_types=1);

use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-wg-easy-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
    $this->statePath = $this->workdir.'/.wg-easy';
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('renders the wg-easy compose file with the configured runtime envs', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath);

    $installer->install(
        publicHost: '203.0.113.10',
        username: 'orbit',
        password: 'secret-password',
        wireguardCidr: '10.7.0.0/24',
        wireguardPort: 51830,
        dnsIp: '10.7.0.1',
    );

    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $compose = File::get($composePath);

    expect($compose)->toContain('INIT_ENABLED=true')
        ->and($compose)->toContain('INIT_USERNAME=orbit')
        ->and($compose)->toContain('INIT_PASSWORD=secret-password')
        ->and($compose)->toContain('INIT_HOST=203.0.113.10')
        ->and($compose)->toContain('INIT_PORT=51830')
        ->and($compose)->toContain('INIT_DNS=10.7.0.1')
        ->and($compose)->toContain('INIT_ALLOWED_IPS=10.7.0.0/24')
        ->and($compose)->toContain('INSECURE=true')
        ->and($compose)->toContain('DISABLE_IPV6=true')
        ->and($compose)->toContain('51830:51830/udp')
        ->and($compose)->toContain('127.0.0.1:51821:51821/tcp')
        ->and($compose)->toContain('NET_ADMIN')
        ->and($compose)->toContain('SYS_MODULE');
});

it('defaults the wg-easy database path to the managed orbit home', function (): void {
    expect(config('services.wg_easy.database_path'))->toBe('/home/orbit/.wg-easy/wg-easy.db');
});

it('resolves the configured database state path when resolved from the container', function (): void {
    $previousServerHome = $_SERVER['HOME'] ?? null;
    $_SERVER['HOME'] = '/var/www';

    config()->set('services.wg_easy.database_path', '/home/orbit/.wg-easy/wg-easy.db');
    app()->forgetInstance(WgEasyServiceInstaller::class);

    $peerScript = null;

    Process::fake(function ($process) use (&$peerScript) {
        $command = (string) $process->command;

        if (str_contains($command, 'clients_table')) {
            $peerScript = $command;
        }

        return Process::result();
    });

    try {
        app(WgEasyServiceInstaller::class)->configurePeers([
            [
                'name' => 'app-dev-1',
                'private_key' => 'app-dev-private',
                'public_key' => 'app-dev-public',
                'pre_shared_key' => 'app-dev-psk',
                'address' => '10.6.0.4',
            ],
        ]);
    } finally {
        if ($previousServerHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $previousServerHome;
        }

        app()->forgetInstance(WgEasyServiceInstaller::class);
    }

    expect($peerScript)->toContain("sqlite3 '/home/orbit/.wg-easy'/wg-easy.db")
        ->and($peerScript)->not->toContain('/var/www/.wg-easy');
});

it('uses the default runtime values when install inputs are omitted', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath);

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    $compose = File::get($this->workdir.'/wg-easy/docker-compose.yaml');

    expect($compose)->toContain('INIT_PORT=51820')
        ->and($compose)->toContain('INIT_DNS=10.6.0.1')
        ->and($compose)->toContain('INIT_ALLOWED_IPS=10.6.0.0/24')
        ->and($compose)->toContain('51820:51820/udp');
});

it('invokes docker compose up to start the wg-easy container', function (): void {
    Process::fake();

    (new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath))
        ->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, '$ORBIT_DOCKER compose')
        && str_contains((string) $process->command, 'up -d'));
});

it('reads the wg-easy server public key from the running container', function (): void {
    Process::fake(function ($process) {
        if (str_contains((string) $process->command, 'wg show wg0 public-key')) {
            return Process::result(output: "wg-easy-public-key\n");
        }

        return Process::result();
    });

    $publicKey = (new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath))->publicKey();

    expect($publicKey)->toBe('wg-easy-public-key');
});

it('persists and activates node peers on wg-easy wg0', function (): void {
    $peerScript = null;

    Process::fake(function ($process) use (&$peerScript) {
        if (str_contains((string) $process->command, 'clients_table')) {
            $peerScript = (string) $process->command;
        }

        return Process::result();
    });

    (new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath))->configurePeers([
        [
            'name' => 'gateway-1',
            'private_key' => 'gateway-private',
            'public_key' => 'gateway-public',
            'pre_shared_key' => 'gateway-psk',
            'address' => '10.6.0.2',
        ],
        [
            'name' => 'control-1',
            'private_key' => 'control-private',
            'public_key' => 'control-public',
            'pre_shared_key' => 'control-psk',
            'address' => '10.6.0.3',
        ],
    ]);

    expect($peerScript)->toContain('wg-easy.db')
        ->and($peerScript)->toContain('ORBIT_DOCKER="sudo docker"')
        ->and($peerScript)->toContain('clients_table')
        ->and($peerScript)->toContain('gateway-public')
        ->and($peerScript)->toContain('gateway-psk')
        ->and($peerScript)->toContain('10.6.0.2/32')
        ->and($peerScript)->toContain('control-public')
        ->and($peerScript)->toContain('control-psk')
        ->and($peerScript)->toContain('10.6.0.3/32')
        ->and($peerScript)->toContain('wg set wg0 peer')
        ->and($peerScript)->toContain('preshared-key');
});

it('converges the runtime server address using the configured cidr dns ip and port', function (): void {
    $serverAddressScript = null;

    Process::fake(function ($process) use (&$serverAddressScript) {
        if (str_contains((string) $process->command, 'UPDATE interfaces_table')) {
            $serverAddressScript = (string) $process->command;
        }

        return Process::result();
    });

    (new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath))->install(
        publicHost: 'vpn.example.com',
        username: 'orbit',
        password: 'secret-password',
        wireguardCidr: '10.7.0.0/24',
        wireguardPort: 51830,
        dnsIp: '10.7.0.1',
    );

    expect($serverAddressScript)->toContain("ip addr replace '10.7.0.1/24' dev wg0")
        ->and($serverAddressScript)->toContain("ip route replace '10.7.0.0/24' dev wg0")
        ->and($serverAddressScript)->toContain("ipv4_cidr = '10.7.0.0/24'")
        ->and($serverAddressScript)->toContain('default_dns = \'["10.7.0.1"]\'')
        ->and($serverAddressScript)->toContain("host = 'vpn.example.com'");
});

it('is idempotent: rerunning with same inputs does not recreate compose file unnecessarily', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath);

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');
    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $firstMtime = filemtime($composePath);

    // Backdate the file so a second write would land on a different mtime
    // (cheaper than `sleep(1)` while still detecting unnecessary rewrites).
    touch($composePath, $firstMtime - 60);
    $expectedMtime = filemtime($composePath);
    clearstatcache();

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');
    $secondMtime = filemtime($composePath);

    expect($secondMtime)->toBe($expectedMtime);
});

it('rejects invalid public host', function (): void {
    expect(fn (): mixed => (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '', username: 'orbit', password: 'secret-password'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty username', function (): void {
    expect(fn (): mixed => (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '203.0.113.10', username: '', password: 'secret-password'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty password', function (): void {
    expect(fn (): mixed => (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '203.0.113.10', username: 'orbit', password: ''))
        ->toThrow(RuntimeException::class);
});
