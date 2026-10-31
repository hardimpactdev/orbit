<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Data\DatabaseConnections\CreateServerDatabaseData;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use Tests\Support\FakeAgentCommandRunner;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';
uses(ServerDatabaseFixtures::class);

it('forgets a reserved connection after a proven foreign-name refusal before any effect', function (string $driver): void {
    $this->prepareServerDatabaseFixtures();
    $this->server->update(['driver' => $driver]);
    $operation = app(CreateServerDatabaseAction::class)->execute(new CreateServerDatabaseData('unsafe', $this->server->slug, null), null);
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => 'unsafe']);
    app()->instance(AgentCommandRunner::class, $agents);
    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('database.create', 'proof', $this->gateway->id);
    $step = app(DatabaseOperationExecution::class)->context($operation)['descriptors'][0];

    $result = $executor->run($operation, ['worker' => 'proof', 'key' => $step['key'], 'transport' => $step['transport'], 'name' => $step['name'], 'input' => $step['input']], $this->gateway->id);
    $executor->complete($operation, ['worker' => 'proof', 'status' => 'failed', 'result' => $result, 'error_code' => 'database.name_conflict'], $this->gateway->id);

    expect(DatabaseConnection::query()->count())->toBe(0);
    expect($agents->calls)->toHaveCount(1);
    expect($agents->calls[0]['input']['sql'])->toStartWith('SELECT')->not->toContain('CREATE DATABASE');
})->with(['mysql', 'pgsql']);

it('refuses removal of a MySQL reservation without a successful per-name create receipt', function (): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'restored', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'restored', 'username' => 'reserved_user', 'owner_instance_id' => $this->instance->id]);

    expect(fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]))
        ->toThrow(ResourceOperationException::class, 'successful creation receipt');

    $this->assertModelExists($connection);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('records successful MySQL creation per name and keeps its proof after intent expiry', function (): void {
    $this->freezeTime();
    $this->prepareServerDatabaseFixtures();
    app(CreateServerDatabaseAction::class)->execute(new CreateServerDatabaseData('owned', $this->server->slug, null), null);
    app()->instance(AgentCommandRunner::class, new FakeAgentCommandRunner()->answer('database.server-admin', ['output' => '']));
    $executor = app(SwampOperationExecutor::class);
    $operation = $executor->claim('database.create', 'proof', $this->gateway->id);
    foreach (app(DatabaseOperationExecution::class)->context($operation)['descriptors'] as $step) {
        $result = $executor->run($operation, ['worker' => 'proof', 'key' => $step['key'], 'transport' => $step['transport'], 'name' => $step['name'], 'input' => $step['input']], $this->gateway->id);
        $executor->step($operation, ['worker' => 'proof', 'key' => $step['key'].'.finish', 'name' => $step['step'], 'status' => 'succeeded', 'result' => $result], $this->gateway->id);
    }
    $executor->complete($operation, ['worker' => 'proof', 'status' => 'succeeded', 'result' => $result], $this->gateway->id);
    $connection = DatabaseConnection::query()->sole();
    expect(app(DatabaseOwnership::class)->mysqlNames($connection))->toBe(['owned']);
    expect($connection->toArray())->not->toHaveKey('creation_receipts');
    $this->travel(25)->hours();
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(1);

    $drop = app(DropServerDatabaseAction::class)->prepareMany([$connection])[0];

    expect($drop->kind)->toBe('database.destroy');
    expect(DatabaseExecutionIntent::recorded($drop->input['intent_id'])->payload['commands']['database-apply']['transport'])->toBe('database_removal');
});

it('rechecks names on clone reservation reuse and resets only proven names', function (): void {
    $this->prepareServerDatabaseFixtures();
    $action = app(CreateServerDatabaseAction::class);
    $connection = $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null, ['clone_step' => 'recorded']);
    $first = $action->prepareEmpty($connection);
    $this->admin->rows = ['SCHEMATA' => ['clone_db']];

    expect(fn () => $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null))
        ->toThrow(ResourceOperationException::class, 'already has a database');
    expect(DatabaseExecutionIntent::recorded($first->input['intent_id'])->payload['commands']['create-database-0']['input']['sql'])
        ->not->toContain('DROP DATABASE');
    databaseCreatedProof($connection);
    $action->reserve($this->server, 'clone', 'clone_db', $this->instance, null);
    $reset = $action->prepareEmpty($connection);
    expect(DatabaseExecutionIntent::recorded($reset->input['intent_id'])->payload['commands']['create-database-0']['input']['sql'])
        ->toContain('DROP DATABASE IF EXISTS `clone_db`');
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

it('refuses a connection reservation while removal holds the database lock', function (): void {
    $this->prepareServerDatabaseFixtures();
    app(DatabaseRemovalLock::class)->run([$this->server->id], function (): void {
        expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'clone', 'clone_db', $this->instance, null))
            ->toThrow(ResourceOperationException::class, 'Another database removal');
    });
    expect(DatabaseConnection::query()->count())->toBe(0);
});

it('holds the removal lock through final dispatch so a new shared login reservation cannot interleave', function (): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned', 'username' => 'owned_user']);
    databaseCreatedProof($connection);
    app(DropServerDatabaseAction::class)->execute($connection);
    $agents = new FakeAgentCommandRunner()->answer('database.server-admin', function (): array {
        expect(fn () => app(CreateServerDatabaseAction::class)->reserve($this->server, 'interleaved', 'interleaved', $this->instance, null))
            ->toThrow(ResourceOperationException::class, 'Another database removal');

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
