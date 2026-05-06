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

function seedGatewayTrustHumanAlreadyTrustedSettings(string $pem): void
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

it('shows human renderer by default', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('┌ Trust Gateway CA')
        ->expectsOutputToContain('○ Resolve configured gateway')
        ->expectsOutputToContain('○ Fetch trust material')
        ->expectsOutputToContain('○ Install local trust')
        ->expectsOutputToContain('○ Store trust metadata')
        ->expectsOutputToContain('└ Gateway CA trusted for https://10.6.0.2')
        ->expectsOutputToContain('Gateway CA trusted for https://10.6.0.2.')
        ->assertSuccessful();
});

it('shows already-trusted progress tree', function (): void {
    $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";

    seedGatewayTrustHumanAlreadyTrustedSettings($pem);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => $pem]],
        ]),
    ]);

    $this->fakeInstaller->isTrusted = true;

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('┌ Trust Gateway CA')
        ->expectsOutputToContain('○ Resolve configured gateway')
        ->expectsOutputToContain('○ Check local trust')
        ->expectsOutputToContain('└ Gateway CA already trusted for https://10.6.0.2')
        ->expectsOutputToContain('Gateway CA already trusted for https://10.6.0.2.')
        ->assertSuccessful();
});

it('shows missing gateway prose', function (): void {
    $this->artisan('gateway:trust')
        ->expectsOutputToContain('No gateway is configured. Run orbit gateway:add first.')
        ->assertFailed();
});

it('shows missing gateway prose when registry gateways exist without configured settings', function (): void {
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

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('No gateway is configured. Run orbit gateway:add first.')
        ->assertFailed();
});

it('shows gateway fetch failure prose', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('', 503),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Could not fetch the gateway CA from https://10.6.0.2.')
        ->assertFailed();
});

it('shows invalid trust material prose', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('invalid'),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway returned invalid CA material.')
        ->assertFailed();
});

it('shows unsupported platform prose', function (): void {
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

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('This platform does not support automatic gateway CA trust installation.')
        ->assertFailed();
});

it('shows unsupported platform prose via real AppServiceProvider binding', function (): void {
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

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('This platform does not support automatic gateway CA trust installation.')
        ->assertFailed();
});

it('shows malformed gateway prose with non-empty wg_ip', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'ht!tp://bad[url',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Configured gateway endpoint is invalid.')
        ->assertFailed();
});

it('shows trust store failure prose', function (): void {
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

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Failed to install the gateway CA into the local trust store.')
        ->assertFailed();
});

it('shows invalid configured gateway prose', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => '::not-a-valid-host::',
        'gateway_wg_ip' => '',
    ])->save();

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Configured gateway endpoint is invalid.')
        ->assertFailed();
});

it('shows connection failure prose as gateway unavailable', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Could not fetch the gateway CA from https://10.6.0.2.')
        ->assertFailed();
});

it('shows unsupported platform prose from binding failure', function (): void {
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

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('This platform does not support automatic gateway CA trust installation.')
        ->assertFailed();
});

it('shows metadata write failure prose', function (): void {
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
        $this->artisan('gateway:trust')
            ->expectsOutputToContain('Failed to write local trust metadata.')
            ->assertFailed();
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});

it('does not emit json envelope in human mode', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $output = new BufferedOutput;
    Artisan::call('gateway:trust', [], $output);
    $text = $output->fetch();

    expect($text)->not->toContain('"success"')
        ->not->toContain('"error"')
        ->not->toContain('gateway_trust');
});
