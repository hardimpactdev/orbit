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

it('renders the wg-easy compose file with the gateway public host and required envs', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath);

    $installer->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $compose = File::get($composePath);

    expect($compose)->toContain('INIT_ENABLED=true')
        ->and($compose)->toContain('INIT_USERNAME=orbit')
        ->and($compose)->toContain('INIT_PASSWORD=secret-password')
        ->and($compose)->toContain('INIT_HOST=203.0.113.10')
        ->and($compose)->toContain('INIT_PORT=51820')
        ->and($compose)->toContain('INIT_DNS=10.6.0.1')
        ->and($compose)->toContain('INIT_ALLOWED_IPS=10.6.0.0/24')
        ->and($compose)->toContain('INSECURE=true')
        ->and($compose)->toContain('DISABLE_IPV6=true')
        ->and($compose)->toContain('51820:51820/udp')
        ->and($compose)->toContain('127.0.0.1:51821:51821/tcp')
        ->and($compose)->toContain('NET_ADMIN')
        ->and($compose)->toContain('SYS_MODULE');
});

it('invokes docker compose up to start the wg-easy container', function (): void {
    Process::fake();

    (new WgEasyServiceInstaller(rootPath: $this->workdir, statePath: $this->statePath))
        ->install(publicHost: '203.0.113.10', username: 'orbit', password: 'secret-password');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker compose')
        && str_contains((string) $process->command, 'up -d'));
});

it('reads the wg-easy server public key from the running container', function (): void {
    Process::fake(function ($process) {
        if ($process->command === 'docker exec wg-easy wg show wg0 public-key') {
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
