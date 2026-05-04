<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowRolePathRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeShowRolePathGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeShowRolePathRow([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupNodeShowRolePathAppCaller(): void
{
    DB::table('nodes')->insert(nodeShowRolePathRow([
        'name' => 'local-app',
        'role' => 'app',
        'environment' => 'development',
        'is_local' => true,
    ]));
}

function setupNodeShowRolePathControlCaller(): void
{
    DB::table('nodes')->insert(nodeShowRolePathRow([
        'name' => 'local-control',
        'role' => 'control',
        'environment' => null,
        'is_local' => true,
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

describe('node:show role paths', function (): void {
    it('uses local registry for gateway caller', function (): void {
        setupNodeShowRolePathGatewayCaller();
        DB::table('nodes')->insert(nodeShowRolePathRow(['name' => 'target-app']));

        $exitCode = Artisan::call('node:show', ['name' => 'target-app', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('target-app');
    });

    it('forwards to gateway for app caller', function (): void {
        setupNodeShowRolePathAppCaller();

        Http::fake([
            '*' => Http::response([
                'success' => [
                    'data' => [
                        'name' => 'visible-app',
                        'role' => 'app',
                        'status' => 'active',
                        'environment' => 'development',
                        'platform' => 'ubuntu_24-04',
                        'wireguard_address' => '10.6.0.7',
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'visible-app', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('visible-app');
    });

    it('forwards to gateway for control caller', function (): void {
        setupNodeShowRolePathControlCaller();

        Http::fake([
            '*' => Http::response([
                'success' => [
                    'data' => [
                        'name' => 'gateway-1',
                        'role' => 'gateway',
                        'status' => 'active',
                        'environment' => null,
                        'platform' => 'ubuntu_24-04',
                        'wireguard_address' => '10.6.0.2',
                        'addresses' => ['wireguard' => '10.6.0.2'],
                        'agent_ide' => ['adapter' => null, 'source' => 'default'],
                        'grants' => ['consuming_nodes' => [], 'serving_nodes' => []],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1');
    });

    it('handles gateway forwarding error for control caller', function (): void {
        setupNodeShowRolePathControlCaller();

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'some-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('handles gateway forwarding error for app caller', function (): void {
        setupNodeShowRolePathAppCaller();

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 'gateway_unavailable',
                    'message' => 'Gateway is unreachable.',
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'missing-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns node.not_found for gateway caller when target is missing', function (): void {
        setupNodeShowRolePathGatewayCaller();

        $exitCode = Artisan::call('node:show', ['name' => 'missing-node', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });
});
