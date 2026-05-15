<?php

declare(strict_types=1);

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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeGrantAppContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeGrantAppCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeGrantAppContractRow([
        'name' => 'app-caller',
        'role' => 'app',
        'host' => '10.6.0.99',
        'wireguard_address' => '10.6.0.99',
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeGrantAppGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        GrantNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:grant on app node contract', function (): void {
    it('forwards app-role CLI callers to the gateway instead of pre-rejecting locally', function (): void {
        setupNodeGrantAppCaller();

        $mock = fakeNodeGrantAppGateway([
            'error' => [
                'code' => 'caller_role_not_allowed',
                'message' => 'This command may only be run from a control or gateway node.',
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
            'message' => 'This command may only be run from a control or gateway node.',
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
                'message' => 'This command may only be run from a control or gateway node.',
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
            ->and(Artisan::output())->toContain('This command may only be run from a control or gateway node.')
            ->and(DB::table('node_access')->count())->toBe(0);
    });
});
