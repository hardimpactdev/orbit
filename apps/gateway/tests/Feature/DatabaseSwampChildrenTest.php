<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseChildAdmission;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Instances\InstanceDatabaseIntent;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';

uses(ServerDatabaseFixtures::class);
beforeEach(fn () => $this->prepareServerDatabaseFixtures());

function databaseChildSource(Instance $target): Instance
{
    return Instance::query()->create(['project_id' => $target->project_id, 'node_id' => $target->node_id,
        'name' => 'source', 'environment' => 'development', 'checkout_path' => '/srv/orbit/dlf/source',
        'source_is_laravel' => true, 'provisioning_step' => 'active', 'status' => 'active']);
}

it('prepares an Instance-targeted create with protected intent and no execution', function (): void {
    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->create($this->instance, $this->server, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);
    $operation = new Operation(['kind' => $child['kind'], 'target_type' => 'instance', 'target_id' => $this->instance->id,
        'node_id' => $child['node_id'], 'input' => $child['input']]);

    expect($child['kind'])->toBe('database.create');
    expect($child['node_id'])->toBe($this->server->node_id);
    expect($child['input'])->toMatchArray(['owner_instance_id' => $this->instance->id, 'server_id' => $this->server->id]);
    expect($intent->target_type)->toBe('instance');
    expect(app(DatabaseIntentSource::class)->ownerId($operation))->toBe($intent->id);
    expect(json_encode($child))->not->toContain(SERVER_DATABASE_ROOT_SECRET, 'CREATE DATABASE', 'password', 'parent_operation_id');
    expect(DatabaseConnection::query()->sole()->owner_instance_id)->toBe($this->instance->id);
    expect(DatabaseConnectionTarget::query()->count())->toBe(0);
    expect(Operation::query()->where('kind', '!=', 'instance.create')->count())->toBe(0);
    expect($this->admin->statements)->toBe([]);
});

it('prepares a typed MySQL clone without create-shaped reset intent or Gateway probes', function (): void {
    $source = databaseChildSource($this->instance);
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'source_database', 'username' => 'source_user', 'password' => 'synthetic-source-secret']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);

    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);
    $commands = $intent->payload['commands'];

    expect($child['kind'])->toBe('database.mysql_clone');
    expect($intent->target_type)->toBe('instance');
    expect($intent->target_id)->toBe($this->instance->id);
    expect($commands)->toHaveCount(1);
    expect($commands['database-apply']['input'])->toMatchArray(['source_database' => 'source_database', 'driver' => 'mysql']);
    expect($commands['database-apply']['input'])->not->toHaveKeys(['sql', 'client', 'operation']);
    expect($child['input']['server_id'])->toBe($this->server->id);
    expect($this->admin->sql())->not->toContain('CREATE ', 'DROP ', 'GRANT ');
});

it('prepares SQLite clone paths and keeps source children bound to the target Instance', function (bool $crossNode): void {
    $source = databaseChildSource($this->instance);
    if ($crossNode) {
        $source->update(['node_id' => $this->gateway->id]);
    }
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'sqlite', 'node_id' => $source->node_id,
        'path' => $source->checkout_path.'/database/database.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);

    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);

    expect($child['node_id'])->toBe($this->instance->node_id);
    expect($child['input']['variant'])->toBe($crossNode ? 'sqlite_remote' : 'sqlite_local');
    expect($intent->target_type)->toBe('instance');
    expect($intent->target_id)->toBe($this->instance->id);
    expect(DatabaseConnection::query()->where('owner_instance_id', $this->instance->id)->sole()->path)
        ->toBe($this->instance->checkout_path.'/database/database.sqlite');
    $children = $intent->payload['projection']['children'];
    expect($children)->toHaveCount($crossNode ? 2 : 0);
    foreach ($children as $nested) {
        $nestedIntent = DatabaseExecutionIntent::query()->findOrFail($nested['input']['intent_id']);
        expect($nestedIntent->target_type)->toBe('instance');
        expect($nestedIntent->target_id)->toBe($this->instance->id);
        expect($nested['node_id'])->toBe($source->node_id);
    }
})->with([false, true]);

it('preflights only owned connections and retains all records until proven removal', function (): void {
    $owned = DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned_database', 'username' => 'shared_user', 'owner_instance_id' => $this->instance->id]);
    databaseCreatedProof($owned);
    $foreign = DatabaseConnection::query()->create(['slug' => 'foreign', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'foreign_database', 'username' => 'shared_user']);
    $local = DatabaseConnection::query()->create(['slug' => 'local', 'driver' => 'sqlite', 'node_id' => $this->instance->node_id,
        'path' => $this->instance->checkout_path.'/database.sqlite', 'owner_instance_id' => $this->instance->id]);

    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->destroyOwned($this->instance, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);
    $nested = $intent->payload['projection']['children'][0];
    $drop = DatabaseExecutionIntent::query()->findOrFail($nested['input']['intent_id']);
    $sql = $drop->payload['commands']['database-apply']['input']['sql'];

    expect($child['kind'])->toBe('database.destroy_owned');
    expect($child['input']['connection_ids'])->toBe([$owned->id, $local->id]);
    expect($nested['kind'])->toBe('database.destroy');
    expect($drop->target_type)->toBe('instance');
    expect($drop->target_id)->toBe($this->instance->id);
    expect($sql)->toContain('owned_database')->not->toContain('foreign_database', 'DROP USER');
    expect(DatabaseConnection::query()->count())->toBe(3);
    expect($intent->payload['projection']['delete_connections'])->toBe([$local->id]);
    $this->assertModelExists($foreign);
});

it('proves empty owned removal without requiring a fabricated database child', function (): void {
    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->destroyOwned($this->instance, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);

    expect($intent->payload['projection']['children'])->toBe([]);
    expect($intent->payload['commands']['database-apply']['name'])->toBe('database.owned-ready');
});

it('prepares development transfer SQLite seed with distinct placements for one Instance', function (): void {
    $source = new SqliteSeedPlacement($this->instance->id, 'development', $this->instance->checkout_path, 'orbit', $this->dbNode, 'parent-1');
    $target = new SqliteSeedPlacement($this->instance->id, 'development', '/srv/orbit/dlf/moved', 'orbit', $this->gateway, 'parent-1');
    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->sqliteSeed($source, $target, $source->basePath.'/database.sqlite', databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::query()->findOrFail($child['input']['intent_id']);

    expect($child['kind'])->toBe('database.sqlite_seed');
    expect($child['node_id'])->toBe($target->node->id);
    expect($intent->target_id)->toBe($this->instance->id);
    expect($intent->payload['projection']['children'])->toHaveCount(2);
    expect(Operation::query()->where('kind', '!=', 'instance.create')->count())->toBe(0);
});

it('rejects a SQLite clone outside the source checkout without recording intent', function (): void {
    $source = databaseChildSource($this->instance);
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'sqlite', 'node_id' => $source->node_id, 'path' => '/etc/foreign.sqlite']);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);

    expect(fn () => DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, databasePreparationParent($this->instance))))
        ->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('refuses to reset a clone whose enum checkpoint is already complete', function (string $driver): void {
    $source = databaseChildSource($this->instance);
    $connection = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => $driver, 'node_id' => $source->node_id,
        'database_server_id' => $driver === 'mysql' ? $this->server->id : null,
        'database' => $driver === 'mysql' ? 'source_database' : null,
        'path' => $driver === 'sqlite' ? $source->checkout_path.'/database.sqlite' : null,
        'username' => $driver === 'mysql' ? 'source_user' : null]);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $connection->id]);
    DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, databasePreparationParent($this->instance)));
    DatabaseConnection::query()->where('owner_instance_id', $this->instance->id)->sole()->update(['clone_step' => 'complete']);
    $intents = DatabaseExecutionIntent::query()->count();

    expect(fn () => DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, databasePreparationParent($this->instance))))
        ->toThrow(ResourceOperationException::class);
    expect(DatabaseExecutionIntent::query()->count())->toBe($intents);
})->with(['mysql', 'sqlite']);

it('admits stored removal children on their recorded Node under the owning Instance hold and resumes after wait', function (): void {
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        $this->markTestSkipped('Database execution awaits Kit B and I enforcement.');
    }
    $this->instance->update(['node_id' => $this->gateway->id]);
    $owned = DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned', 'username' => 'owned_user', 'owner_instance_id' => $this->instance->id]);
    databaseCreatedProof($owned);
    $descriptor = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->destroyOwned($this->instance, databasePreparationParent($this->instance)));
    $intent = DatabaseExecutionIntent::recorded($descriptor['input']['intent_id']);
    app(RecordDatabaseOperationAction::class)->start(new PreparedDatabaseOperation(
        $descriptor['kind'], $intent->target_type, $intent->target_id, $intent->node_id, $descriptor['input']));
    $executor = app(SwampOperationExecutor::class);
    $parent = $executor->claim('database.destroy_owned', 'parent', $this->gateway->id);
    DatabaseExecutionIntent::query()->update(['parent_operation_id' => $parent->id]);
    $key = $intent->payload['projection']['children'][0]['key'];
    $child = $executor->child($parent, ['worker' => 'parent', 'key' => $key], $this->gateway->id)['operation'];

    expect($child->target_type)->toBe('instance');
    expect($child->target_id)->toBe($this->instance->id);
    expect($child->node_id)->toBe($this->server->node_id)->not->toBe($parent->node_id);
    expect($child->parent_operation_id)->toBe($parent->id);
    expect($child->lock_key)->toBeNull();
    expect($executor->child($parent, ['worker' => 'parent', 'key' => $key], $this->gateway->id)['operation']->id)->toBe($child->id);
    $executor->wait($parent, 'parent', [$key], $this->gateway->id);
    expect($parent->fresh()->worker)->toBeNull();
    expect($parent->fresh()->lock_key)->not->toBeNull();

    app()->instance(AgentCommandRunner::class,
        new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => '']));
    foreach ([['database.destroy', 'child', $child->id], ['database.destroy_owned', 'resumed-parent', $parent->id]] as [$kind, $worker, $id]) {
        $operation = $executor->claim($kind, $worker, $this->gateway->id, $id);
        foreach (app(DatabaseOperationExecution::class)->context($operation)['descriptors'] as $step) {
            $result = $executor->run($operation, ['worker' => $worker, 'key' => $step['key'], 'transport' => $step['transport'], 'name' => $step['name'], 'input' => $step['input']], $this->gateway->id);
            $executor->step($operation, ['worker' => $worker, 'key' => $step['key'].'.finish', 'name' => $step['step'], 'status' => 'succeeded', 'result' => $result], $this->gateway->id);
        }
        $executor->complete($operation, ['worker' => $worker, 'status' => 'succeeded', 'result' => $result], $this->gateway->id);
    }
    expect(DatabaseConnection::query()->count())->toBe(0);
    expect($parent->fresh()->status->value)->toBe('succeeded');
    expect($parent->fresh()->lock_key)->toBeNull();
});

it('admits a MySQL clone child through direct start and projects its attachment and ownership', function (): void {
    $this->dbNode->accessibleNodes()->syncWithoutDetaching([$this->gateway->id]);
    config(['operation_executor.worker_node_ids' => [$this->dbNode->id]]);
    $source = databaseChildSource($this->instance);
    $original = DatabaseConnection::query()->create(['slug' => 'source', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'node_id' => $this->dbNode->id, 'host' => $this->dbNode->wireguard_ip, 'port' => $this->server->port,
        'database' => 'source_database', 'username' => 'source_user', 'password' => 'source-private', 'owner_instance_id' => $source->id]);
    DatabaseConnectionTarget::query()->create(['instance_id' => $source->id, 'prefix' => 'DB', 'database_connection_id' => $original->id]);
    $parent = databasePreparationParent($this->instance);
    $intent = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $source, $parent));
    $parent->update(['input' => ['database_clone_source_id' => $source->id], 'state' => ['lifecycle_database' => [...$intent, 'key' => 'database']]]);
    expect(app(InstanceDatabaseIntent::class)->child($parent, 'database')['kind'])->toBe('database.mysql_clone');
    $child = app(DatabaseChildAdmission::class)->admit($parent, $intent);
    expect($child->kind)->toBe('database.mysql_clone');
    expect($child->state)->toMatchArray(['database_mysql_clone_writer' => 'direct']);
    expect($child->attempts)->toBe(0);
    expect($child->worker)->toBeNull();
    expect($child->lock_key)->toBeNull();
    $runId = $child->state['swamp_run_id'];
    $path = '/api/v1/direct-operations/'.$child->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->dbNode->wireguard_ip]);
    $safe = $this->getJson($path.'?run_id='.$runId)->assertOk()->json('data.input');
    expect($safe)->toMatchArray(['source_database' => 'source_database', 'driver' => 'mysql'])->not->toHaveKeys(['sql', 'password', 'payload']);
    $this->getJson($path.'/private-material?run_id='.$runId)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->postJson($path.'/result', ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T03:00:00.000Z', 'finished_at' => '2026-10-31T03:00:01.000Z', 'result' => ['backend' => 'active']])->assertOk();
    $connection = DatabaseConnection::query()->where('owner_instance_id', $this->instance->id)->sole();
    expect($connection->clone_step->value)->toBe('complete');
    expect(DatabaseConnectionTarget::query()->where('instance_id', $this->instance->id)->sole()->database_connection_id)->toBe($connection->id);
    expect(app(DatabaseOwnership::class)->mysqlNames($connection))->toBe([$connection->database]);
    expect($original->fresh()->database)->toBe('source_database');
    expect($this->admin->statements)->toBe([]);
    $this->getJson($path.'/private-material?run_id='.$runId)->assertConflict();
});
