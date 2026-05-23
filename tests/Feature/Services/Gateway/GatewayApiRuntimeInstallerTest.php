<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Gateway\GatewayApiRuntimeInstaller;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\Runtime\OrbitRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function gatewayApiInstallerPathIsCaddyVisible(string $path, OrbitCaddyContainer $container): bool
{
    foreach ($container->mounts() as $mount) {
        $target = $mount['target'];

        if ($path === $target) {
            return true;
        }

        if (str_starts_with($path, rtrim($target, '/').'/')) {
            return true;
        }
    }

    return false;
}

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
            'user' => 'orbit',
            'orbit_path' => base_path(),
            'status' => 'active',
        ]);
    });

    afterEach(function (): void {
        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('issues a leaf certificate and routes the gateway API through orbit-caddy to orbit-runtime', function (): void {
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

        Process::fake(function ($process) use (&$writtenGlobalCaddyfile, &$writtenGatewayApiCaddyfile
        ) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
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
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/orbit/*.caddy')
            ->and($writtenGlobalCaddyfile)->toContain('import /etc/caddy/sites/*.caddy')
            ->and($writtenGatewayApiCaddyfile)->toContain('https://10.6.0.2:443')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('bind 10.6.0.2')
            ->and($writtenGatewayApiCaddyfile)->toContain('tls '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.crt '.$this->tempStorage.'/app/orbit/certs/10.6.0.2.key')
            ->and($writtenGatewayApiCaddyfile)->toContain('reverse_proxy http://orbit-runtime:8080')
            ->and($writtenGatewayApiCaddyfile)->toContain('flush_interval -1')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('php_fastcgi')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('php-fpm')
            ->and($writtenGatewayApiCaddyfile)->not->toContain('orbit-api.sock');
    });

    it('preserves real-time streaming through the containerized gateway api with flush_interval disabled', function (): void {
        $writtenGatewayApiCaddyfile = null;
        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGatewayApiCaddyfile) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        expect($writtenGatewayApiCaddyfile)
            ->toContain('flush_interval -1')
            ->and($writtenGatewayApiCaddyfile)->toContain('reverse_proxy http://orbit-runtime:8080');
    });

    it('ensures the orbit-runtime container before writing the gateway API Caddy config', function (): void {
        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        $builder = new DockerCommandBuilder;
        $renderer = new OrbitRuntimeContainerRenderer(new OrbitContainerNames);
        $runtimeContainer = $renderer->render(
            orbitCheckoutPath: '/home/orbit/orbit',
            gatewayDatabasePath: '/home/orbit/orbit/database/database.sqlite',
        );

        $invocations = [];

        Process::fake(function ($process) use ($builder, $runtimeContainer, &$invocations) {
            $invocations[] = $process->command;

            if ($process->command === $builder->networkInspect($runtimeContainer->network())) {
                return Process::result(exitCode: 1);
            }

            if ($process->command === $builder->containerInspect($runtimeContainer->name())) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        $runtimeCreateIndex = null;
        $caddyConfigWriteIndex = null;
        $caddyRestartIndex = null;

        foreach ($invocations as $i => $command) {
            if ($command === $builder->runDetached($runtimeContainer)) {
                $runtimeCreateIndex = $i;
            }
            if (str_contains($command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $caddyConfigWriteIndex = $i;
            }
            if ($command === "docker restart 'orbit-caddy'") {
                $caddyRestartIndex = $i;
            }
        }

        expect($runtimeCreateIndex)->not->toBeNull('orbit-runtime container must be created')
            ->and($caddyConfigWriteIndex)->not->toBeNull('gateway API Caddy config must be written')
            ->and($caddyRestartIndex)->not->toBeNull('orbit-caddy must be restarted')
            ->and($runtimeCreateIndex)->toBeLessThan($caddyConfigWriteIndex, 'orbit-runtime must be created before the Caddy config is written')
            ->and($caddyConfigWriteIndex)->toBeLessThan($caddyRestartIndex, 'Caddy config must be written before orbit-caddy is restarted');
    });

    it('reloads the orbit-caddy container and never installs or restarts host PHP-FPM or host Caddy', function (): void {
        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');

        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');

        Process::assertRan('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites');
        Process::assertRan('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null');
        Process::assertRan("docker restart 'orbit-caddy'");

        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'systemctl'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'php-fpm'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'php8.5-fpm'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'tee /etc/php/'));
        Process::assertNotRan(fn ($process): bool => is_string($process->command)
            && str_contains($process->command, 'usermod -aG orbit caddy'));
    });

    it('rewrites runtime-container storage_path cert paths to the host path that lives under an orbit-caddy bind mount', function (): void {
        $hostOrbit = '/home/orbit/orbit';
        $sourcePath = '/opt/orbit';
        $writtenGatewayApiCaddyfile = null;

        // Simulate running inside orbit-runtime: storage_path() points at
        // /opt/orbit/storage and ORBIT_HOST_PATH tells us where that lives
        // on the host bind source.
        $containerStorage = "{$sourcePath}/storage";
        $tempContainerRoot = sys_get_temp_dir().'/orbit-gateway-api-host-translate-'.uniqid();
        mkdir($tempContainerRoot, 0777, true);
        app()->useStoragePath($tempContainerRoot);

        // OrbitCaService reads storage_path() to issue the leaf, so prepare
        // CA material at the test storage path, then assert the *rendered*
        // Caddyfile points at the host-translated location.
        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');
        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

        Process::fake(function ($process) use (&$writtenGatewayApiCaddyfile) {
            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        // The translation rule is "/opt/orbit/X → $ORBIT_HOST_PATH/X". For
        // this test we point storage to a non-/opt path; the installer
        // should leave non-/opt paths untouched, which keeps existing
        // host-direct installs working. To exercise the live translation we
        // also assert the helper directly.
        putenv("ORBIT_HOST_PATH={$hostOrbit}");

        try {
            app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2', orbitPath: '/home/orbit/orbit');
        } finally {
            putenv('ORBIT_HOST_PATH');

            if (is_dir($tempContainerRoot)) {
                File::deleteDirectory($tempContainerRoot);
            }
        }

        // Storage path under temp dir isn't /opt/orbit, so paths are
        // returned unchanged here. The host-path translation logic is
        // covered explicitly below with a hand-built container path.
        expect($writtenGatewayApiCaddyfile)->toContain('tls '.$certsDir.'/10.6.0.2.crt');

        $reflection = new ReflectionClass(GatewayApiRuntimeInstaller::class);
        $method = $reflection->getMethod('caddyVisiblePath');
        $method->setAccessible(true);
        $instance = app(GatewayApiRuntimeInstaller::class);

        putenv("ORBIT_HOST_PATH={$hostOrbit}");

        try {
            $translatedCert = $method->invoke($instance, "{$containerStorage}/app/orbit/certs/10.6.0.2.crt");
            $translatedKey = $method->invoke($instance, "{$containerStorage}/app/orbit/certs/10.6.0.2.key");
            $translatedExact = $method->invoke($instance, $sourcePath);
            $passthrough = $method->invoke($instance, '/etc/orbit/certs/external.crt');
        } finally {
            putenv('ORBIT_HOST_PATH');
        }

        expect($translatedCert)->toBe("{$hostOrbit}/storage/app/orbit/certs/10.6.0.2.crt")
            ->and($translatedKey)->toBe("{$hostOrbit}/storage/app/orbit/certs/10.6.0.2.key")
            ->and($translatedExact)->toBe($hostOrbit)
            ->and($passthrough)->toBe('/etc/orbit/certs/external.crt');

        $caddyContainer = OrbitCaddyContainer::forPrivateNode('10.6.0.2');

        expect(gatewayApiInstallerPathIsCaddyVisible($translatedCert, $caddyContainer))->toBeTrue('translated cert path must fall under an orbit-caddy bind mount')
            ->and(gatewayApiInstallerPathIsCaddyVisible($translatedKey, $caddyContainer))->toBeTrue('translated key path must fall under an orbit-caddy bind mount')
            ->and(gatewayApiInstallerPathIsCaddyVisible($passthrough, $caddyContainer))->toBeTrue('paths already under /etc/orbit/* must remain Caddy-visible without translation');
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

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
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
