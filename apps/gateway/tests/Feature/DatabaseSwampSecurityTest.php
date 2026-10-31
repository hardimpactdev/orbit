<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Actions\DatabaseConnections\PrepareDatabaseChildAction;
use App\Actions\DatabaseServers\PurgeDatabaseExecutionIntentsAction;
use App\Actions\DatabaseServers\RecordDatabaseOperationAction;
use App\Domain\DatabaseServers\DatabaseChildAdmission;
use App\Domain\DatabaseServers\DatabaseIntentSource;
use App\Domain\DatabaseServers\DatabaseOperationCompletion;
use App\Domain\DatabaseServers\DatabaseOperationExecution;
use App\Domain\DatabaseServers\PreparedDatabaseOperation;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\DatabaseConnections\DatabaseSnapshotTransport;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseUser;
use App\Models\Operation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../Support/DatabaseCreationProofs.php';
uses(ServerDatabaseFixtures::class);

/** Records a failed attempt without bypassing the unavailable executor through a test-only kit. */
function failedDatabaseAttempt(PreparedDatabaseOperation $prepared): Operation
{
    return Operation::query()->create(['kind' => $prepared->kind, 'target_type' => $prepared->targetType,
        'target_id' => $prepared->targetId, 'node_id' => $prepared->nodeId, 'input' => $prepared->input,
        'status' => 'failed', 'finished_at' => now()]);
}

it('refuses a planned MySQL login that becomes shared before execution', function (bool $additionalUser): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'owned', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'owned', 'username' => 'a_user']);
    databaseCreatedProof($connection);
    $prepared = app(DropServerDatabaseAction::class)->prepareMany([$connection])[0];
    $operation = new Operation(['kind' => $prepared->kind, 'target_type' => $prepared->targetType,
        'target_id' => $prepared->targetId, 'node_id' => $prepared->nodeId, 'input' => $prepared->input]);
    $other = DatabaseConnection::query()->create(['slug' => 'new-database', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'new_database', 'username' => $additionalUser ? 'another_user' : 'a_user']);
    if ($additionalUser) {
        DatabaseUser::query()->create(['database_connection_id' => $other->id, 'username' => 'a_user', 'privileges' => 'SELECT']);
    }
    $this->admin->statements = [];
    $execution = app(DatabaseOperationExecution::class);
    $descriptor = $execution->context($operation)['descriptors'][0];

    expect(fn () => $execution->beforeRun($operation, ['worker' => 'proof', ...$descriptor]))
        ->toThrow(ResourceOperationException::class, 'now belongs to another connection');

    expect($this->admin->statements)->toBe([]);
    $this->assertModelExists($connection);
    $this->assertModelExists($other);
})->with([false, true]);

it('rejects a MySQL clone back into its source connection before preparing a reset', function (): void {
    $this->prepareServerDatabaseFixtures();
    $connection = DatabaseConnection::query()->create(['slug' => 'dlf-feature-x', 'driver' => 'mysql',
        'database_server_id' => $this->server->id, 'database' => 'dlf_feature_x', 'username' => 'owner', 'password' => 'fixture-password',
        'owner_instance_id' => $this->instance->id, 'clone_step' => 'recorded']);
    DatabaseConnectionTarget::query()->create(['database_connection_id' => $connection->id, 'instance_id' => $this->instance->id, 'prefix' => 'DB']);

    expect(fn () => DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->clone($this->instance, $this->instance, databasePreparationParent($this->instance))))
        ->toThrow(ResourceOperationException::class);

    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
    expect($this->admin->sql())->not->toContain('DROP ');
});

it('checks the snapshot Operation target before looking up Instances or transferring bytes', function (): void {
    $operation = new Operation(['kind' => 'database.sqlite_seed', 'target_type' => 'instance', 'target_id' => 1, 'node_id' => 2]);

    expect(fn () => app(DatabaseSnapshotTransport::class)->run($operation, ['name' => 'database.receive-snapshot'], ['target_instance_id' => 3]))
        ->toThrow(ResourceOperationException::class, 'snapshot target');
});

it('refuses a child whose Node or input differs from the protected admission snapshot', function (): void {
    $this->prepareServerDatabaseFixtures();
    $child = DB::transaction(fn () => app(PrepareDatabaseChildAction::class)->create($this->instance, $this->server, databasePreparationParent($this->instance)));
    $adapter = app(DatabaseChildAdmission::class);
    $parent = databasePreparationParent($this->instance);
    $foreign = $child;
    $foreign['node_id']++;
    expect(fn () => $adapter->admit($parent, $foreign))->toThrow(ResourceOperationException::class, 'recorded database intent');
    $foreign = $child;
    $foreign['input']['owner_instance_id']++;
    expect(fn () => $adapter->admit($parent, $foreign))->toThrow(ResourceOperationException::class, 'recorded database intent');
    expect(Operation::query()->where('kind', '!=', 'instance.create')->count())->toBe(0);
});

it('rejects a secret reference on a different target type even when numeric identities match', function (): void {
    $this->prepareServerDatabaseFixtures();
    $prepared = app(RecordDatabaseOperationAction::class)->prepare('database.create', 'database_server', $this->server->id, $this->server->node_id, [], []);
    $foreign = new Operation(['target_type' => 'project', 'target_id' => $this->server->id, 'node_id' => $this->server->node_id, 'input' => $prepared->input]);
    expect(app(DatabaseIntentSource::class)->ownerId($foreign))->toBeNull();
});

it('purges expired protected intents while retaining active and recent retries and public receipts', function (): void {
    $this->freezeTime();
    $this->prepareServerDatabaseFixtures();
    $records = app(RecordDatabaseOperationAction::class);
    $prepared = $records->prepare('database.create', 'database_server', $this->server->id, $this->server->node_id, [], []);
    $operation = failedDatabaseAttempt($prepared);
    $active = $records->prepare('database.create', 'database_server', $this->server->id, $this->server->node_id, [], []);
    $activeOperation = failedDatabaseAttempt($active);
    $recent = $records->prepare('database.create', 'database_server', $this->server->id, $this->server->node_id, [], []);
    $recentOperation = failedDatabaseAttempt($recent);
    $this->travel(25)->hours();
    $activeOperation->update(['status' => 'running', 'finished_at' => null]);
    $recentOperation->update(['finished_at' => now()->subHour()]);

    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(1);

    expect(DatabaseExecutionIntent::query()->pluck('id')->all())->toBe([$active->input['intent_id'], $recent->input['intent_id']]);
    $this->assertModelExists($operation);
    expect(collect(app(Schedule::class)->events())->contains(fn ($event) => $event->description === 'database-intents-purge'))->toBeTrue();
});

it('retains unadmitted nested intent until the parent retry window closes', function (): void {
    $this->freezeTime();
    $this->prepareServerDatabaseFixtures();
    $records = app(RecordDatabaseOperationAction::class);
    $nested = $records->prepare('database.sqlite_cleanup', 'instance', $this->instance->id, $this->server->node_id, [], []);
    $parent = $records->prepare('database.clone', 'instance', $this->instance->id, $this->server->node_id, [], [], ['children' => [$nested->child()]]);
    $operation = failedDatabaseAttempt($parent);
    $this->travel(25)->hours();
    $operation->update(['finished_at' => now()->subHour()]);

    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(0);
    expect(DatabaseExecutionIntent::query()->count())->toBe(2);

    $this->travel(24)->hours();
    expect(app(PurgeDatabaseExecutionIntentsAction::class)->execute())->toBe(2);
    $this->assertModelExists($operation);
});

it('does not project a failed Instance-targeted operation onto a server with the same numeric id', function (): void {
    $this->prepareServerDatabaseFixtures();
    $operation = new Operation(['kind' => 'database_server.create', 'target_type' => 'instance', 'target_id' => $this->server->id]);

    app(DatabaseOperationCompletion::class)->complete($operation,
        OperationOutcome::failed('database.server_command_failed'));

    expect($this->server->fresh()->error_code)->toBeNull();
    expect($this->server->fresh()->status->value)->toBe('active');
});
