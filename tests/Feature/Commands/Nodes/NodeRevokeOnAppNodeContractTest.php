<?php

declare(strict_types=1);

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
function nodeRevokeAppContractRow(array $overrides = []): array
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

function setupNodeRevokeAppCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRevokeAppContractRow([
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
function fakeNodeRevokeAppGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        RevokeNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:revoke on app node contract', function (): void {
    it('forwards app-role CLI callers to the gateway and preserves structured gateway errors', function (): void {
        setupNodeRevokeAppCaller();

        $error = [
            'code' => 'caller_role_not_allowed',
            'message' => 'This command may only be run from a control or gateway node.',
            'meta' => [
                'caller_role' => 'app',
            ],
        ];

        $mock = fakeNodeRevokeAppGateway(['error' => $error], 403);

        $exitCode = Artisan::call('node:revoke', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error)
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (RevokeNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/revoke'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'force' => true,
            ]);
    });
});
