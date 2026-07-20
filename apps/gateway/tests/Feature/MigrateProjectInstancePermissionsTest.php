<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('adds project and instance permissions to both stored grant lists', function (): void {
    $consumer = Node::factory()->create(['name' => 'caller']);
    $serving = Node::factory()->create(['name' => 'app-1']);
    $grantId = DB::table('node_access')->insertGetId([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['app:read', 'node:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode(['app:register', 'app:mount'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    projectInstancePermissionMigration()->up();

    $grant = DB::table('node_access')->find($grantId);

    expect(json_decode($grant->permissions, true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['app:read', 'project:read', 'instance:read', 'node:read'])
        ->and(json_decode($grant->custom_permissions, true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['app:register', 'instance:register', 'app:mount', 'instance:mount']);
});

function projectInstancePermissionMigration(): object
{
    return require
        database_path(
            'migrations/2026_07_20_080355_add_project_instance_permissions_to_node_access_grants.php',
        );
}
