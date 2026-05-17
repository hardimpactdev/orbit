<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Gateway\GatewayApiRuntimeInstaller;
use App\Services\Vpn\WgEasyServiceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

describe('orbit:internal:bootstrap-gateway-local', function (): void {
    beforeEach(function (): void {
        $this->tempStorage = sys_get_temp_dir().'/orbit-ca-test-'.uniqid();
        mkdir($this->tempStorage.'/app/orbit', 0777, true);
        app()->useStoragePath($this->tempStorage);
        $this->originalEnvironmentPath = app()->environmentPath();
        app()->useEnvironmentPath($this->tempStorage);
        File::put("{$this->tempStorage}/.env", "APP_NAME=Orbit\n");

        $this->gatewayApiRuntimeInstaller = new class extends GatewayApiRuntimeInstaller
        {
            /** @var list<string> */
            public array $addresses = [];

            public function __construct() {}

            public function install(string $wireguardAddress, string $phpVersion = '8.5', string $orbitPath = ''): void
            {
                $this->addresses[] = $wireguardAddress;
            }
        };

        $this->wgEasyServiceInstaller = new class extends WgEasyServiceInstaller
        {
            /** @var list<array{publicHost: string, passwordHash: string}> */
            public array $invocations = [];

            public function __construct() {}

            public function install(string $publicHost, string $passwordHash): void
            {
                $this->invocations[] = ['publicHost' => $publicHost, 'passwordHash' => $passwordHash];
            }
        };

        $this->orbitDnsServiceInstaller = new class extends OrbitDnsServiceInstaller
        {
            public int $installs = 0;

            public function __construct() {}

            public function install(): void
            {
                $this->installs++;
            }
        };

        app()->instance(GatewayApiRuntimeInstaller::class, $this->gatewayApiRuntimeInstaller);
        app()->instance(WgEasyServiceInstaller::class, $this->wgEasyServiceInstaller);
        app()->instance(OrbitDnsServiceInstaller::class, $this->orbitDnsServiceInstaller);
    });

    afterEach(function (): void {
        if (isset($this->originalEnvironmentPath)) {
            app()->useEnvironmentPath($this->originalEnvironmentPath);
        }

        if (isset($this->tempStorage) && is_dir($this->tempStorage)) {
            File::deleteDirectory($this->tempStorage);
        }
    });

    it('creates a local gateway node record and generates the root CA', function (): void {
        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and(Node::query()->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and(Node::query()->where('name', 'gateway-1')->value('role'))->toBe('gateway')
            ->and($output)->toContain('-----BEGIN CERTIFICATE-----')
            ->and($output)->toContain('-----END CERTIFICATE-----')
            ->and(File::get(app()->environmentFilePath()))->toContain('ORBIT_IS_GATEWAY=true')
            ->and($this->gatewayApiRuntimeInstaller->addresses)->toBe(['10.6.0.2']);
    });

    it('can skip gateway runtime installation for container topology preparation', function (): void {
        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--skip-runtime-install' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(Node::query()->where('name', 'gateway-1')->exists())->toBeTrue()
            ->and($this->gatewayApiRuntimeInstaller->addresses)->toBe([])
            ->and($this->wgEasyServiceInstaller->invocations)->toBe([])
            ->and($this->orbitDnsServiceInstaller->installs)->toBe(0);
    });

    it('installs wg-easy before orbit-dns after the gateway API runtime', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        expect($this->gatewayApiRuntimeInstaller->addresses)->toBe(['10.6.0.2'])
            ->and($this->wgEasyServiceInstaller->invocations)->toHaveCount(1)
            ->and($this->wgEasyServiceInstaller->invocations[0]['publicHost'])->toBe('203.0.113.10')
            ->and($this->wgEasyServiceInstaller->invocations[0]['passwordHash'])->not->toBe('')
            ->and($this->orbitDnsServiceInstaller->installs)->toBe(1);
    });

    it('persists the wg-easy admin password hash in the gateway env', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        $env = File::get(app()->environmentFilePath());

        expect($env)->toContain('WG_EASY_PASSWORD_HASH=');
    });

    it('reuses an existing wg-easy admin password hash on re-bootstrap', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);
        $firstHash = $this->wgEasyServiceInstaller->invocations[0]['passwordHash'];

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--public-host' => '203.0.113.10',
        ]);

        expect($this->wgEasyServiceInstaller->invocations[1]['passwordHash'])->toBe($firstHash);
    });

    it('skips wg-easy and orbit-dns when public host is not provided', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect($this->wgEasyServiceInstaller->invocations)->toBe([])
            ->and($this->orbitDnsServiceInstaller->installs)->toBe(0);
    });

    it('sets the gateway tld to "gateway" by default', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect(Node::query()->where('name', 'gateway-1')->value('tld'))->toBe('gateway');
    });

    it('honors a custom --tld value for the gateway', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--tld' => 'orbital',
        ]);

        expect(Node::query()->where('name', 'gateway-1')->value('tld'))->toBe('orbital');
    });

    it('is idempotent when the gateway node and CA already exist', function (): void {
        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $firstOutput = Artisan::output();

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        $secondOutput = Artisan::output();

        expect($firstOutput)->toBe($secondOutput)
            ->and(Node::query()->where('name', 'gateway-1')->count())->toBe(1);
    });

    it('keeps existing control nodes when creating the gateway record', function (): void {
        Node::query()->create([
            'name' => 'old-control',
            'role' => 'control',
            'host' => '127.0.0.1',
            'orbit_path' => base_path(),
            'status' => 'active',
        ]);

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
        ]);

        expect(Node::query()->where('name', 'old-control')->value('role'))->toBe('control')
            ->and(Node::query()->where('name', 'gateway-1')->value('role'))->toBe('gateway');
    });

    it('persists wireguard peers and configures the gateway interface idempotently', function (): void {
        $writtenConfig = null;
        $caDir = storage_path('app/orbit/ca');

        File::ensureDirectoryExists($caDir);
        File::put("{$caDir}/root.key", 'test-root-key');
        File::put("{$caDir}/root.crt", "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n");

        Process::fake(function ($process) use (&$writtenConfig) {
            if (str_contains($process->command, 'tee /etc/wireguard/wg-orbit.conf')) {
                $writtenConfig = (string) $process->input;
            }

            return Process::result();
        });
        Process::preventStrayProcesses();

        $identity = [
            'gateway' => [
                'public_key' => 'gateway-public-v1',
                'private_key' => 'gateway-private-v1',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v1',
                'private_key' => 'control-private-v1',
            ],
        ];

        $exitCode = Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($identity, JSON_THROW_ON_ERROR),
        ]);

        $gateway = Node::query()->where('name', 'gateway-1')->first();
        $control = Node::query()->where('name', 'mini')->first();
        $gatewayPeer = WireGuardPeer::query()->where('node_id', $gateway?->id)->first();
        $controlPeer = WireGuardPeer::query()->where('node_id', $control?->id)->first();

        expect($exitCode)->toBe(0)
            ->and($gateway)->toBeInstanceOf(Node::class)
            ->and($control)->toBeInstanceOf(Node::class)
            ->and($control->role)->toBe('control')
            ->and($control->wireguard_address)->toBe('10.6.0.3')
            ->and($gatewayPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($gatewayPeer->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->private_key)->toBe('gateway-private-v1')
            ->and($gatewayPeer->allowed_ips)->toBe('10.6.0.2/32')
            ->and($controlPeer)->toBeInstanceOf(WireGuardPeer::class)
            ->and($controlPeer->public_key)->toBe('control-public-v1')
            ->and($controlPeer->private_key)->toBe('control-private-v1')
            ->and($controlPeer->allowed_ips)->toBe('10.6.0.3/32')
            ->and($writtenConfig)->toContain('PrivateKey = gateway-private-v1')
            ->and($writtenConfig)->toContain('Address = 10.6.0.2/24')
            ->and($writtenConfig)->toContain('ListenPort = 51820')
            ->and($writtenConfig)->toContain('PublicKey = control-public-v1')
            ->and($writtenConfig)->toContain('AllowedIPs = 10.6.0.3/32');

        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo mkdir -p /etc/wireguard'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo tee /etc/wireguard/wg-orbit.conf'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo wg-quick up wg-orbit'));
        Process::assertRan(fn ($process): bool => str_contains($process->command, 'sudo systemctl enable wg-quick@wg-orbit'));

        $replacementIdentity = [
            'gateway' => [
                'public_key' => 'gateway-public-v2',
                'private_key' => 'gateway-private-v2',
            ],
            'control' => [
                'name' => 'mini',
                'wireguard_address' => '10.6.0.3',
                'public_key' => 'control-public-v2',
                'private_key' => 'control-private-v2',
            ],
        ];

        Artisan::call('orbit:internal:bootstrap-gateway-local', [
            'name' => 'gateway-1',
            'wireguard-address' => '10.6.0.2',
            '--identity-json' => json_encode($replacementIdentity, JSON_THROW_ON_ERROR),
        ]);

        expect(Node::query()->whereIn('name', ['gateway-1', 'mini'])->count())->toBe(2)
            ->and(WireGuardPeer::query()->count())->toBe(2)
            ->and($gatewayPeer->fresh()->public_key)->toBe('gateway-public-v1')
            ->and($gatewayPeer->fresh()->private_key)->toBe('gateway-private-v1')
            ->and($controlPeer->fresh()->public_key)->toBe('control-public-v1')
            ->and($controlPeer->fresh()->private_key)->toBe('control-private-v1');
    });
});
