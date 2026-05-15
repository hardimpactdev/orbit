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
function nodeGrantControlContractRow(array $overrides = []): array
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

function setupNodeGrantControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeGrantControlContractRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
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
function fakeNodeGrantControlGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        GrantNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:grant on control node contract', function (): void {
    it('forwards configured control-node grants to the gateway without local target rows', function (): void {
        setupNodeGrantControlCaller();

        $mock = fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'granted',
                'already_granted' => false,
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse()
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/grant'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
            ]);
    });

    it('renders forwarded already-granted success with human output', function (): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => true,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("'control-1' already has access to 'app-1'");
    });

    it('preserves structured gateway errors when forwarding', function (array $error): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    })->with([
        'authorization failure' => [[
            'code' => 'authorization_failed',
            'message' => 'This control node is not authorized to grant node access.',
            'meta' => [
                'required_node' => 'gateway-1',
                'caller_role' => 'control',
            ],
        ]],
        'not found' => [[
            'code' => 'node.not_found',
            'message' => "Serving node 'app-1' not found.",
            'meta' => [
                'field' => 'serving_node',
                'name' => 'app-1',
            ],
        ]],
        'policy violation' => [[
            'code' => 'node.grant_policy_violation',
            'message' => 'A node cannot be granted access to itself.',
            'meta' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'control-1',
                'reason' => 'self_grant',
            ],
        ]],
    ]);
});
