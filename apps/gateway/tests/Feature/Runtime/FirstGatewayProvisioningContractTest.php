<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Gateway\GatewayApiRuntimeInstaller;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

describe('first-gateway provisioning contract', function (): void {
    it('routes remote bootstrap and platform detection through the orbit launcher instead of host php artisan', function (): void {
        $installer = file_get_contents(repo_path('apps/gateway/app/Console/Commands/NodeNewCommand.php'));

        expect($installer)
            ->toContain("'orbit orbit:internal:bootstrap-gateway-local %s %s --identity-json=- --public-host=%s --tld=%s --metadata-json'")
            ->toContain("'orbit orbit:internal:detect-platform --update-local-node'")
            ->not->toContain('php artisan orbit:internal:bootstrap-gateway-local')
            ->not->toContain('php artisan orbit:internal:detect-platform')
            ->not->toContain("'cd %s && php artisan orbit:internal:bootstrap-gateway-local")
            ->not->toContain("'cd %s && php artisan orbit:internal:detect-platform");
    });

    it('budgets first-gateway identity bootstrap for runtime image loading and container convergence', function (): void {
        $nodeNew = file_get_contents(repo_path('apps/gateway/app/Console/Commands/NodeNewCommand.php'));

        expect($nodeNew)
            ->toContain('private const int FIRST_GATEWAY_BOOTSTRAP_TIMEOUT_SECONDS = 600')
            ->and(preg_match('/Process::timeout\(self::FIRST_GATEWAY_BOOTSTRAP_TIMEOUT_SECONDS\)\s*->input\(\$identityJson\)\s*->run/s', $nodeNew))->toBe(1);
    });

    it('starts orbit-runtime with ORBIT_IS_GATEWAY=1 when install-orbit is invoked with --gateway', function (): void {
        $script = file_get_contents(repo_path('bin/install-orbit'));

        expect($script)
            ->toContain('--gateway')
            ->toContain('GATEWAY_MODE')
            ->toContain('ORBIT_IS_GATEWAY=1')
            ->toContain('ORBIT_TRUST_WIREGUARD_PROXY_HEADER=1')
            ->toContain('ORBIT_HOST_PATH=$TARGET_DIR');
    });

    it('passes --gateway from OrbitHostInstaller to install-orbit when bootstrapping a first gateway', function (): void {
        $installer = file_get_contents(repo_path('apps/gateway/app/Services/OrbitHostInstaller.php'));
        $nodeNew = file_get_contents(repo_path('apps/gateway/app/Console/Commands/NodeNewCommand.php'));

        expect($installer)
            ->toContain('bool $asGateway = false')
            ->toContain("\$asGateway ? ' --gateway' : ''");

        expect($nodeNew)
            ->toContain('$installer->install($host, $sshUser, $runtimeUser, asGateway: true)');
    });
});

describe('GatewayApiRuntimeInstaller orbit-caddy convergence', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-gateway-caddy-converge-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);

        $caDir = storage_path('app/orbit/ca');
        $certsDir = storage_path('app/orbit/certs');
        File::ensureDirectoryExists($caDir);
        File::ensureDirectoryExists($certsDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf-cert\n-----END CERTIFICATE-----\n");
        File::put("{$certsDir}/10.6.0.2.key", 'test-leaf-key');

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

    it('converges the orbit-caddy container before writing the gateway API site and reloading it', function (): void {
        $shellScripts = [];

        Process::fake(function ($process) use (&$shellScripts) {
            if (is_string($process->command) && $process->command === 'bash -s') {
                $shellScripts[] = (string) $process->input;
            }

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2');

        expect($shellScripts)->not->toBeEmpty();

        $convergeScript = $shellScripts[0];

        expect($convergeScript)
            ->toContain('docker image inspect')
            ->toContain('caddy:2-alpine')
            ->toContain('docker run -d')
            ->toContain('--name')
            ->toContain('orbit-caddy')
            ->toContain('10.6.0.2:80:80')
            ->toContain('10.6.0.2:443:443')
            ->toContain('10.6.0.2:'.OrbitCaddyContainer::PrivateBackendPort.':'.OrbitCaddyContainer::PrivateBackendPort);

        Process::assertRan('sudo install -d -m 0755 /etc/caddy /etc/caddy/orbit /etc/caddy/sites');
        Process::assertRan('sudo tee /etc/caddy/orbit/orbit-api.caddy > /dev/null');
        Process::assertRan(CaddyTool::reloadCommand('orbit-caddy'));
    });

    it('uses the forPrivateNode container spec so port 443 binds only to the WireGuard address', function (): void {
        $shellScripts = [];

        Process::fake(function ($process) use (&$shellScripts) {
            if (is_string($process->command) && $process->command === 'bash -s') {
                $shellScripts[] = (string) $process->input;
            }

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2');

        $convergeScript = $shellScripts[0] ?? '';
        $expected = OrbitCaddyContainer::forPrivateNode('10.6.0.2')->publishedPorts();

        foreach ($expected as $publishedPort) {
            expect($convergeScript)->toContain($publishedPort);
        }

        expect($convergeScript)
            ->not->toContain("'80:80'")
            ->not->toContain("'443:443'");
    });

    it('renders the gateway API TLS cert/key under the caddy-readable /etc/orbit bind mount', function (): void {
        $writtenGatewayApiCaddyfile = null;

        $containerStorageRoot = sys_get_temp_dir().'/orbit-host-translate-storage-'.uniqid();
        mkdir($containerStorageRoot.'/app/orbit/ca', 0777, true);
        mkdir($containerStorageRoot.'/app/orbit/certs', 0777, true);
        app()->useStoragePath($containerStorageRoot);

        File::put("{$containerStorageRoot}/app/orbit/ca/root.key", 'test-root-key');
        File::put("{$containerStorageRoot}/app/orbit/ca/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");
        File::put("{$containerStorageRoot}/app/orbit/certs/10.6.0.2.crt", "-----BEGIN CERTIFICATE-----\ntest-leaf\n-----END CERTIFICATE-----\n");
        File::put("{$containerStorageRoot}/app/orbit/certs/10.6.0.2.key", 'test-key');

        Process::fake(function ($process) use (&$writtenGatewayApiCaddyfile) {
            if (str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy')) {
                $writtenGatewayApiCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        try {
            app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2');
        } finally {
            File::deleteDirectory($containerStorageRoot);
        }

        $caddyCertPath = '/etc/orbit/certs/10.6.0.2.crt';
        $caddyKeyPath = '/etc/orbit/certs/10.6.0.2.key';
        $caddyContainer = OrbitCaddyContainer::forPrivateNode('10.6.0.2');
        $caddyVisibleRoots = collect($caddyContainer->mounts())
            ->pluck('target')
            ->all();

        $reachable = function (string $path) use ($caddyVisibleRoots): bool {
            foreach ($caddyVisibleRoots as $target) {
                if ($path === $target || str_starts_with($path, rtrim($target, '/').'/')) {
                    return true;
                }
            }

            return false;
        };

        expect($reachable($caddyCertPath))->toBeTrue('gateway API cert must live under an orbit-caddy bind mount target')
            ->and($reachable($caddyKeyPath))->toBeTrue('gateway API key must live under an orbit-caddy bind mount target')
            ->and($reachable('/opt/orbit/storage/app/orbit/certs/10.6.0.2.crt'))->toBeFalse('runtime-private /opt/orbit paths must NOT be rendered into orbit-caddy config');

        expect($writtenGatewayApiCaddyfile)->not->toBeNull();
        preg_match('#^\s+tls\s+(\S+)\s+(\S+)\s*$#m', $writtenGatewayApiCaddyfile, $matches);
        expect($matches)->toHaveCount(3, 'rendered Caddyfile must include a tls cert and key directive');

        expect($matches[1])->toBe($caddyCertPath)
            ->and($matches[2])->toBe($caddyKeyPath)
            ->and($writtenGatewayApiCaddyfile)->not->toContain($containerStorageRoot);

        Process::assertRan('sudo install -d -m 0755 /etc/orbit/certs');
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$containerStorageRoot}/app/orbit/certs/10.6.0.2.crt").' '.escapeshellarg($caddyCertPath));
        Process::assertRan('sudo install -m 0644 '.escapeshellarg("{$containerStorageRoot}/app/orbit/certs/10.6.0.2.key").' '.escapeshellarg($caddyKeyPath));
    });

    it('writes the gateway API Caddyfile through the host bind mount that orbit-caddy reads', function (): void {
        $installer = file_get_contents(repo_path('bin/install-orbit'));

        expect($installer)
            ->toContain('prepare_orbit_caddy_host_paths')
            ->toContain('install -d -m 0755')
            ->toContain('/etc/caddy')
            ->toContain('/etc/caddy/orbit')
            ->toContain('/etc/caddy/sites')
            ->toContain('/etc/orbit')
            ->toContain('/etc/orbit/certs')
            ->toContain('--mount "type=bind,source=/etc/caddy,target=/etc/caddy"')
            ->toContain('--mount "type=bind,source=/etc/orbit,target=/etc/orbit"');

        $orbitCaddyMounts = collect(OrbitCaddyContainer::default()->mounts())
            ->pluck('target')
            ->all();

        // Anything the gateway installer writes (orbit-api.caddy under
        // /etc/caddy/orbit, leaf certs under <home>/orbit/storage/...)
        // must land somewhere orbit-caddy actually mounts.
        expect($orbitCaddyMounts)->toContain('/etc/caddy/orbit', '/etc/caddy/sites', '/etc/orbit', '/home');
    });

    it('ships sudo inside orbit-runtime so the gateway installer scripts can sudo install-d and sudo tee through bind-mounted host paths', function (): void {
        $dockerfile = file_get_contents(repo_path('docker/orbit-runtime/Dockerfile'));

        expect($dockerfile)
            ->toContain('sudo');
    });

    it('keeps the additive global Caddyfile preservation step from previous fixes', function (): void {
        // Ensure the prior behavior survives: a non-empty /etc/caddy/Caddyfile
        // gets its imports/snippets ensured rather than overwritten.
        $existingCaddyfile = <<<'CADDY'
{
    admin off
}

import /etc/caddy/sites/*.caddy
import /etc/caddy/orbit/orbit-web.caddy
CADDY;
        $writtenGlobalCaddyfile = null;

        Process::fake(function ($process) use ($existingCaddyfile, &$writtenGlobalCaddyfile) {
            if (str_contains($process->command, "sudo test -f '/etc/caddy/Caddyfile' && sudo cat '/etc/caddy/Caddyfile'")) {
                return Process::result($existingCaddyfile);
            }

            if (str_contains($process->command, 'tee /etc/caddy/Caddyfile')) {
                $writtenGlobalCaddyfile = (string) $process->input;
            }

            if (str_contains($process->command, 'docker container inspect')) {
                return Process::result(exitCode: 1);
            }

            if (str_contains($process->command, 'docker network inspect')) {
                return Process::result(exitCode: 1);
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2');

        expect($writtenGlobalCaddyfile)
            ->toContain('admin localhost:2019')
            ->toContain('local_certs')
            ->toContain('import /etc/caddy/sites/*.caddy')
            ->toContain('import /etc/caddy/orbit/orbit-web.caddy')
            ->toContain('import /etc/caddy/orbit/*.caddy')
            ->not->toContain('admin off');

        // Force-touch CaddyGlobalConfig so the imports list is the source of truth.
        expect((new CaddyGlobalConfig)->fresh())
            ->toContain('import /etc/caddy/orbit/*.caddy')
            ->toContain('import /etc/caddy/sites/*.caddy');
    });
});
