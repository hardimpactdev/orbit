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
    config(['orbit.is_gateway' => false]);
});

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

it('selects interactive mode in tty without --json', function (): void {
    Node::query()->create([
        'name' => 'control-1',
        'status' => 'active',
        'host' => '10.6.0.8',
        'orbit_path' => '/home/orbit/orbit',
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

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->assertSuccessful();
});

it('does not prompt when gateway_ip is supplied in interactive mode', function (): void {
    Node::query()->create([
        'name' => 'control-1',
        'status' => 'active',
        'host' => '10.6.0.8',
        'orbit_path' => '/home/orbit/orbit',
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

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->doesntExpectOutput('Gateway IP')
        ->assertSuccessful();
});

it('does not prompt when gateway_ip is derived in interactive mode', function (): void {
    Node::query()->create([
        'name' => 'control-1',
        'status' => 'active',
        'host' => '10.6.0.8',
        'orbit_path' => '/home/orbit/orbit',
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

    $this->artisan('gateway:add')
        ->doesntExpectOutput('Gateway IP')
        ->assertSuccessful();
});
