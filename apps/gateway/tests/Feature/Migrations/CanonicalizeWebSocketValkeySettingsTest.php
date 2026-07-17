<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function runWebSocketValkeySettingsMigration(): void
{
    $migration = require base_path('database/migrations/2026_07_17_000000_canonicalize_websocket_valkey_settings.php');

    $migration->up();
}

it('canonicalizes legacy websocket Redis settings to Valkey settings', function (): void {
    $node = Node::factory()->create();
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => 42],
    ]);

    runWebSocketValkeySettingsMigration();

    expect($assignment->fresh()->settings)
        ->toBe(['valkey_node_id' => 42]);
});

it('rejects conflicting legacy and canonical websocket broker settings', function (): void {
    $node = Node::factory()->create();
    $assignment = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'websocket',
        'settings' => ['redis_node_id' => 42],
    ]);

    DB::table('node_role')
        ->where('id', $assignment->id)
        ->update([
            'settings' => json_encode([
                'redis_node_id' => 42,
                'valkey_node_id' => 43,
            ], JSON_THROW_ON_ERROR),
        ]);

    expect(runWebSocketValkeySettingsMigration(...))
        ->toThrow(
            RuntimeException::class,
            "Websocket role assignment [{$assignment->id}] has conflicting Redis and Valkey node settings.",
        );
});
