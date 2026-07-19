<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('backfills a legacy analytics assignment with its sole PostgreSQL process identity', function (): void {
    $databaseNode = Node::factory()->create();
    $analyticsNode = Node::factory()->create();
    $postgres = Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'postgres',
            'runtime_config' => ['service' => 'postgres'],
        ]);
    $assignment = NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'settings' => [
            'postgres_node_id' => $databaseNode->id,
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);

    $migration = require
        database_path(
            'migrations/2026_07_19_030000_backfill_analytics_postgres_process_identity.php',
        );
    $migration->up();

    expect($assignment->fresh()?->settings)
        ->toMatchArray([
            'postgres_node_id' => $databaseNode->id,
            'postgres_process_id' => $postgres->id,
            'clickhouse_node_id' => $databaseNode->id,
        ]);
});

it('leaves an ambiguous legacy analytics assignment unresolved for a clear runtime failure', function (): void {
    $databaseNode = Node::factory()->create();
    $analyticsNode = Node::factory()->create();

    foreach (['postgres', 'postgres-food'] as $name) {
        Process::factory()
            ->forOwner($databaseNode)
            ->create([
                'name' => $name,
                'runtime_config' => ['service' => 'postgres'],
            ]);
    }

    $assignment = NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'settings' => [
            'postgres_node_id' => $databaseNode->id,
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);

    $migration = require
        database_path(
            'migrations/2026_07_19_030000_backfill_analytics_postgres_process_identity.php',
        );
    $migration->up();

    $storedSettings = DB::table('node_role')
        ->where('id', $assignment->id)
        ->value('settings');

    expect((string) $storedSettings)->not->toContain('postgres_process_id');
});
