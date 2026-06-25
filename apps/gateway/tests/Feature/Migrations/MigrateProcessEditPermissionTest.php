<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('rewrites stored process:edit node-access grants to process:update', function (): void {
    $migrationPath = database_path(
        'migrations/2026_06_25_120000_migrate_process_edit_permission_to_process_update.php',
    );

    expect(is_file($migrationPath))->toBeTrue();

    $consumer = Node::factory()->create(['name' => 'caller']);
    $serving = Node::factory()->create(['name' => 'app-1']);

    $grantId = DB::table('node_access')->insertGetId([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $serving->id,
        'permissions' => json_encode(['process:edit', 'process:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require $migrationPath;
    $migration->up();

    $grant = NodeAccess::query()->findOrFail($grantId);

    expect($grant->permissions)
        ->toBe(['process:update', 'process:read'])
        ->and($grant->permissions)
        ->not->toContain('process:edit');
});
