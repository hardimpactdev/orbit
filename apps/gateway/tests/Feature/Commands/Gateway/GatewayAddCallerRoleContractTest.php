<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('allows control caller', function (): void {
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

it('accepts gateway identity payloads that still include legacy role keys', function (): void {
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

it('fails gateway-local hosts before input prompts or side effects', function (): void {
    config(['orbit.is_gateway' => true]);

    $fakeInstaller = new class implements TrustStoreInstaller
    {
        public int $trustCalls = 0;

        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return false;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
        {
            $this->trustCalls++;
        }
    };

    app()->instance(TrustStoreInstaller::class, $fakeInstaller);

    Http::fake(fn () => throw new RuntimeException('gateway:add should reject before HTTP side effects'));

    $output = new BufferedOutput;
    $exitCode = Artisan::call('gateway:add', ['--json' => true], $output);
    $payload = json_decode($output->fetch(), true);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toBe(['reason' => 'not_supported_on_gateway'])
        ->and($payload['error']['message'])->toBe('This command is not supported on gateway nodes.')
        ->and($fakeInstaller->trustCalls)->toBe(0);
});
