<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Gateway\GatewayApiRuntimeInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('GatewayApiRuntimeInstaller', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-gateway-api-runtime-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);

        Node::query()->create([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'ssh_user' => 'orbit',
            'user' => 'orbit',
            'orbit_path' => base_path(),
            'status' => 'active',
            'is_local' => true,
        ]);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('issues a leaf certificate and installs the caddy-backed gateway API runtime', function (): void {
        $writtenGlobalCaddyfile = null;
        $writtenGatewayApiCaddyfile = null;
        $writtenFpmPool = null;
        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile, &$writtenFpmPool) {
            if (str_contains($process->command, 'tee /etc/php/8.5/fpm/pool.d/orbit-api.conf')) {
                $writtenFpmPool = (string) $process->input;
            }

            if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
                $writtenGlobalCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGlobalCaddyfile)->toContain('(security_headers)')
            ->and($writtenGlobalCaddyfile)->toContain('(profiling_headers)')
            ->and($writtenGlobalCaddyfile)->toContain('(path_blocking_public_root)')
            ->and($writtenGlobalCaddyfile)->toContain('(path_blocking_project_root)')
            ->and($writtenGlobalCaddyfile)->toContain('(security_txt)')
            ->and($writtenGlobalCaddyfile)->toContain('(cache_headers)')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
            ->and($writtenGatewayApiCaddyfile)->toContain('https://10.6.0.2:443')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('bind 10.6.0.2')
            ->and($writtenGatewayApiCaddyfile)->toContain('tls '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.crt '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.key')
            ->and($writtenGatewayApiCaddyfile)->toContain('root * /home/orbit/orbit/public')
            ->and($writtenGatewayApiCaddyfile)->toContain('php_fastcgi unix//run/php/orbit-api.sock')
            ->and($writtenFpmPool)->toContain('[orbit-api]')
            ->and($writtenFpmPool)->toContain('user = orbit')
            ->and($writtenFpmPool)->toContain('listen.group = caddy')
            ->and($writtenFpmPool)->toContain('chdir = /home/orbit/orbit');

        Process::assertRan(fn ($process): bool => str_contains($process->command, 'openssl x509 -checkend'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo usermod -aG orbit caddy'));
        Process::assertRan('sudo tee /etc/php/8.5/fpm/pool.d/orbit-api.conf > /dev/null');
        Process::assertRan('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites');
        Process::assertRan('sudo tee /etc/caddy/Caddyfile > /dev/null');
        Process::assertRan('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null');
        Process::assertRan('sudo systemctl restart php8.5-fpm');
        Process::assertRan('sudo systemctl restart caddy');
        Process::assertRan('sudo systemctl enable caddy');
    });

    it('preserves an existing global Caddyfile and only ensures managed imports and snippets', function (): void {
        $readExistingCaddyfileCommand = "sudo test -f '/etc/caddy/Caddyfile' && sudo cat '/etc/caddy/Caddyfile' || true";
        $writtenGlobalCaddyfile = null;
        $writtenGatewayApiCaddyfile = null;

        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use ($readExistingCaddyfileCommand, &$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile) {
            if ($process->command === $readExistingCaddyfileCommand) {
                return Process::result(<<<'CADDY'
{
    admin off
}

import /etc/caddy/sites/*.caddy
import /etc/caddy/orbit/orbit-web.caddy
import /etc/caddy/orbit/tld-proxies.caddy
CADDY);
            }

            if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
                $writtenGlobalCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGlobalCaddyfile)->toContain('admin off')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/orbit-web.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/tld-proxies.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
            ->and(substr_count($writtenGlobalCaddyfile, 'import /etc/caddy/sites/*.caddy'))->toBe(1)
            ->and(substr_count($writtenGlobalCaddyfile, 'import /etc/caddy/orbit/*.caddy'))->toBe(1)
            ->and(substr_count($writtenGlobalCaddyfile, '{'))->toBe(substr_count($writtenGlobalCaddyfile, '}'))
            ->and(substr_count($writtenGlobalCaddyfile, 'admin off'))->toBe(1)
            ->and($writtenGatewayApiCaddyfile)->toContain('https://10.6.0.2:443');
    });
});
