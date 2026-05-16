<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\CreateNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeNewInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '127.0.0.1',
        'wireguard_address' => '10.6.0.3',
        'user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => null,
        'platform' => 'macos_15-4',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeNewInteractiveControlCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function setupNodeNewInteractiveUnconfiguredControlCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow());
}

function setupNodeNewInteractiveAppCaller(): void
{
    DB::table('nodes')->insert(nodeNewInteractiveRow([
        'name' => 'local-app',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'environment' => 'development',
    ]));
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeNewGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        CreateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:new interactive input mode', function (): void {
    it('prompts for missing name and role before control-node path validation', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveUnconfiguredControlCaller();

        $this->artisan('node:new')
            ->expectsQuestion('Node name', 'control-2')
            ->expectsChoice('Node role', 'control', ['gateway', 'app', 'control'])
            ->expectsOutputToContain('Gateway connection is required before creating app or control nodes.')
            ->assertFailed();
    });

    it('prompts for app inputs in documented order and forwards the resolved request', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'role' => 'app',
                        'environment' => 'development',
                        'tld' => 'test',
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new')
            ->expectsQuestion('Node name', 'app-1')
            ->expectsChoice('Node role', 'app', ['gateway', 'app', 'control'])
            ->expectsChoice('App node environment', 'development', ['development', 'production'])
            ->expectsQuestion('Host', '192.0.2.20')
            ->expectsQuestion('Development TLD', 'test')
            ->expectsQuestion('SSH user', 'deployer')
            ->expectsOutputToContain('Created app node app-1.')
            ->assertSuccessful();
    });

    it('does not prompt for tld when app environment is production', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeNewInteractiveControlCaller();
        fakeNodeNewGateway([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'node' => [
                        'name' => 'app-1',
                        'role' => 'app',
                        'environment' => 'production',
                        'tld' => null,
                        'status' => 'active',
                    ],
                    'provisioning' => [
                        'transport' => 'ssh',
                        'host' => '192.0.2.20',
                        'status' => 'complete',
                    ],
                    'next_steps' => [],
                ],
            ],
        ]);

        $this->artisan('node:new app-1 --role=app')
            ->expectsChoice('App node environment', 'production', ['development', 'production'])
            ->expectsQuestion('Host', '192.0.2.20')
            ->expectsQuestion('SSH user', 'root')
            ->expectsOutputToContain('Created app node app-1.')
            ->assertSuccessful();
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        setupNodeNewInteractiveControlCaller();

        $this->artisan('node:new --json')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });

});
