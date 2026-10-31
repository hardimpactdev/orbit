<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareOwnedDatabaseDestructionAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(function (): void {
    $this->prepareServerDatabaseFixtures();
    $this->prepare = app(PrepareOwnedDatabaseDestructionAction::class);
    $this->owned = DatabaseConnection::query()->create(['slug' => 'owned-sqlite', 'driver' => 'sqlite',
        'node_id' => $this->instance->node_id, 'path' => $this->instance->checkout_path.'/database.sqlite',
        'owner_instance_id' => $this->instance->id, 'password' => 'not-in-public-intent']);
});

it('records one aggregate encrypted intent without starting another Operation', function (): void {
    $artifact = $this->prepare->execute($this->instance);
    expect($artifact['owner_instance_id'])->toBe($this->instance->id)
        ->and($artifact['connection_ids'])->toBe([$this->owned->id])
        ->and($artifact['servers'])->toBe([])
        ->and($artifact['sqlite'])->toBe([['connection_id' => $this->owned->id, 'node_id' => $this->instance->node_id,
            'checkout' => $this->instance->checkout_path, 'path' => $this->owned->path]])
        ->and(json_encode($artifact))->not->toContain('not-in-public-intent')
        ->and(Operation::query()->count())->toBe(0)->and(DatabaseExecutionIntent::query()->count())->toBe(1);
});

it('combines admitted owners on the same server and keeps its password encrypted', function (): void {
    $other = $this->instance->replicate();
    $other->name = 'other-database-owner';
    $other->checkout_path .= '-other';
    $other->save();
    foreach ([$this->instance, $other] as $owner) {
        DatabaseConnection::query()->create(['slug' => 'sql-'.$owner->id, 'driver' => 'mysql',
            'database_server_id' => $this->server->id, 'database' => 'sql_'.$owner->id, 'username' => 'owner_'.$owner->id,
            'owner_instance_id' => $owner->id]);
    }
    $artifact = $this->prepare->execute($this->instance, [$this->instance->id, $other->id]);
    $intent = DatabaseExecutionIntent::recorded($artifact['intent_id']);
    expect($artifact['connection_ids'])->toHaveCount(3)->and($artifact['servers'])->toHaveCount(1)
        ->and($artifact['servers'][0]['intent']['databases'])->toHaveCount(2)
        ->and($intent->payload['commands']['database-apply']['input']['participant_passwords'][$this->server->node_id][$this->server->id])->toBe($this->server->root_password)
        ->and(json_encode($artifact))->not->toContain($this->server->root_password);
});

it('refuses a database attached outside the admitted owners before recording material', function (): void {
    $other = $this->instance->replicate();
    $other->name = 'foreign-consumer';
    $other->checkout_path .= '-foreign';
    $other->save();
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $this->owned->id, 'instance_id' => $other->id, 'prefix' => 'DB']);
    expect(fn () => $this->prepare->execute($this->instance))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('database_execution_intents', 0);
});

it('refuses a foreign SQLite Node or unsafe path', function (string $change): void {
    $this->owned->update(match ($change) {
        'node' => ['node_id' => $this->gateway->id],
        'outside' => ['path' => $this->instance->checkout_path.'-other/database.sqlite'],
        'traversal' => ['path' => $this->instance->checkout_path.'/../database.sqlite'],
        'checkout' => ['path' => $this->instance->checkout_path],
    });
    expect(fn () => $this->prepare->execute($this->instance))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseCount('database_execution_intents', 0);
})->with(['node', 'outside', 'traversal', 'checkout']);
