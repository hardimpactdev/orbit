<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\DatabaseServers\NativeDatabaseRemovalLock;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';
uses(ServerDatabaseFixtures::class);

it('refuses removal of a MySQL reservation without a successful per-name create receipt', function (): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'restored', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'restored', 'username' => 'reserved_user', 'owner_instance_id' => $this->instance->id]);

    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))
        ->toThrow(ResourceOperationException::class, 'successful creation receipt');

    $this->assertModelExists($connection);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('reuses clone reservation credentials without Gateway inventory or a reset plan', function (): void {
    $this->prepareServerDatabaseFixtures();
    $action = app(CreateServerDatabaseAction::class);
    $connection = $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null, ['clone_step' => 'recorded']);
    $this->admin->rows = ['SCHEMATA' => ['clone_db']];
    $same = $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null);
    expect($same->id)->toBe($connection->id);
    expect($same->password)->toBe($connection->password);
    expect($this->admin->statements)->toBe([]);
    $this->assertDatabaseCount('database_execution_intents', 0);
});

it('retains lifecycle intents and nested children beyond 24 hours while their recorded parent is active', function (): void {
    $this->freezeTime();
    $this->prepareServerDatabaseFixtures();
    $parent = databasePreparationParent($this->instance);
    $prepared = app(PrepareDatabaseChildAction::class)->create($this->instance, $this->server, $parent);
    $this->travel(49)->hours();

    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(0);
    expect(DatabaseExecutionIntent::recorded($prepared['input']['intent_id'])->parent_operation_id)->toBe($parent->id);
    $parent->update(['status' => 'failed', 'finished_at' => now()]);
    $this->travel(25)->hours();
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(1);
});

it('refuses clone reservation while a recorded server removal is unfinished', function (): void {
    $this->prepareServerDatabaseFixtures();
    Operation::query()->create(['kind' => 'database.destroy', 'target_type' => 'database_server', 'target_id' => $this->server->id, 'node_id' => $this->server->node_id, 'status' => 'running', 'input' => []]);
    expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'clone', 'clone_db', $this->instance, null))
        ->toThrow(ResourceOperationException::class, 'removal is unfinished');
    $this->assertDatabaseCount('database_connections', 0);
});

it('refuses new shared login reservation while a removal intent is dispatched', function (): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned', 'username' => 'owned_user']);
    databaseCreatedProof($connection);
    app(DropServerDatabaseAction::class)->execute($connection);
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', function (): array {
        expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'interleaved', 'interleaved', $this->instance, null))
            ->toThrow(ResourceOperationException::class, 'removal is unfinished');

        return ['output' => ''];
    });
    app()->instance(AgentCommandRunner::class, $agents);
    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('database.destroy', 'proof', $this->gateway->id);
    $step = app(DatabaseOperationExecution::class)->context($operation)['descriptors'][0];

    $result = $executor->run($operation, ['worker' => 'proof', 'key' => $step['key'], 'transport' => $step['transport'], 'name' => $step['name'], 'input' => $step['input']], $this->gateway->id);

    expect($result)->toBe(['backend' => 'absent']);
    expect(DatabaseConnection::query()->pluck('slug')->all())->toBe(['owned']);
});

it('retains both source and target SQLite intent while an unadmitted lifecycle parent waits', function (): void {
    $this->freezeTime();
    $this->prepareServerDatabaseFixtures();
    $parent = databasePreparationParent($this->instance);
    $source = new SqliteSeedPlacement($this->instance->id, 'development', '/srv/orbit/source', 'orbit', $this->dbNode, 'attempt-1');
    $target = new SqliteSeedPlacement($this->instance->id, 'development', '/srv/orbit/target', 'orbit', $this->gateway, 'attempt-1');
    app(PrepareDatabaseChildAction::class)->sqliteSeed($source, $target, '/srv/orbit/source/database.sqlite', $parent);
    $this->travel(49)->hours();

    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(0);
    expect(DatabaseExecutionIntent::query()->count())->toBe(3);
    expect(DatabaseExecutionIntent::query()->where('parent_operation_id', $parent->id)->count())->toBe(3);
});

it('refuses clone reservation while synchronous removal owns the server lock and retries after release', function (): void {
    $this->prepareServerDatabaseFixtures();
    $directory = config()->string('orbit.home').'/locks/reservation-'.bin2hex(random_bytes(4));
    $owner = new NativeDatabaseRemovalLock($directory);
    app()->instance(DatabaseRemovalLock::class, new NativeDatabaseRemovalLock($directory));
    $action = app(CreateServerDatabaseAction::class);
    $owner->run([$this->server->id], function () use ($action): void {
        expect(fn () => $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('database.server_in_use'));
        $this->assertDatabaseCount('database_connections', 0);
        $this->assertDatabaseCount('database_users', 0);
    });
    $connection = $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null);
    expect($connection->database)->toBe('clone_db');
    $this->assertDatabaseCount('database_connections', 1);
    $this->assertDatabaseCount('database_users', 1);
    expect($this->admin->statements)->toBe([]);
});
