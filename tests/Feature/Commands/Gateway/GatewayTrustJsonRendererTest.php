<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Services\Trust\TrustStoreInstaller;
use App\Services\Trust\TrustStoreInstallException;
use App\Services\Trust\TrustStoreInstallReason;
use App\Support\LocalPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
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
    fakeGatewayCaRootThroughLaravelHttp();
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

function seedGatewayTrustJsonAlreadyTrustedSettings(string $pem): void
{
    $pemPath = storage_path('app/orbit/gateway-ca/orbit.crt');
    $dir = dirname($pemPath);

    if (! File::isDirectory($dir)) {
        File::makeDirectory($dir, 0755, true);
    }

    File::put($pemPath, $pem);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_sha256' => hash('sha256', $pem),
        'ca_pem_path' => $pemPath,
        'trusted_at' => now(),
    ])->save();
}

function runJson(string $command = 'gateway:trust', array $args = []): array
{
    $args['--json'] = true;

    $output = new BufferedOutput;
    Artisan::call($command, $args, $output);

    return json_decode($output->fetch(), true);
}

it('selects json renderer with --json flag', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = runJson();

    expect($output)->toHaveKey('success')
        ->and($output['success'])->toHaveKey('data')
        ->and($output['success'])->toHaveKey('meta');
});

it('emits success envelope with trusted status', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = runJson();

    expect($output['success']['data']['gateway_trust']['status'])->toBe('trusted')
        ->and($output['success']['data']['gateway_trust']['trusted'])->toBeTrue()
        ->and($output['success']['data']['gateway_trust']['gateway_url'])->toBe('https://10.6.0.2')
        ->and($output['success']['data']['gateway_trust']['ca_sha256'])->toBe(hash('sha256', "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"))
        ->and($output['success']['meta'])->toHaveKey('trusted_at');
});

it('emits already_trusted status when idempotent', function (): void {
    $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";

    seedGatewayTrustJsonAlreadyTrustedSettings($pem);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => $pem]],
        ]),
    ]);

    $this->fakeInstaller->isTrusted = true;

    $output = runJson();

    expect($output['success']['data']['gateway_trust']['status'])->toBe('already_trusted');
});

it('emits validation_failed for missing gateway', function (): void {
    $output = runJson();

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['message'])->toBe('No gateway is configured. Run orbit gateway:add first.')
        ->and($output['error']['meta']['field'])->toBe('gateway')
        ->and($output['error']['meta']['reason'])->toBe('missing');
});

it('emits validation_failed when registry gateways exist without configured settings', function (): void {
    Node::query()->create([
        'name' => 'gw1',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => false,
    ]);
    Node::query()->create([
        'name' => 'gw2',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.3',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => false,
    ]);

    $output = runJson();

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['meta']['field'])->toBe('gateway')
        ->and($output['error']['meta']['reason'])->toBe('missing');
});

it('emits gateway_unavailable for unreachable endpoint', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('', 503),
    ]);

    $output = runJson();

    expect($output['error']['code'])->toBe('gateway_unavailable')
        ->and($output['error']['meta']['gateway_url'])->toBe('https://10.6.0.2')
        ->and($output['error']['meta']['endpoint'])->toBe('/api/ca/root');
});

it('emits node.gateway_api_error for invalid trust material', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('not a certificate'),
    ]);

    $output = runJson();

    expect($output['error']['code'])->toBe('node.gateway_api_error')
        ->and($output['error']['meta']['reason'])->toBe('invalid_trust_material');
});

it('emits node.unsupported_platform for unsupported platform', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $this->fakeInstaller->throwUnsupported = true;

    $output = runJson();

    expect($output['error']['code'])->toBe('node.unsupported_platform')
        ->and($output['error']['meta']['reason'])->toBe('unsupported_trust_store');
});

it('emits node.unsupported_platform via real AppServiceProvider binding', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    app()->forgetInstance(TrustStoreInstaller::class);
    app()->bind(LocalPlatform::class, function () {
        return new class extends LocalPlatform
        {
            public function current(): string
            {
                return 'unsupported';
            }
        };
    });

    $output = runJson();

    expect($output['error']['code'])->toBe('node.unsupported_platform')
        ->and($output['error']['meta']['reason'])->toBe('unsupported_trust_store');
});

it('emits validation_failed for malformed gateway with non-empty wg_ip', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'ht!tp://bad[url',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    $output = runJson();

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['meta']['field'])->toBe('gateway')
        ->and($output['error']['meta']['reason'])->toBe('invalid');
});

it('emits node.local_config_write_failed for trust store failure', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $this->fakeInstaller->throwCommandFailed = true;

    $output = runJson();

    expect($output['error']['code'])->toBe('node.local_config_write_failed')
        ->and($output['error']['meta']['reason'])->toBe('trust_store_failed');
});

it('emits validation_failed for invalid configured gateway', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => '::not-a-valid-host::',
        'gateway_wg_ip' => '',
    ])->save();

    $output = runJson();

    expect($output['error']['code'])->toBe('validation_failed')
        ->and($output['error']['meta']['field'])->toBe('gateway')
        ->and($output['error']['meta']['reason'])->toBe('invalid');
});

it('emits gateway_unavailable for connection exception', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $output = runJson();

    expect($output['error']['code'])->toBe('gateway_unavailable')
        ->and($output['error']['meta']['gateway_url'])->toBe('https://10.6.0.2')
        ->and($output['error']['meta']['endpoint'])->toBe('/api/ca/root');
});

it('emits node.unsupported_platform when binding throws unsupported', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    app()->bind(TrustStoreInstaller::class, function () {
        throw new TrustStoreInstallException(
            'Unsupported platform',
            TrustStoreInstallReason::UnsupportedPlatform,
        );
    });

    $output = runJson();

    expect($output['error']['code'])->toBe('node.unsupported_platform')
        ->and($output['error']['meta']['reason'])->toBe('unsupported_trust_store');
});

it('emits node.local_config_write_failed for metadata write failure', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    DB::statement('PRAGMA query_only = ON');

    try {
        $output = runJson();

        expect($output['error']['code'])->toBe('node.local_config_write_failed')
            ->and($output['error']['meta']['reason'])->toBe('metadata_write_failed');
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});

it('forces non-interactive mode with --json', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = runJson();

    $buffered = new BufferedOutput;
    Artisan::call('gateway:trust', ['--json' => true], $buffered);

    expect($output)->toHaveKey('success')
        ->and($buffered->fetch())->toContain('gateway_trust');
});
