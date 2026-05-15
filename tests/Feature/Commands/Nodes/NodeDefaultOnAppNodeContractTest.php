<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

function setupNodeDefaultAppContractGateway(): void
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function nodeDefaultAppGatewayMock(): MockClient
{
    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(
            nodeDefaultAppIdentityEnvelope(),
            200,
        ),
        ListNodesRequest::class => function (): MockResponse {
            throw new RuntimeException('App-role caller must be rejected before listing nodes.');
        },
    ]);
}

function nodeDefaultAppIdentityEnvelope(): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'app-caller',
                    'role' => 'app',
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                ],
            ],
        ],
    ];
}

describe('node:default on app node contract', function (): void {
    it('rejects set before writing the local default', function (): void {
        setupNodeDefaultAppContractGateway();

        $mock = nodeDefaultAppGatewayMock();

        $exitCode = Artisan::call('node:default', [
            'name' => 'remote-app',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta'])->toBe(['caller_role' => 'app'])
            ->and(DB::table('local_node_defaults')->count())->toBe(0);

        $mock->assertSent(ShowGatewayIdentityRequest::class);
        $mock->assertNotSent(ListNodesRequest::class);
    });

    it('rejects interactive choose before listing nodes or prompting', function (): void {
        setupNodeDefaultAppContractGateway();

        $mock = nodeDefaultAppGatewayMock();

        \Pest\Laravel\artisan('node:default')
            ->expectsOutputToContain('This command may only be run from a control node.')
            ->assertExitCode(1);

        expect(DB::table('local_node_defaults')->count())->toBe(0);

        $mock->assertSent(ShowGatewayIdentityRequest::class);
        $mock->assertNotSent(ListNodesRequest::class);
    });

    it('shows the local default without gateway identity preflight or role rejection', function (): void {
        setupNodeDefaultAppContractGateway();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'stale-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = nodeDefaultAppGatewayMock();

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('show')
            ->and($payload['success']['data']['default_node']['name'])->toBe('stale-app');

        $mock->assertNothingSent();
    });

    it('clears the local default without gateway identity preflight or role rejection', function (): void {
        setupNodeDefaultAppContractGateway();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'stale-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = nodeDefaultAppGatewayMock();

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('clear')
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBeNull();

        $mock->assertNothingSent();
    });
});
