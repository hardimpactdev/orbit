<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('shows human renderer by default', function (): void {
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
        ->expectsOutputToContain('┌ Join Gateway')
        ->expectsOutputToContain('○ Resolve gateway')
        ->expectsOutputToContain('○ Fetch trust material')
        ->expectsOutputToContain('○ Trust gateway CA')
        ->expectsOutputToContain('○ Verify gateway API')
        ->expectsOutputToContain('○ Verify identity')
        ->expectsOutputToContain('○ Store local config')
        ->expectsOutputToContain("Joined 'gateway-1' as 'control-1' (control)")
        ->assertSuccessful();
});

it('shows converged progress tree', function (): void {
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

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('┌ Join Gateway')
        ->expectsOutputToContain('○ Resolve gateway')
        ->expectsOutputToContain('○ Verify gateway API')
        ->expectsOutputToContain('○ Verify identity')
        ->expectsOutputToContain('Gateway 10.6.0.2 is already configured')
        ->assertSuccessful();
});

it('shows missing gateway_ip prose', function (): void {
    $this->artisan('gateway:add', ['--json' => true])
        ->expectsOutputToContain('Gateway IP is required when it cannot be derived from an active WireGuard network.')
        ->assertFailed();
});

it('shows invalid gateway_ip prose', function (): void {
    $this->artisan('gateway:add', ['gateway_ip' => '192.168.1.1'])
        ->expectsOutputToContain('Gateway IP must be a valid Orbit WireGuard address.')
        ->assertFailed();
});

it('shows caller role denial prose for gateway', function (): void {
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

it('shows caller role denial prose for app', function (): void {
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

it('shows local context error prose', function (): void {
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

it('shows unregistered peer prose', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response('', 403),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('This peer is not registered on the gateway at 10.6.0.2')
        ->assertFailed();
});

it('shows gateway unavailable prose', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('', 503),
    ]);

    $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
        ->expectsOutputToContain('Could not fetch the gateway CA from 10.6.0.2.')
        ->assertFailed();
});

it('shows local config write failure prose', function (): void {
    // Ensure LocalGatewaySettings record exists before making DB read-only
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

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

    DB::statement('PRAGMA query_only = ON');

    try {
        $this->artisan('gateway:add', ['gateway_ip' => '10.6.0.2'])
            ->expectsOutputToContain('Failed to store local gateway configuration.')
            ->assertFailed();
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});

it('does not emit json envelope in human mode', function (): void {
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

    $output = new BufferedOutput;
    Artisan::call('gateway:add', ['gateway_ip' => '10.6.0.2'], $output);
    $text = $output->fetch();

    expect($text)->not->toContain('"success"')
        ->not->toContain('"error"')
        ->not->toContain('"action"');
});
