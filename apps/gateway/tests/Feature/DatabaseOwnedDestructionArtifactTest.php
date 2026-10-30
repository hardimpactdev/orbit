<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Actions\DatabaseConnections\ProjectOwnedDatabaseDestructionAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';

uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->dbNode->update(['architecture' => 'amd64']);
    $this->prepare = app(PrepareOwnedDatabaseDestructionAction::class);
    $this->project = app(ProjectOwnedDatabaseDestructionAction::class);
    $this->owned = DatabaseConnection::query()->create(['slug' => 'owned-sqlite', 'driver' => 'sqlite',
        'node_id' => $this->instance->node_id, 'path' => $this->instance->checkout_path.'/database.sqlite',
        'owner_instance_id' => $this->instance->id, 'password' => 'not-in-artifact']);
});

function ownedDestructionResult(array $artifact): array
{
    return ['artifact_id' => $artifact['artifact_id'], 'owner_instance_id' => $artifact['owner_instance_id'],
        'node_id' => $artifact['node_id'], 'removed_connection_ids' => array_column($artifact['connections'], 'connection_id'), 'backend' => 'absent'];
}

it('records only public owned local intent without any Gateway execution records or Node reads', function (): void {
    $foreign = DatabaseConnection::query()->create(['slug' => 'attached-foreign', 'driver' => 'sqlite', 'path' => '/foreign/db.sqlite']);
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $foreign->id, 'instance_id' => $this->instance->id, 'prefix' => 'DB']);
    $external = DatabaseConnection::query()->create(['slug' => 'owned-external', 'driver' => 'mysql', 'database' => 'external_db',
        'username' => 'external', 'password' => 'private-external', 'owner_instance_id' => $this->instance->id]);
    $artifact = $this->prepare->execute($this->instance);
    expect(array_keys($artifact))->toBe(['version', 'artifact_id', 'owner_instance_id', 'node_id', 'checkout', 'agent_sha256', 'connections'])
        ->and($artifact['connections'])->toBe([
            ['connection_id' => $this->owned->id, 'slug' => 'owned-sqlite', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id, 'path' => $this->owned->path],
            ['connection_id' => $external->id, 'slug' => 'owned-external', 'driver' => 'mysql', 'node_id' => null, 'path' => null],
        ])->and(json_encode($artifact))->not->toContain('not-in-artifact', 'private-external', 'external_db', 'external"')
        ->and(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0)
        ->and($this->admin->statements)->toBe([]);
    $this->assertModelExists($this->owned);
});

it('projects the complete native result before Instance deletion and tolerates replay', function (): void {
    $foreign = DatabaseConnection::query()->create(['slug' => 'foreign', 'driver' => 'sqlite', 'path' => '/foreign/db.sqlite']);
    foreach ([$this->owned, $foreign] as $index => $connection) {
        DatabaseConnectionTarget::query()->create(['database_connection_id' => $connection->id, 'instance_id' => $this->instance->id, 'prefix' => $index === 0 ? 'DB' : 'OTHER']);
    }
    $artifact = $this->prepare->execute($this->instance);
    $this->project->execute($this->instance, $artifact, ownedDestructionResult($artifact));
    $this->project->execute($this->instance, $artifact, ownedDestructionResult($artifact));
    $this->assertModelMissing($this->owned);
    $this->assertModelExists($foreign);
    $this->assertModelExists($this->instance);
    expect(DatabaseConnectionTarget::query()->pluck('database_connection_id')->all())->toBe([$foreign->id]);
});

it('supports empty ownership without an execution record', function (): void {
    $this->owned->delete();
    $artifact = $this->prepare->execute($this->instance);
    expect($artifact['connections'])->toBe([]);
    $this->project->execute($this->instance, $artifact, ownedDestructionResult($artifact));
    expect(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('refuses managed databases on either Node without querying live grants or preparing SQL', function (bool $sameNode): void {
    if (! $sameNode) {
        $this->server->update(['node_id' => $this->gateway->id]);
    }
    $this->owned->update(['driver' => 'mysql', 'database_server_id' => $this->server->id, 'path' => null, 'database' => 'foreign_db']);
    expect(fn () => $this->prepare->execute($this->instance))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.native_removal_unavailable'));
    expect($this->admin->statements)->toBe([])->and(DatabaseExecutionIntent::query()->count())->toBe(0);
    $this->assertModelExists($this->owned);
})->with([true, false]);

it('refuses a foreign SQLite Node or unsafe path', function (string $change): void {
    $this->owned->update(match ($change) {
        'node' => ['node_id' => $this->gateway->id],
        'outside' => ['path' => $this->instance->checkout_path.'-other/database.sqlite'],
        'traversal' => ['path' => $this->instance->checkout_path.'/../database.sqlite'],
        'checkout' => ['path' => $this->instance->checkout_path],
    });
    expect(fn () => $this->prepare->execute($this->instance))->toThrow(ResourceOperationException::class);
})->with(['node', 'outside', 'traversal', 'checkout']);

it('refuses incomplete or altered native results without deleting a row', function (string $field): void {
    $artifact = $this->prepare->execute($this->instance);
    $result = ownedDestructionResult($artifact);
    $result[$field] = match ($field) {
        'artifact_id' => str_repeat('0', 64), 'backend' => 'active', 'removed_connection_ids' => [],
        default => -1,
    };
    expect(fn () => $this->project->execute($this->instance, $artifact, $result))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($this->owned);
})->with(['artifact_id', 'node_id', 'owner_instance_id', 'backend', 'removed_connection_ids', 'extra']);

it('refuses changed placement or ownership as a set without deleting unchanged rows', function (string $change): void {
    $artifact = $this->prepare->execute($this->instance);
    match ($change) {
        'checkout' => $this->instance->update(['checkout_path' => '/srv/changed']),
        'node' => $this->instance->update(['node_id' => $this->gateway->id]),
        'path' => $this->owned->update(['path' => $this->instance->checkout_path.'/new.sqlite']),
        'owner' => $this->owned->update(['owner_instance_id' => null]),
        'server' => $this->owned->update(['database_server_id' => $this->server->id]),
        'added' => DatabaseConnection::query()->create(['slug' => 'new-owned', 'driver' => 'mysql', 'owner_instance_id' => $this->instance->id]),
    };
    expect(fn () => $this->project->execute($this->instance, $artifact, ownedDestructionResult($artifact)))
        ->toThrow(ResourceOperationException::class);
    $this->assertModelExists($this->owned);
})->with(['checkout', 'node', 'path', 'owner', 'server', 'added']);
