<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

function setupNodeGrantAppCaller(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function nodeGrantAppIdentityEnvelope(string $role = 'app'): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'app-caller',
                    'role' => $role,
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.99'],
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
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeGrantAppGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(nodeGrantAppIdentityEnvelope('app'), 200),
        GrantNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:grant on app node contract', function (): void {
    it('forwards app-role CLI callers to the gateway instead of pre-rejecting locally', function (): void {
        setupNodeGrantAppCaller();

        $mock = fakeNodeGrantAppGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from an operator or gateway node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/grant'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
            ]);
    });

    it('preserves gateway caller_role_not_allowed JSON errors for app-role CLI callers', function (): void {
        setupNodeGrantAppCaller();

        $error = [
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from an operator or gateway node.',
            'meta' => [
                'caller_role' => 'app',
            ],
        ];

        fakeNodeGrantAppGateway(['error' => $error], 403);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error)
            ->and(DB::table('node_access')->count())->toBe(0);
    });

    it('preserves gateway caller_role_not_allowed human errors for app-role CLI callers', function (): void {
        setupNodeGrantAppCaller();

        fakeNodeGrantAppGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from an operator or gateway node.',
                'meta' => [
                    'caller_role' => 'app',
                ],
            ],
        ], 403);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('This command may only be run from an operator or gateway node.')
            ->and(DB::table('node_access')->count())->toBe(0);
    });

    it('forwards supplied preset permissions and force options for app-role CLI callers', function (): void {
        setupNodeGrantAppCaller();

        $mock = fakeNodeGrantAppGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                    'permissions' => ['app:read', 'node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--force' => true,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->body()->all() === [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'preset' => 'operator',
            'force' => true,
        ]);
    });

    it('falls back to control behavior when gateway identity is unavailable', function (): void {
        setupNodeGrantAppCaller();

        MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make('', 500),
            GrantNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'consuming_node' => 'control-1',
                        'serving_node' => 'app-1',
                        'action' => 'granted',
                        'already_granted' => false,
                        'permissions' => ['app:read'],
                    ],
                ],
            ]),
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['permissions'])->toBe(['app:read']);
    });
});
