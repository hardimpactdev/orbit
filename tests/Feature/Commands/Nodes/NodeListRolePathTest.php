<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeListRolePathRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeListGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeListRolePathRow([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

function setupNodeListAppCaller(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

function setupNodeListControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('node:list role paths', function (): void {

    it('forwards to gateway for control caller', function (): void {
        setupNodeListControlCaller();

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'gateway-1',
                                'role' => 'gateway',
                                'environment' => null,
                                'platform' => 'ubuntu_24-04',
                                'status' => 'active',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['nodes'])->toHaveCount(1)
            ->and($payload['success']['data']['nodes'][0]['name'])->toBe('gateway-1');
    });

    it('handles gateway forwarding error for control caller', function (): void {
        setupNodeListControlCaller();

        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });
});
