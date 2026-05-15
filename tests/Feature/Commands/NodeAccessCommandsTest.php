<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeAccessCommandRow(array $overrides = []): array
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

function setupNodeAccessGrantNodes(): array
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeAccessCommandRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));

    $consumerId = (int) DB::table('nodes')->insertGetId(nodeAccessCommandRow([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
        'wireguard_address' => '10.6.0.11',
    ]));

    $servingId = (int) DB::table('nodes')->insertGetId(nodeAccessCommandRow([
        'name' => 'app-1',
        'wireguard_address' => '10.6.0.12',
    ]));

    return [$consumerId, $servingId];
}

describe('node access grant integration', function (): void {
    it('creates a grant through the gateway command path', function (): void {
        [$consumerId, $servingId] = setupNodeAccessGrantNodes();

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
            ->and(DB::table('node_access')
                ->where('consumer_node_id', $consumerId)
                ->where('serving_node_id', $servingId)
                ->exists())->toBeTrue();
    });

    it('keeps repeated grants idempotent with stable state', function (): void {
        [$consumerId, $servingId] = setupNodeAccessGrantNodes();

        Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('granted')
            ->and($payload['success']['data']['already_granted'])->toBeTrue()
            ->and(DB::table('node_access')
                ->where('consumer_node_id', $consumerId)
                ->where('serving_node_id', $servingId)
                ->count())->toBe(1);
    });

    it('enforces the self-grant policy without writing access', function (): void {
        config(['orbit.is_gateway' => true]);

        DB::table('nodes')->insert(nodeAccessCommandRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        DB::table('nodes')->insert(nodeAccessCommandRow([
            'name' => 'control-1',
            'role' => 'control',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'control-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.grant_policy_violation')
            ->and($payload['error']['meta']['reason'])->toBe('self_grant')
            ->and(DB::table('node_access')->count())->toBe(0);
    });
});
