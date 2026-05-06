<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tempStorage = sys_get_temp_dir().'/orbit-test-storage-'.uniqid();
    app()->useStoragePath($this->tempStorage);

    $this->fakeInstaller = new class implements TrustStoreInstaller
    {
        public bool $isTrusted = false;

        public bool $throwUnsupported = false;

        public bool $throwCommandFailed = false;

        /** @var list<array{path: string, label: string}> */
        public array $trustCalls = [];

        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return $this->isTrusted;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
        {
            $this->trustCalls[] = ['path' => $rootCaPath, 'label' => $label];

            if ($this->throwUnsupported) {
                throw new TrustStoreInstallException(
                    'Unsupported platform',
                    TrustStoreInstallReason::UnsupportedPlatform,
                );
            }

            if ($this->throwCommandFailed) {
                throw new TrustStoreInstallException(
                    'Command failed',
                    TrustStoreInstallReason::CommandFailed,
                );
            }

            if ($log !== null) {
                $log('Trusting CA...');
            }
        }
    };

    app()->instance(TrustStoreInstaller::class, $this->fakeInstaller);
});

afterEach(function (): void {
    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('resolves gateway_ip from argument', function (): void {
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

it('logs gateway onboarding activity', function (): void {
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

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('gateway:add');
    expect($entry->subject_type)->toBeNull();
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('gateway_ip'))->toBe('10.6.0.2');
    expect($entry->properties->get('gateway_name'))->toBe('gateway-1');
    expect($entry->properties->get('local_node'))->toBe('control-1');
    expect($entry->properties->get('result'))->toBe('added');
});

it('fails for invalid gateway_ip', function (): void {
    $this->artisan('gateway:add', ['gateway_ip' => '192.168.1.1', '--json' => true])
        ->assertFailed();
});

it('fails for missing gateway_ip in non-interactive mode', function (): void {
    $this->artisan('gateway:add', ['--json' => true])
        ->assertFailed();
});

it('fetches and installs gateway ca', function (): void {
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

    expect($this->fakeInstaller->trustCalls)->toHaveCount(1);
});

it('verifies gateway api via https with trusted ca', function (): void {
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

it('persists local gateway settings', function (): void {
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

    $settings = LocalGatewaySettings::current();
    expect($settings->gateway_wg_ip)->toBe('10.6.0.2')
        ->and($settings->gateway_url)->toBe('https://10.6.0.2')
        ->and($settings->ca_sha256)->not->toBeNull()
        ->and($settings->ca_pem_path)->not->toBeNull();
});

it('is idempotent when gateway is already configured', function (): void {
    $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";
    $sha256 = hash('sha256', $pem);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_sha256' => $sha256,
        'ca_pem_path' => storage_path('app/orbit/gateway-ca/orbit.crt'),
    ])->save();

    $pemPath = storage_path('app/orbit/gateway-ca/orbit.crt');
    $dir = dirname($pemPath);
    if (! File::isDirectory($dir)) {
        File::makeDirectory($dir, 0755, true);
    }
    File::put($pemPath, $pem);

    Http::fake([
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertSuccessful();

    expect($this->fakeInstaller->trustCalls)->toHaveCount(0);
});

it('does not create local node registry mirror rows', function (): void {
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

    $nodeCountBefore = Node::query()->count();

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertSuccessful();

    expect(Node::query()->count())->toBe($nodeCountBefore);
});

it('fails when gateway returns 403 for unregistered peer', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response('', 403),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});

it('fails when gateway api returns non-success status', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response('', 500),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});

it('fails when gateway ca endpoint is unreachable', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('', 503),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});

it('fails when gateway returns invalid ca material', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('not a certificate'),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2', '--json' => true])
        ->assertFailed();
});
