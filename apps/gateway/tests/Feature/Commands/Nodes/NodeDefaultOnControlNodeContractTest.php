<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultControlContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'roles' => [
            [
                'status' => 'active',
                'settings' => ['tld' => 'test'],
            ],
        ],
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeDefaultControlContractGateway(): void
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  list<array<string, mixed>>  $nodes
 */
function nodeDefaultControlGatewayMock(array $nodes): MockClient
{
    return MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'nodes' => $nodes,
                ],
            ],
        ], 200),
    ]);
}

describe('node:default on operator node contract', function (): void {
    it('shows the local default without gateway identity preflight', function (): void {
        setupNodeDefaultControlContractGateway();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'stale-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = nodeDefaultControlGatewayMock([]);

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('show')
            ->and($payload['success']['data']['default_node']['name'])->toBe('stale-app');

        $mock->assertNothingSent();
    });

    it('clears the local default without gateway identity preflight', function (): void {
        setupNodeDefaultControlContractGateway();
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'stale-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mock = nodeDefaultControlGatewayMock([]);

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('clear')
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBeNull();

        $mock->assertNothingSent();
    });

    it('sets a local default without gateway identity preflight', function (): void {
        setupNodeDefaultControlContractGateway();

        $mock = nodeDefaultControlGatewayMock([
            nodeDefaultControlContractRow(['name' => 'remote-app']),
        ]);

        $exitCode = Artisan::call('node:default', [
            'name' => 'remote-app',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('set')
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBe('remote-app');

        $mock->assertSent(ListNodesRequest::class);
    });

    it('lists nodes for interactive choose without gateway identity preflight', function (): void {
        setupNodeDefaultControlContractGateway();

        $mock = nodeDefaultControlGatewayMock([
            nodeDefaultControlContractRow(['name' => 'remote-app-1']),
            nodeDefaultControlContractRow(['name' => 'remote-app-2']),
        ]);

        DataTablePrompt::fake([Key::DOWN, Key::ENTER]);

        \Pest\Laravel\artisan('node:default')
            ->assertExitCode(0);

        expect(DB::table('local_node_defaults')->value('default_node_name'))->toBe('remote-app-2');

        $mock->assertSent(ListNodesRequest::class);
    });

    it('preserves gateway-host rejection before local mutation', function (): void {
        config(['orbit.is_gateway' => true]);
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'existing-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', [
            'name' => 'remote-app',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['reason' => 'not_supported_on_gateway'])
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBe('existing-app');
    });
});
