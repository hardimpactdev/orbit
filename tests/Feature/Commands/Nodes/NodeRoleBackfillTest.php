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
