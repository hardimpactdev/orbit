<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Nodes\Access\NodePermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers singular process:log and workspace:run:log and rejects old tokens', function (): void {
    $registry = new NodePermissionRegistry;

    expect($registry->isKnown('process:log'))
        ->toBeTrue()
        ->and($registry->isKnown('workspace:run:log'))
        ->toBeTrue()
        ->and($registry->isKnown('process:logs'))
        ->toBeFalse()
        ->and($registry->isKnown('workspace:log'))
        ->toBeFalse()
        ->and($registry->impliedBy('process:read'))
        ->toContain('process:log')
        ->and($registry->impliedBy('process:read'))
        ->not->toContain('process:logs');
});

it('rewrites stored process:logs and lifecycle workspace:log grants fail-closed', function (): void {
    $consumer = Node::factory()->create(['name' => 'caller']);
    $serving = Node::factory()->create(['name' => 'app-1']);
    $grantId = DB::table('node_access')->insertGetId([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['process:logs', 'workspace:log', 'instance:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode(['process:logs'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    logPermissionTokenMigration()->up();

    $grant = DB::table('node_access')->find($grantId);
    $permissions = json_decode($grant->permissions, true, flags: JSON_THROW_ON_ERROR);
    $custom = json_decode($grant->custom_permissions, true, flags: JSON_THROW_ON_ERROR);

    expect($permissions)
        ->toContain('process:log')
        ->toContain('workspace:run:log')
        ->toContain('instance:read')
        ->not->toContain('process:logs')
        ->not->toContain('workspace:log')->and($custom)->toContain('process:log')
        ->not->toContain('process:logs');
});

function logPermissionTokenMigration(): object
{
    return require
        database_path(
            'migrations/2026_08_05_180000_rename_process_logs_and_workspace_log_permissions.php',
        );
}
