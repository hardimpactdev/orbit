<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\RevokeNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
function nodeRevokeNonInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '10.6.0.8',
        'wireguard_address' => '10.6.0.8',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => null,
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRevokeNonInteractiveControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRevokeNonInteractiveRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @return array<string, mixed>
 */
function nodeRevokeNonInteractiveIdentityEnvelope(): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                    'role' => 'control',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.8'],
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.2'],
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>|string  $revokeBody
 */
function fakeNodeRevokeNonInteractiveGateway(array|string $revokeBody): MockClient
{
    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(nodeRevokeNonInteractiveIdentityEnvelope()),
        RevokeNodeRequest::class => MockResponse::make($revokeBody),
    ]);
}

describe('node:revoke non-interactive input mode contract', function (): void {
    it('fails locally without force in json mode before any gateway request', function (): void {
        setupNodeRevokeNonInteractiveControlCaller();

        $mock = fakeNodeRevokeNonInteractiveGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'gateway-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => true,
                    'was_gateway_admin' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe([
                'code' => 'validation_failed',
                'message' => 'Use --force to revoke this grant.',
                'meta' => [
                    'field' => 'force',
                ],
            ]);

        $mock->assertNothingSent();
    });

    it('sends forced json revokes and renders the gateway self_lockout value', function (): void {
        setupNodeRevokeNonInteractiveControlCaller();

        $mock = fakeNodeRevokeNonInteractiveGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'gateway-1',
                    'action' => 'revoked',
                    'already_absent' => false,
                    'self_lockout' => true,
                    'was_gateway_admin' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                        'was_gateway_admin' => false,
                    ],
                ],
            ]);

        $mock->assertNotSent(ShowGatewayIdentityRequest::class);
        $mock->assertSent(RevokeNodeRequest::class);
    });
});
