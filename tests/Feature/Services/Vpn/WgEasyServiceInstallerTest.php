<?php

declare(strict_types=1);

use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->workdir = sys_get_temp_dir().'/orbit-wg-easy-installer-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->workdir);
});

afterEach(function (): void {
    if (isset($this->workdir) && is_string($this->workdir) && is_dir($this->workdir)) {
        File::deleteDirectory($this->workdir);
    }
});

it('renders the wg-easy compose file with the gateway public host and required envs', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir);

    $installer->install(publicHost: '203.0.113.10', passwordHash: '$2y$10$abc');

    $composePath = $this->workdir.'/wg-easy/docker-compose.yaml';
    $compose = File::get($composePath);

    expect($compose)->toContain('WG_HOST=203.0.113.10')
        ->and($compose)->toContain('PASSWORD_HASH=')
        ->and($compose)->toContain('WG_DEFAULT_ADDRESS=10.6.0.x')
        ->and($compose)->toContain('WG_DEFAULT_DNS=10.6.0.1')
        ->and($compose)->toContain('WG_ALLOWED_IPS=10.6.0.0/24')
        ->and($compose)->toContain('WG_PERSISTENT_KEEPALIVE=25')
        ->and($compose)->toContain('51820:51820/udp')
        ->and($compose)->toContain('51821:51821/tcp')
        ->and($compose)->toContain('NET_ADMIN')
        ->and($compose)->toContain('SYS_MODULE');
});

it('invokes docker compose up to start the wg-easy container', function (): void {
    Process::fake();

    (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '203.0.113.10', passwordHash: '$2y$10$abc');

    Process::assertRan(fn ($process): bool => str_contains((string) $process->command, 'docker compose')
        && str_contains((string) $process->command, 'up -d'));
});

it('is idempotent: rerunning with same inputs does not recreate compose file unnecessarily', function (): void {
    Process::fake();

    $installer = new WgEasyServiceInstaller(rootPath: $this->workdir);

    $installer->install(publicHost: '203.0.113.10', passwordHash: '$2y$10$abc');
    $firstMtime = filemtime($this->workdir.'/wg-easy/docker-compose.yaml');

    clearstatcache();
    sleep(1);

    $installer->install(publicHost: '203.0.113.10', passwordHash: '$2y$10$abc');
    $secondMtime = filemtime($this->workdir.'/wg-easy/docker-compose.yaml');

    expect($secondMtime)->toBe($firstMtime);
});

it('rejects invalid public host', function (): void {
    expect(fn (): mixed => (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '', passwordHash: '$2y$10$abc'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty password hash', function (): void {
    expect(fn (): mixed => (new WgEasyServiceInstaller(rootPath: $this->workdir))
        ->install(publicHost: '203.0.113.10', passwordHash: ''))
        ->toThrow(RuntimeException::class);
});
