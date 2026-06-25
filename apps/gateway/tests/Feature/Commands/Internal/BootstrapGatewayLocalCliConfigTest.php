<?php

declare(strict_types=1);

use App\Services\Ca\OrbitCaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tempStorage = sys_get_temp_dir().'/orbit-cli-config-test-'.uniqid();
    mkdir($this->tempStorage.'/app/orbit', permissions: 0o777, recursive: true);
    app()->useStoragePath($this->tempStorage);
    $this->originalEnvironmentPath = app()->environmentPath();
    app()->useEnvironmentPath($this->tempStorage);
    File::put("{$this->tempStorage}/.env", "APP_NAME=Orbit\n");

    app()->instance(OrbitCaService::class, new readonly class extends OrbitCaService {
        public function ensureRootCa(): void
        {
            File::ensureDirectoryExists(storage_path('app/orbit/ca'));
            File::put(storage_path('app/orbit/ca/root.key'), 'test-root-key');
            File::put(storage_path('app/orbit/ca/root.crt'), $this->rootCert());
        }

        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    });
});

afterEach(function (): void {
    $originalEnvironmentPath = $this->originalEnvironmentPath ?? null;

    if (is_string($originalEnvironmentPath)) {
        app()->useEnvironmentPath($originalEnvironmentPath);
    }

    $tempStorage = $this->tempStorage ?? null;

    if (is_string($tempStorage) && is_dir($tempStorage)) {
        File::deleteDirectory($tempStorage);
    }
});

it('seeds gateway-local CLI config for the orbit user with WireGuard HTTPS gateway settings', function (): void {
    $cliConfigRoot = "{$this->tempStorage}/.config/orbit";
    config()->set('orbit.paths.config_root', $cliConfigRoot);

    $rootCert = "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
    $expectedSha256 = hash('sha256', $rootCert);

    $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
        'name' => 'gateway-1',
        'wireguard-address' => '10.6.0.2',
        '--skip-gateway-service-install' => true,
    ]);

    $configPath = "{$cliConfigRoot}/config.json";
    $pemPath = "{$cliConfigRoot}/gateways/default/ca.pem";
    $config = File::exists($configPath)
        ? json_decode(File::get($configPath), associative: true, flags: JSON_THROW_ON_ERROR)
        : null;

    expect($exitCode)
        ->toBe(0)
        ->and(File::exists($configPath))
        ->toBeTrue()
        ->and(File::exists($pemPath))
        ->toBeTrue()
        ->and($config)
        ->toBeArray()
        ->and($config['schema_version'] ?? null)
        ->toBe(1)
        ->and($config['active_gateway'] ?? null)
        ->toBe('default')
        ->and($config['gateways']['default']['url'] ?? null)
        ->toBe('https://10.6.0.2')
        ->and($config['gateways']['default']['wireguard_ip'] ?? null)
        ->toBe('10.6.0.2')
        ->and($config['gateways']['default']['ca_pem_path'] ?? null)
        ->toBe($pemPath)
        ->and($config['gateways']['default']['ca_sha256'] ?? null)
        ->toBe($expectedSha256)
        ->and($config['gateways']['default']['ca_fingerprint'] ?? null)
        ->toBe("sha256:{$expectedSha256}")
        ->and($config['gateways']['default']['timeout'] ?? null)
        ->toBe(30)
        ->and($config['gateways']['default']['self_mode'] ?? null)
        ->toBe('wireguard_https')
        ->and($config['defaults'] ?? null)
        ->toBe(['node' => null, 'profile' => null])
        ->and($config['meta'] ?? null)
        ->toBeArray()
        ->and(File::get($pemPath))
        ->toBe($rootCert)
        ->and(File::get($pemPath))
        ->not->toContain('PRIVATE KEY');
});
