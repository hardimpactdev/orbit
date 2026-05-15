<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_sha256' => 'fake',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
        'trusted_at' => now(),
    ])->save();
});

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

it('renders gateway app-caller rejection before local side effects', function (): void {
    MockClient::global([
        CreateNodeRequest::class => MockResponse::make([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control or gateway node.',
                'meta' => ['caller_role' => 'app'],
            ],
        ], 403),
    ]);

    Process::fake();
    Process::preventStrayProcesses();

    $exitCode = Artisan::call('node:new', [
        'name' => 'app-1',
        '--role' => 'app',
        '--environment' => 'development',
        '--host' => '192.0.2.20',
        '--tld' => 'test',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error'])->toBe([
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from a control or gateway node.',
            'meta' => ['caller_role' => 'app'],
        ])
        ->and(DB::table('nodes')->count())->toBe(0);

    Process::assertRanTimes(fn (): bool => true, 0);
});
