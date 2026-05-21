<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\UpdateNodeRequest;
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
function nodeUpdateControlContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '10.6.0.4',
        'wireguard_address' => '10.6.0.4',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => null,
        'platform' => 'ubuntu_24-04',
        'public_ipv4' => null,
        'public_ipv6' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeUpdateControlContractCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeUpdateControlContractRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeUpdateControlContractGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        UpdateNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:update on control node contract', function (): void {
    it('preserves gateway tld role rejection for gateway targets before local side effects', function (): void {
        setupNodeUpdateControlContractCaller();

        $error = [
            'code' => 'node.field_role_incompatible',
            'message' => "The field 'tld' is not valid for node 'gateway-1' (role: gateway).",
            'meta' => [
                'field' => 'tld',
                'name' => 'gateway-1',
                'role' => 'gateway',
            ],
        ];

        fakeNodeUpdateControlContractGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:update', [
            'name' => 'gateway-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error)
            ->and(DB::table('nodes')->where('name', 'gateway-1')->exists())->toBeFalse();
    });

    it('preserves gateway tld role rejection for control targets before local side effects', function (): void {
        setupNodeUpdateControlContractCaller();

        $error = [
            'code' => 'node.field_role_incompatible',
            'message' => "The field 'tld' is not valid for node 'control-2' (role: control).",
            'meta' => [
                'field' => 'tld',
                'name' => 'control-2',
                'role' => 'control',
            ],
        ];

        fakeNodeUpdateControlContractGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:update', [
            'name' => 'control-2',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error)
            ->and(DB::table('nodes')->where('name', 'control-2')->exists())->toBeFalse();
    });

    it('forwards successful development app tld updates and renders the success envelope', function (): void {
        setupNodeUpdateControlContractCaller();

        $mock = fakeNodeUpdateControlContractGateway([
            'success' => [
                'data' => [
                    'name' => 'app-1',
                    'changed' => ['tld'],
                    'action' => 'updated',
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'name' => 'app-1',
                'changed' => ['tld'],
                'action' => 'updated',
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse();

        $mock->assertSent(fn (UpdateNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/app-1'
            && $request->body()->all() === [
                'tld' => 'test',
            ]);
    });

    it('preserves forwarded structured gateway errors', function (array $error): void {
        setupNodeUpdateControlContractCaller();

        fakeNodeUpdateControlContractGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:update', [
            'name' => 'app-1',
            '--tld' => 'test',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    })->with([
        'authorization failure' => [[
            'code' => 'authorization_failed',
            'message' => "This node is not authorized for 'node:update' on 'app-1'.",
            'meta' => [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:update',
                'serving_node' => 'app-1',
            ],
        ]],
        'duplicate tld' => [[
            'code' => 'node.tld_in_use',
            'message' => "Development TLD 'test' is already assigned to another node.",
            'meta' => [
                'field' => 'tld',
                'value' => 'test',
            ],
        ]],
        'production tld rejection' => [[
            'code' => 'node.field_role_incompatible',
            'message' => "The field 'tld' is not valid for node 'app-1' (role: app).",
            'meta' => [
                'field' => 'tld',
                'name' => 'app-1',
                'role' => 'app',
            ],
        ]],
    ]);
});
