<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
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

it('allows control caller', function (): void {
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

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->assertSuccessful();
});

it('defaults to control when no local node role is set', function (): void {
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

it('denies gateway caller', function (): void {
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

it('denies app caller', function (): void {
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

it('fails for unknown local role setting', function (): void {
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

it('shows correct error message for gateway caller in human mode', function (): void {
    Node::query()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('This command may only be run from a control node.')
        ->assertFailed();
});

it('shows correct error message for app caller in human mode', function (): void {
    Node::query()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'host' => '10.6.0.3',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('This command may only be run from a control node.')
        ->assertFailed();
});

it('shows correct error message for unknown role in human mode', function (): void {
    Node::query()->create([
        'name' => 'weird',
        'role' => 'weird',
        'status' => 'active',
        'host' => '10.6.0.4',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('Local node role setting must be control, gateway, or app.')
        ->assertFailed();
});
