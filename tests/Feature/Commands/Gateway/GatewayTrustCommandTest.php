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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockClient;
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
    fakeGatewayCaRootThroughLaravelHttp();
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

function seedGatewayTrustCommandAlreadyTrustedSettings(string $pem): string
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

    return $pemPath;
}

it('trusts gateway ca when configured via local gateway settings', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => [
                'data' => [
                    'root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----",
                ],
            ],
        ]),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway CA trusted for https://10.6.0.2')
        ->assertSuccessful();

    expect($this->fakeInstaller->trustCalls)->toHaveCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/me'));

    $settings = LocalGatewaySettings::current();
    expect($settings->gateway_url)->toBe('https://10.6.0.2')
        ->and($settings->ca_sha256)->toBe(hash('sha256', "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"))
        ->and($settings->ca_pem_path)->not->toBeNull()
        ->and($settings->trusted_at)->not->toBeNull();
});

it('logs gateway trust activity', function (): void {
    $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => $pem]],
        ]),
    ]);

    $this->artisan('gateway:trust', ['--json' => true])
        ->assertSuccessful();

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('gateway:trust');
    expect($entry->subject_type)->toBeNull();
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('gateway_url'))->toBe('https://10.6.0.2');
    expect($entry->properties->get('gateway_ip'))->toBe('10.6.0.2');
    expect($entry->properties->get('ca_sha256'))->toBe(hash('sha256', $pem));
    expect($entry->properties->get('status'))->toBe('trusted');
});

it('does not fall back to active gateway node when local settings are empty', function (): void {
    Node::query()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'ssh_user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'is_local' => false,
    ]);

    Http::fake();

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('No gateway is configured. Run orbit gateway:add first.')
        ->assertFailed();

    expect($this->fakeInstaller->trustCalls)->toHaveCount(0);
    Http::assertNothingSent();
});

it('fails when no gateway is configured', function (): void {
    $this->artisan('gateway:trust')
        ->expectsOutputToContain('No gateway is configured. Run orbit gateway:add first.')
        ->assertFailed();
});

it('ignores registry gateway candidates when local settings are empty', function (): void {
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

it('is idempotent when ca is already trusted and sha256 matches', function (): void {
    $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----";
    $sha256 = hash('sha256', $pem);

    seedGatewayTrustCommandAlreadyTrustedSettings($pem);

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => $pem]],
        ]),
    ]);

    $this->fakeInstaller->isTrusted = true;

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway CA already trusted for https://10.6.0.2')
        ->assertSuccessful();

    expect($this->fakeInstaller->trustCalls)->toHaveCount(0);
});

it('fails when gateway ca endpoint is unreachable', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('', 500),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Could not fetch the gateway CA from https://10.6.0.2.')
        ->assertFailed();
});

it('fails when gateway returns invalid trust material', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response('not a pem'),
    ]);

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Gateway returned invalid CA material.')
        ->assertFailed();
});

it('does not write gateway intent or node records', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    $nodeCountBefore = Node::query()->count();

    $this->artisan('gateway:trust')
        ->assertSuccessful();

    expect(Node::query()->count())->toBe($nodeCountBefore);
});

it('does not accept public gateway override', function (): void {
    $command = Artisan::all()['gateway:trust'] ?? null;

    expect($command)->not->toBeNull()
        ->and($command->getDefinition()->hasOption('gateway'))->toBeFalse();
});

it('does not expose public export option', function (): void {
    $command = Artisan::all()['gateway:trust'] ?? null;

    expect($command)->not->toBeNull()
        ->and($command->getDefinition()->hasOption('export'))->toBeFalse();
});

it('fails when configured gateway endpoint is invalid', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => '::not-a-valid-host::',
        'gateway_wg_ip' => '',
    ])->save();

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Configured gateway endpoint is invalid.')
        ->assertFailed();
});

it('fails on connection exception as gateway unavailable', function (): void {
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

it('fails on unsupported platform via real AppServiceProvider binding', function (): void {
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

it('fails when configured gateway endpoint is malformed with non-empty wg_ip', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'ht!tp://bad[url',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    $this->artisan('gateway:trust')
        ->expectsOutputToContain('Configured gateway endpoint is invalid.')
        ->assertFailed();
});

it('fails when metadata write fails', function (): void {
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
    ])->save();

    Http::fake([
        'http://10.6.0.2/api/ca/root' => Http::response([
            'success' => ['data' => ['root_ca' => "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"]],
        ]),
    ]);

    // Make SQLite read-only to force save() to fail
    DB::statement('PRAGMA query_only = ON');

    try {
        $this->artisan('gateway:trust')
            ->expectsOutputToContain('Failed to write local trust metadata.')
            ->assertFailed();
    } finally {
        DB::statement('PRAGMA query_only = OFF');
    }
});
