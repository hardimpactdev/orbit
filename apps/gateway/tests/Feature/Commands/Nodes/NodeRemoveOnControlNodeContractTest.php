<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\RemoveNodeRequest;
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
function nodeRemoveControlContractRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'control-1',
        'role' => 'control',
        'host' => '10.6.0.9',
        'wireguard_address' => '10.6.0.9',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => null,
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeRemoveControlContractCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeRemoveControlContractRow());

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

describe('node:remove on operator node contract', function (): void {
    it('preserves forwarded gateway warnings in the rendered JSON response', function (): void {
        setupNodeRemoveControlContractCaller();

        MockClient::global([
            RemoveNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'name' => 'app-1',
                        'action' => 'removed',
                        'removed_self' => false,
                        'wireguard_peer_removed' => false,
                        'grants_removed' => 0,
                    ],
                    'meta' => [
                        'warnings' => [[
                            'code' => 'node.role_baseline_mismatch',
                            'message' => 'Development DNS mapping could not be removed: file delete error',
                            'family' => 'node',
                            'next_command' => 'doctor --family=node --restore',
                        ]],
                    ],
                ],
            ]),
        ]);

        $exitCode = Artisan::call('node:remove', [
            'name' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['warnings'])->toBe([[
                'code' => 'node.role_baseline_mismatch',
                'message' => 'Development DNS mapping could not be removed: file delete error',
                'family' => 'node',
                'next_command' => 'doctor --family=node --restore',
            ]]);
    });
});
