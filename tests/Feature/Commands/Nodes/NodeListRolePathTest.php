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
function nodeListRolePathRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeListGatewayCaller(): void
{
    DB::table('nodes')->insert(nodeListRolePathRow([
        'name' => 'local-gateway',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupNodeListAppCaller(): void
{
    DB::table('nodes')->insert(nodeListRolePathRow([
        'name' => 'local-app',
        'role' => 'app',
        'environment' => 'development',
        'is_local' => true,
    ]));
}

function setupNodeListControlCaller(): void
{
    DB::table('nodes')->insert(nodeListRolePathRow([
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

describe('node:list role paths', function (): void {
    it('uses local registry for gateway caller', function (): void {
        setupNodeListGatewayCaller();
        DB::table('nodes')->insert(nodeListRolePathRow(['name' => 'target-app']));

        $exitCode = Artisan::call('node:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($exitCode)->toBe(0)
            ->and($names)->toContain('target-app')
            ->and($names)->toContain('local-gateway');
    });

    it('forwards to gateway for app caller', function (): void {
        setupNodeListAppCaller();

        Http::fake([
            '*' => Http::response([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'visible-app',
                                'role' => 'app',
                                'environment' => 'development',
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
            ->and($payload['success']['data']['nodes'][0]['name'])->toBe('visible-app');
    });

    it('forwards to gateway for control caller', function (): void {
        setupNodeListControlCaller();

        Http::fake([
            '*' => Http::response([
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

        Http::fake([
            '*' => Http::response([
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

    it('forwards filters for app caller', function (): void {
        setupNodeListAppCaller();

        Http::fake([
            '*' => Http::response([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'dev-app',
                                'role' => 'app',
                                'environment' => 'development',
                                'platform' => 'ubuntu_24-04',
                                'status' => 'active',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true, '--environment' => 'development']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['nodes'])->toHaveCount(1)
            ->and($payload['success']['data']['nodes'][0]['name'])->toBe('dev-app');
    });

    it('filters locally for gateway caller', function (): void {
        setupNodeListGatewayCaller();
        DB::table('nodes')->insert([
            nodeListRolePathRow(['name' => 'gateway-2', 'role' => 'gateway', 'environment' => null]),
            nodeListRolePathRow(['name' => 'app-1', 'role' => 'app']),
        ]);

        $exitCode = Artisan::call('node:list', ['--json' => true, '--role' => 'gateway']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $names = array_column($payload['success']['data']['nodes'], 'name');

        expect($exitCode)->toBe(0)
            ->and($names)->toHaveCount(2)
            ->and($names)->toContain('gateway-2')
            ->and($names)->toContain('local-gateway');
    });
});
