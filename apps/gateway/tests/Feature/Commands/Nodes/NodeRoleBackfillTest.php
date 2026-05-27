<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runNodeRoleBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_05_17_000001_backfill_node_roles_from_legacy_nodes.php');

    $migration->up();
}

function runGatewayCoupledVpnRoleBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php');

    $migration->up();
}

function rollbackGatewayCoupledVpnRoleBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_05_20_000000_backfill_gateway_coupled_vpn_roles.php');

    $migration->down();
}

it('backfills legacy node roles into node role assignments', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
        'tld' => null,
    ]);

    $control = Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'environment' => null,
        'tld' => null,
    ]);

    $appDevelopment = Node::factory()->create([
        'name' => 'app-dev-1',
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'orbit.test',
    ]);

    $appProduction = Node::factory()->create([
        'name' => 'app-prod-1',
        'role' => 'app',
        'environment' => 'production',
        'tld' => 'example.com',
    ]);

    runNodeRoleBackfillMigration();

    expect(NodeRoleAssignment::query()->count())->toBe(3);
    expect(DB::table('node_roles')->count())->toBe(3);

    expect(DB::table('node_roles')->where([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => json_encode([]),
    ])->exists())->toBeTrue();

    expect(DB::table('node_roles')->where('node_id', $control->id)->exists())->toBeFalse();

    expect(DB::table('node_roles')->where([
        'node_id' => $appDevelopment->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => json_encode(['tld' => 'orbit.test']),
    ])->exists())->toBeTrue();

    expect(DB::table('node_roles')->where([
        'node_id' => $appProduction->id,
        'role' => 'app-production',
        'status' => 'active',
        'settings' => json_encode([]),
    ])->exists())->toBeTrue();

    $freshGateway = $gateway->fresh();
    $freshAppDevelopment = $appDevelopment->fresh();
    $freshAppProduction = $appProduction->fresh();

    expect($freshGateway)->not->toBeNull();
    expect($freshAppDevelopment)->not->toBeNull();
    expect($freshAppProduction)->not->toBeNull();

    expect($freshGateway->role)->toBe('gateway');
    expect($freshGateway->environment)->toBeNull();
    expect($freshGateway->tld)->toBeNull();

    expect($freshAppDevelopment->role)->toBe('app');
    expect($freshAppDevelopment->environment)->toBe('development');
    expect($freshAppDevelopment->tld)->toBe('orbit.test');

    expect($freshAppProduction->role)->toBe('app');
    expect($freshAppProduction->environment)->toBe('production');
    expect($freshAppProduction->tld)->toBe('example.com');
});

it('is idempotent when matching role assignments already exist', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $appDevelopment = Node::factory()->create([
        'name' => 'app-dev-1',
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'orbit.test',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $appDevelopment->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'orbit.test'],
    ]);

    runNodeRoleBackfillMigration();
    runNodeRoleBackfillMigration();

    expect(NodeRoleAssignment::query()->count())->toBe(2);
    expect(DB::table('node_roles')->count())->toBe(2);
});

it('backfills vpn role assignments for active gateway role assignments', function (): void {
    $activeGateway = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'host' => 'gateway-1.internal',
        'gateway_endpoint' => 'gateway.example.com',
        'status' => 'active',
    ]);

    $inactiveGateway = Node::factory()->create([
        'name' => 'gateway-2',
        'role' => 'gateway',
        'host' => 'gateway-2.internal',
        'gateway_endpoint' => 'gateway-2.example.com',
        'status' => 'inactive',
    ]);

    $existingVpnGateway = Node::factory()->create([
        'name' => 'gateway-3',
        'role' => 'gateway',
        'host' => 'gateway-3.internal',
        'gateway_endpoint' => null,
        'status' => 'active',
    ]);

    $hostFallbackGateway = Node::factory()->create([
        'name' => 'gateway-4',
        'role' => 'gateway',
        'host' => 'gateway-4.internal',
        'gateway_endpoint' => null,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $activeGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $inactiveGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $existingVpnGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $existingVpnGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => 'existing.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $hostFallbackGateway->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    runGatewayCoupledVpnRoleBackfillMigration();

    expect(DB::table('node_roles')->where([
        'node_id' => $activeGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => json_encode([
            'public_endpoint' => 'gateway.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ], JSON_THROW_ON_ERROR),
    ])->exists())->toBeTrue();

    expect(DB::table('node_roles')->where([
        'node_id' => $inactiveGateway->id,
        'role' => 'vpn',
    ])->exists())->toBeFalse();

    expect(DB::table('node_roles')->where([
        'node_id' => $existingVpnGateway->id,
        'role' => 'vpn',
    ])->count())->toBe(1);

    expect(DB::table('node_roles')->where([
        'node_id' => $hostFallbackGateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => json_encode([
            'public_endpoint' => 'gateway-4.internal',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ], JSON_THROW_ON_ERROR),
    ])->exists())->toBeTrue();
});

it('preserves vpn role assignments on rollback', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $gateway->id,
        'role' => 'vpn',
        'status' => 'active',
        'settings' => [
            'public_endpoint' => 'gateway.example.com',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
    ]);

    rollbackGatewayCoupledVpnRoleBackfillMigration();

    expect(DB::table('node_roles')->where([
        'node_id' => $gateway->id,
        'role' => 'vpn',
    ])->exists())->toBeTrue();
});
