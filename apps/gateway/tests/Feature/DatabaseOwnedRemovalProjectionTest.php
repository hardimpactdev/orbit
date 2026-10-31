<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

it('records native removal outcomes without children and keeps the Instance and foreign attachments', function (string $status): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->accessibleNodes()->attach($this->gateway);
    $owned = DatabaseConnection::query()->create(['slug' => 'owned-sqlite', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id,
        'path' => $this->instance->checkout_path.'/database.sqlite', 'owner_instance_id' => $this->instance->id]);
    $foreign = DatabaseConnection::query()->create(['slug' => 'foreign-sqlite', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id,
        'path' => '/srv/foreign/database.sqlite']);
    foreach ([[$owned, 'DB'], [$foreign, 'FOREIGN']] as [$connection, $prefix]) {
        DatabaseConnectionTarget::query()->create(['database_connection_id' => $connection->id, 'instance_id' => $this->instance->id, 'prefix' => $prefix]);
    }
    $prepared = app(DropOwnedDatabasesAction::class)->prepare($this->instance);
    $this->assertDatabaseCount('operations', 0);
    $run = 'df53d68d-1f5d-415e-86f3-b282c1be9ca8';
    $operation = Operation::query()->create(['kind' => $prepared->kind, 'node_id' => $prepared->nodeId,
        'target_type' => 'instance', 'target_id' => $this->instance->id, 'status' => 'running',
        'input' => $prepared->input, 'state' => ['swamp_run_id' => $run]]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $callback = ['run_id' => $run, 'status' => $status, 'started_at' => '2026-10-31T00:00:00.000Z',
        'finished_at' => '2026-10-31T00:00:00.001Z', 'result' => $status === 'succeeded' ? ['backend' => 'absent'] : [],
        'error_code' => $status === 'failed' ? 'database.destroy_owned_outcome_unknown' : null];

    $path = '/api/v1/direct-operations/'.$operation->id.'/result';
    $this->postJson($path, $callback)->assertOk()->assertJsonPath('data.status', $status);
    $this->postJson($path, $callback)->assertOk()->assertJsonPath('data.status', $status);

    $this->assertDatabaseCount('operations', 1);
    $this->assertModelExists($this->instance);
    $this->assertModelExists($foreign);
    expect(DatabaseConnection::query()->whereKey($owned->id)->exists())->toBe($status !== 'succeeded');
    expect(DatabaseConnectionTarget::query()->where('database_connection_id', $owned->id)->exists())->toBe($status !== 'succeeded');
    $this->assertDatabaseHas('database_connection_targets', ['database_connection_id' => $foreign->id, 'instance_id' => $this->instance->id]);
})->with(['succeeded', 'failed', 'cancelled']);
