<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\WireGuard\WireGuardGatewayAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tempStorage = sys_get_temp_dir().'/orbit-test-storage-'.uniqid();
    app()->useStoragePath($this->tempStorage);

    $fakeInstaller = new class implements TrustStoreInstaller
    {
        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return false;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
        {
            if ($log !== null) {
                $log('Trusting CA...');
            }
        }
    };

    app()->instance(TrustStoreInstaller::class, $fakeInstaller);
    fakeGatewayIdentity();
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('selects non-interactive mode with --json flag', function (): void {
    Node::query()->create([
        'name' => 'control-1',
        'role' => 'control',
        'status' => 'active',
        'host' => '10.6.0.8',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertSuccessful();
});

it('fails when gateway_ip is missing in non-interactive mode', function (): void {
    app()->instance(WireGuardGatewayAddressResolver::class, new class extends WireGuardGatewayAddressResolver
    {
        public function resolve(): ?string
        {
            return null;
        }
    });

    $this->artisan('gateway:add', ['--json' => true])
        ->assertFailed();
});

it('derives gateway_ip in non-interactive mode when the active WireGuard network is unambiguous', function (): void {
    Node::query()->create([
        'name' => 'control-1',
        'role' => 'control',
        'status' => 'active',
        'host' => '10.6.0.8',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    app()->instance(WireGuardGatewayAddressResolver::class, new class extends WireGuardGatewayAddressResolver
    {
        public function resolve(): ?string
        {
            return '10.6.0.2';
        }
    });

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $this->artisan('gateway:add', ['--json' => true])
        ->assertSuccessful();
});

it('fails for invalid gateway_ip in non-interactive mode', function (): void {
    $this->artisan('gateway:add', ['gateway_ip' => '192.168.1.1', '--json' => true])
        ->assertFailed();
});

it('rejects gateway caller in non-interactive mode', function (): void {
    Node::query()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});

it('rejects app caller in non-interactive mode', function (): void {
    Node::query()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'host' => '10.6.0.3',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});

it('rejects unknown role caller in non-interactive mode', function (): void {
    Node::query()->create([
        'name' => 'weird',
        'role' => 'weird',
        'status' => 'active',
        'host' => '10.6.0.4',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});
