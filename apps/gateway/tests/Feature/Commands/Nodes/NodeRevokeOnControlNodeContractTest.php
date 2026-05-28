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
function nodeRevokeControlContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRevokeControlCallerContract(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRevokeControlContractRow([
        'name' => 'control-1',
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>  $self
 * @param  array<string, mixed>  $gateway
 * @return array<string, mixed>
 */
function nodeRevokeControlIdentityEnvelope(array $self = [], array $gateway = []): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.8'],
                    ...$self,
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.2'],
                    ...$gateway,
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>|string  $identityBody
 * @param  array<string, mixed>|string  $revokeBody
 */
function fakeNodeRevokeControlGateway(
    array|string $identityBody,
    array|string $revokeBody,
    int $identityStatus = 200,
    int $revokeStatus = 200,
): MockClient {
    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make($identityBody, $identityStatus),
        RevokeNodeRequest::class => MockResponse::make($revokeBody, $revokeStatus),
    ]);
}

describe('node:revoke on operator node contract', function (): void {
    it('uses the documented self-lockout confirmation when the caller revokes its own gateway grant', function (): void {
        setupNodeRevokeControlCallerContract();

        fakeNodeRevokeControlGateway(
            nodeRevokeControlIdentityEnvelope(
                self: ['name' => 'control-1'],
                gateway: ['name' => 'gateway-1'],
            ),
            [
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                    ],
                ],
            ],
        );

        \Pest\Laravel\artisan('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
        ])
            ->expectsConfirmation('Revoke this operator node\'s gateway access? This machine will lose Orbit gateway access.', 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(1);
    });

    it('uses the generic revoke confirmation when the consuming node is not the caller', function (): void {
        setupNodeRevokeControlCallerContract();

        fakeNodeRevokeControlGateway(
            nodeRevokeControlIdentityEnvelope(
                self: ['name' => 'control-1'],
                gateway: ['name' => 'gateway-1'],
            ),
            [
                'success' => [
                    'data' => [
                        'consuming_node' => 'other-control',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => false,
                    ],
                ],
            ],
        );

        \Pest\Laravel\artisan('node:revoke', [
            'consuming_node' => 'other-control',
            'serving_node' => 'gateway-1',
        ])
            ->expectsConfirmation("Revoke access from 'other-control' to 'gateway-1'? This cannot be undone.", 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(1);
    });

    it('fails locally before gateway identity preflight when json mode lacks force', function (): void {
        setupNodeRevokeControlCallerContract();

        $mock = fakeNodeRevokeControlGateway(
            nodeRevokeControlIdentityEnvelope(),
            [
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                    ],
                ],
            ],
        );

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['message'])->toBe('Use --force to revoke this grant.');

        $mock->assertNothingSent();
    });

    it('sends forced json revokes directly and preserves the gateway self_lockout response', function (): void {
        setupNodeRevokeControlCallerContract();

        $mock = fakeNodeRevokeControlGateway(
            nodeRevokeControlIdentityEnvelope(),
            [
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                    ],
                ],
            ],
        );

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
            '--force' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['self_lockout'])->toBeTrue();

        $mock->assertNotSent(ShowGatewayIdentityRequest::class);
        $mock->assertSent(fn (RevokeNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/revoke'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'gateway-1',
                'force' => true,
            ]);
    });

    it('fails closed before prompting when the gateway identity preflight fails', function (): void {
        setupNodeRevokeControlCallerContract();

        $mock = fakeNodeRevokeControlGateway(
            [
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Peer identity unknown.',
                    'meta' => [],
                ],
            ],
            [
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'gateway-1',
                        'action' => 'revoked',
                        'already_absent' => false,
                        'self_lockout' => true,
                    ],
                ],
            ],
            identityStatus: 401,
        );

        \Pest\Laravel\artisan('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'gateway-1',
        ])
            ->doesntExpectOutput('Operation cancelled.')
            ->expectsOutputToContain('Peer identity unknown.')
            ->assertExitCode(1);

        $mock->assertSent(ShowGatewayIdentityRequest::class);
        $mock->assertNotSent(RevokeNodeRequest::class);
    });
});
