<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Services\WireGuard\WireGuardGatewayAddressResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
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
    fakeGatewayIdentity();
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

function runGatewayAddJson(array $args = []): array
{
    $args['--json'] = true;

    $output = new BufferedOutput;
    Artisan::call('gateway:add', $args, $output);

    return json_decode($output->fetch(), true);
}

function disableGatewayAddJsonIpDerivation(): void
{
    app()->instance(WireGuardGatewayAddressResolver::class, new class extends WireGuardGatewayAddressResolver
    {
        public function resolve(): ?string
        {
            return null;
        }
    });
}

it('selects json renderer with --json flag', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active', 'platform' => 'ubuntu_24-04'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'platform' => 'macos_15-4', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output)->toHaveKey('success')
        ->and($output['success'])->toHaveKey('data');
});

it('emits added success envelope', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active', 'platform' => 'ubuntu_24-04'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'platform' => 'macos_15-4', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['success']['data']['result']['action'])->toBe('added')
        ->and($output['success']['data']['gateway']['name'])->toBe('gateway-1')
        ->and($output['success']['data']['gateway']['role'])->toBe('gateway')
        ->and($output['success']['data']['gateway']['status'])->toBe('active')
        ->and($output['success']['data']['gateway']['addresses']['wireguard'])->toBe('10.6.0.2')
        ->and($output['success']['data']['local_node']['name'])->toBe('control-1')
        ->and($output['success']['data']['local_node']['role'])->toBe('control')
        ->and($output['success']['data']['local_node']['status'])->toBe('active')
        ->and($output['success']['data']['local_onboarding']['gateway_trust'])->toBe('trusted')
        ->and($output['success']['data']['local_onboarding']['gateway_config'])->toBe('stored')
        ->and($output['success']['data']['local_onboarding']['gateway_api'])->toBe('verified');
});

it('accepts the gateway api success data envelope for identity verification', function (): void {
    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
        'https://10.6.0.2/api/me' => Http::response([
            'success' => [
                'data' => [
                    'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active', 'platform' => 'ubuntu_24-04'],
                    'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'platform' => 'macos_15-4', 'addresses' => ['wireguard' => '10.6.0.8']],
                ],
            ],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['success']['data']['gateway']['name'])->toBe('gateway-1')
        ->and($output['success']['data']['local_node']['name'])->toBe('control-1')
        ->and($output['success']['data']['local_node']['addresses']['wireguard'])->toBe('10.6.0.8');
});

it('emits converged success envelope', function (): void {
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

    $this->fakeInstaller->isTrusted = true;

    Http::fake([
        'https://10.6.0.2/api/me' => Http::response([
            'data' => [
                'gateway' => ['name' => 'gateway-1', 'role' => 'gateway', 'status' => 'active', 'platform' => 'ubuntu_24-04'],
                'self' => ['name' => 'control-1', 'role' => 'control', 'status' => 'active', 'platform' => 'macos_15-4', 'wg_ip' => '10.6.0.8'],
            ],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['success']['data']['result']['action'])->toBe('converged')
        ->and($output['success']['data']['local_onboarding']['gateway_trust'])->toBe('already_trusted')
        ->and($output['success']['data']['local_onboarding']['gateway_config'])->toBe('already_stored');
});

it('emits validation_failed for missing gateway_ip', function (): void {
    disableGatewayAddJsonIpDerivation();

    $output = runGatewayAddJson();

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['message'])->toBe('Gateway IP is required when it cannot be derived from an active WireGuard network.')
        ->and($output['error']['meta']['field'])->toBe('gateway_ip')
        ->and($output['error']['meta']['reason'])->toBe('missing');
});

it('emits validation_failed for invalid gateway_ip', function (): void {
    $output = runGatewayAddJson(['gateway_ip' => '192.168.1.1']);

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['message'])->toBe('Gateway IP must be a valid Orbit WireGuard address.')
        ->and($output['error']['meta']['field'])->toBe('gateway_ip')
        ->and($output['error']['meta']['reason'])->toBe('invalid_ip');
});

it('emits caller_role_not_allowed for gateway caller', function (): void {
    Node::query()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('caller_role_not_allowed')
        ->and($output['error']['message'])->toBe('This command may only be run from a control node.')
        ->and($output['error']['meta']['caller_role'])->toBe('gateway');
});

it('emits caller_role_not_allowed for app caller', function (): void {
    Node::query()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'host' => '10.6.0.3',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('caller_role_not_allowed')
        ->and($output['error']['meta']['caller_role'])->toBe('app');
});

it('emits local_context_invalid for unknown role', function (): void {
    Node::query()->create([
        'name' => 'weird',
        'role' => 'weird',
        'status' => 'active',
        'host' => '10.6.0.4',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => true,
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('local_context_invalid')
        ->and($output['error']['message'])->toBe('Local node role setting must be control, gateway, or app.')
        ->and($output['error']['meta']['setting'])->toBe('general.local_node_role')
        ->and($output['error']['meta']['reason'])->toBe('unsupported_value')
        ->and($output['error']['meta']['caller_role'])->toBe('unknown');
});

it('emits node.identity_unknown for 403 response', function (): void {
    fakeGatewayIdentity('', 403);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('node.identity_unknown')
        ->and($output['error']['meta']['gateway_ip'])->toBe('10.6.0.2');
});

it('emits gateway_unavailable for non-success api response', function (): void {
    fakeGatewayIdentity('', 500);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('gateway_unavailable')
        ->and($output['error']['meta']['gateway_ip'])->toBe('10.6.0.2')
        ->and($output['error']['meta']['status'])->toBe(500);
});

it('emits gateway_unavailable for unreachable ca endpoint', function (): void {
    fakeGatewayIdentity(rootCaBody: '', rootCaStatus: 503);

    $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

    expect($output['error']['code'])->toBe('gateway_unavailable')
        ->and($output['error']['meta']['gateway_ip'])->toBe('10.6.0.2');
});

it('emits node.local_config_write_failed for metadata write failure', function (): void {
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
        $output = runGatewayAddJson(['gateway_ip' => '10.6.0.2']);

        expect($output['error']['code'])->toBe('node.local_config_write_failed')
            ->and($output['error']['meta']['gateway_ip'])->toBe('10.6.0.2');
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});
