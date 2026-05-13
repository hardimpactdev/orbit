<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Operations\UpdateAllRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

it('rejects control callers without configured gateway', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake();
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('gateway_unavailable');
    expect($payload['error']['message'])->toBe('Gateway connection is required to update the fleet.');
});

it('forwards control callers to gateway after local update succeeds', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        UpdateAllRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'updates' => [
                        [
                            'target' => 'gateway',
                            'node' => 'gateway',
                            'role' => 'gateway',
                            'status' => 'completed',
                        ],
                        [
                            'target' => 'beast',
                            'node' => 'beast',
                            'role' => 'app',
                            'status' => 'completed',
                        ],
                    ],
                ],
                'meta' => [
                    'summary' => [
                        'total' => 2,
                        'completed' => 2,
                        'failed' => 0,
                    ],
                ],
            ],
        ], 200),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(0);
    expect($payload)->toHaveKey('success.data.updates');
    expect($payload['success']['data']['updates'])->toHaveCount(3);
    expect($payload['success']['data']['updates'][0]['target'])->toBe('local');
    expect($payload['success']['data']['updates'][1]['target'])->toBe('gateway');
    expect($payload['success']['data']['updates'][2]['target'])->toBe('beast');
});

it('returns local failure immediately for control callers without contacting gateway', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    $mockClient = MockClient::global([
        UpdateAllRequest::class => MockResponse::make(['error' => ['code' => 'should_not_reach']], 500),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('local_update_failed');
    expect($payload['error']['meta']['failed_step'])->toBe('local_checkout');

    $mockClient->assertNotSent(UpdateAllRequest::class);
});

it('preserves gateway local failure output for control callers', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        UpdateAllRequest::class => MockResponse::make([
            'error' => [
                'code' => 'local_update_failed',
                'message' => 'Failed to update local Orbit checkout.',
                'data' => [
                    'output' => 'php-fpm8.5 usage',
                ],
                'meta' => [
                    'failed_step' => 'local_checkout',
                ],
            ],
        ], 422),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('local_update_failed');
    expect($payload['error']['data']['output'])->toBe('php-fpm8.5 usage');
    expect($payload['error']['data']['updates'][0])->toBe([
        'target' => 'local',
        'node' => null,
        'role' => null,
        'status' => 'completed',
    ]);
});

it('forwards control callers to gateway and reports remote failure', function (): void {
    config(['orbit.is_gateway' => false]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        UpdateAllRequest::class => MockResponse::make([
            'error' => [
                'code' => 'remote_update_failed',
                'message' => 'One or more Orbit installations failed to update.',
                'data' => [
                    'updates' => [
                        [
                            'target' => 'gateway',
                            'node' => 'gateway',
                            'role' => 'gateway',
                            'status' => 'completed',
                        ],
                        [
                            'target' => 'beast',
                            'node' => 'beast',
                            'role' => 'app',
                            'status' => 'failed',
                            'output' => 'Connection refused',
                        ],
                    ],
                ],
                'meta' => [
                    'summary' => [
                        'total' => 2,
                        'completed' => 1,
                        'failed' => 1,
                    ],
                ],
            ],
        ], 422),
    ]);

    $exitCode = Artisan::call('update:all', ['--json' => true]);
    $output = Artisan::output();
    $payload = json_decode($output, true);

    expect($exitCode)->toBe(1);
    expect($payload['error']['code'])->toBe('remote_update_failed');
    expect($payload['error']['data']['updates'])->toHaveCount(3);
    expect($payload['error']['data']['updates'][0]['status'])->toBe('completed');
    expect($payload['error']['data']['updates'][2]['status'])->toBe('failed');
    expect($payload['error']['data']['updates'][2]['output'])->toBe('Connection refused');
});
