<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

it('renders the exact already-provisioned first-gateway convergence line', function (): void {
    config(['orbit.is_gateway' => false]);

    $storage = sys_get_temp_dir().'/orbit-node-new-human-'.uniqid();
    app()->useStoragePath($storage);
    File::ensureDirectoryExists($storage.'/app/orbit/gateway-ca');
    File::put($storage.'/app/orbit/gateway-ca/orbit.crt', "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----");

    app()->instance(TrustStoreInstaller::class, new class implements TrustStoreInstaller
    {
        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            return true;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void {}
    });

    $gateway = Node::factory()->create([
        'name' => 'gateway-1',

        'host' => '203.0.113.2',
        'wireguard_address' => '10.6.0.2',
        'gateway_endpoint' => '203.0.113.2',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'gateway',
        'status' => 'active',
    ]);

    Node::factory()->create([
        'name' => 'operator-1',

        'host' => '127.0.0.1',
        'wireguard_address' => '10.6.0.3',
        'platform' => 'macos_15-4',
        'status' => 'active',
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://203.0.113.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_sha256' => hash('sha256', File::get($storage.'/app/orbit/gateway-ca/orbit.crt')),
        'ca_pem_path' => $storage.'/app/orbit/gateway-ca/orbit.crt',
        'trusted_at' => now(),
    ])->save();

    MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'self' => [
                        'name' => 'operator-1',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.3'],
                    ],
                    'gateway' => [
                        'name' => 'gateway-1',
                        'status' => 'active',
                        'addresses' => ['wireguard' => '10.6.0.2'],
                    ],
                ],
            ],
        ]),
    ]);
    Process::fake();
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'gateway-1',
        '--template' => 'gateway',
        '--host' => '203.0.113.2',
        '--operator-name' => 'operator-1',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Gateway is already provisioned.')
        ->and($output)->not->toContain('Gateway node gateway-1 already provisioned.')
        ->and($output)->not->toContain('Endpoint:')
        ->and($output)->not->toContain('Operator node:');

    File::deleteDirectory($storage);
});
