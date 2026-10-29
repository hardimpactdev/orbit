<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Operation;
use Tests\Support\FakeDatabaseServerAdmin;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(fn () => $this->prepareServerDatabaseFixtures());

it('creates and removes an Instance database synchronously with durable password-free ownership receipts', function (): void {
    $connection = app(CreateServerDatabaseAction::class)->createOnServer($this->server, 'retained', 'retained', $this->instance, null);
    expect($connection)->toBeInstanceOf(DatabaseConnection::class)
        ->and($connection->targets()->sole()->instance_id)->toBe($this->instance->id)
        ->and(app(DatabaseOwnership::class)->mysqlNames($connection))->toBe(['retained', 'retained_test'])
        ->and(Operation::query()->count())->toBe(0)
        ->and(DatabaseExecutionIntent::query()->count())->toBe(0);
    $receipts = Activity::query()->where('command', 'database:lifecycle:create')->get();
    expect($receipts)->toHaveCount(3)
        ->and($receipts->toJson())->not->toContain($connection->password, SERVER_DATABASE_ROOT_SECRET);

    app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

    $this->assertModelMissing($connection);
    expect($this->admin->sql())->toContain('DROP DATABASE IF EXISTS `retained`;', 'DROP DATABASE IF EXISTS `retained_test`;')
        ->and(Operation::query()->count())->toBe(0)
        ->and(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('does not accept a synchronous creation receipt for a changed recorded identity', function (): void {
    $connection = app(CreateServerDatabaseAction::class)->createOnServer($this->server, 'retained', 'retained', $this->instance, null);
    $connection->update(['username' => 'somebody_else']);
    $this->admin->statements = [];

    expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))->toThrow(ResourceOperationException::class, 'successful creation receipt');
    $this->assertModelExists($connection);
    expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP USER');
});

it('refuses retained removal of an unproven MySQL reservation without admitting any Operation', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'foreign', 'driver' => 'mysql', 'database_server_id' => $this->server->id,
        'database' => 'foreign', 'username' => 'reserved', 'owner_instance_id' => $this->instance->id]);

    expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))->toThrow(ResourceOperationException::class, 'successful creation receipt');
    $this->assertModelExists($connection);
    expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP USER')
        ->and(Operation::query()->count())->toBe(0);
});

it('holds the removal lock through synchronous effects and row deletion and rechecks newly shared logins', function (): void {
    $lock = new class implements DatabaseRemovalLock
    {
        public bool $held = false;

        public function run(array $serverIds, Closure $operation): mixed
        {
            expect($this->held)->toBeFalse();
            $this->held = true;
            try {
                return $operation();
            } finally {
                $this->held = false;
            }
        }
    };
    app()->instance(DatabaseRemovalLock::class, $lock);
    $admin = new RetainedDatabaseAdmin($this->admin);
    $admin->before = static function (string $sql) use ($lock): void {
        if (str_contains($sql, 'CREATE DATABASE') || str_contains($sql, 'DROP DATABASE')) {
            expect($lock->held)->toBeTrue();
        }
    };
    app()->instance(DatabaseServerAdmin::class, $admin);
    $connection = app(CreateServerDatabaseAction::class)->createOnServer($this->server, 'retained', 'retained', $this->instance, null);
    $admin->before = function (string $sql) use ($connection): void {
        if (str_contains($sql, 'SELECT DISTINCT blocker')) {
            DatabaseConnection::query()->firstOrCreate(['slug' => 'new-consumer'], ['driver' => 'mysql', 'database_server_id' => $this->server->id,
                'database' => 'another', 'username' => $connection->username]);
        }
    };
    $this->admin->statements = [];

    expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))->toThrow(ResourceOperationException::class, 'now belongs to another connection');
    $this->assertModelExists($connection);
    expect($this->admin->sql())->not->toContain('DROP USER', 'DROP DATABASE')
        ->and($lock->held)->toBeFalse();

    $admin->before = static function (string $sql) use ($lock): void {
        if (str_contains($sql, 'DROP DATABASE')) {
            expect($lock->held)->toBeTrue();
        }
    };
    DatabaseConnection::deleted(static function (DatabaseConnection $deleted) use ($connection, $lock): void {
        if ($deleted->id === $connection->id) {
            expect($lock->held)->toBeTrue();
        }
    });
    app(DropOwnedDatabasesAction::class)->execute($this->instance->id);
    $this->assertModelMissing($connection);
    expect($this->admin->sql())->toContain('DROP DATABASE')->not->toContain('DROP USER');
});

it('keeps an uncertain failed create unproven and refuses to drop a database that appeared during the failure', function (): void {
    $admin = new RetainedDatabaseAdmin($this->admin);
    $admin->before = function (string $sql): void {
        if (str_contains($sql, 'CREATE DATABASE')) {
            $this->admin->rows['SCHEMATA'] = ['restored'];
        }
    };
    $this->admin->failOn = 'CREATE DATABASE';
    app()->instance(DatabaseServerAdmin::class, $admin);
    expect(fn () => app(CreateServerDatabaseAction::class)->createOnServer($this->server, 'restored', 'restored', $this->instance, null))
        ->toThrow(ResourceOperationException::class);
    $connection = DatabaseConnection::query()->where('slug', 'restored')->sole();
    expect($connection->creation_receipts)->toBeNull();
    $this->admin->failOn = null;
    $this->admin->statements = [];

    expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
        ->toThrow(ResourceOperationException::class, 'successful creation receipt');
    $this->assertModelExists($connection);
    expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP USER');
});

it('preserves the original create error when the names-absent read fails', function (Throwable $readError): void {
    $original = new ResourceOperationException('database.create_refused', 'Original create failure.', 502);
    $failed = false;
    $readAttempts = 0;
    $admin = new RetainedDatabaseAdmin($this->admin);
    $admin->before = static function (string $sql) use ($original, $readError, &$failed, &$readAttempts): void {
        if (str_contains($sql, 'CREATE DATABASE')) {
            $failed = true;
            throw $original;
        }
        if ($failed && str_contains($sql, 'SCHEMATA')) {
            $readAttempts++;
            throw $readError;
        }
    };
    app()->instance(DatabaseServerAdmin::class, $admin);

    expect(fn () => app(CreateServerDatabaseAction::class)->createOnServer($this->server, 'uncertain', 'uncertain', $this->instance, null))
        ->toThrow(function (ResourceOperationException $caught) use ($original): void {
            expect($caught)->toBe($original);
        });

    expect($readAttempts)->toBe(1)
        ->and(DatabaseConnection::query()->where('slug', 'uncertain')->sole()->creation_receipts)->toBeNull()
        ->and(DatabaseExecutionIntent::query()->count())->toBe(0)
        ->and($this->admin->sql())->not->toContain('DROP DATABASE');
})->with([
    'remote refusal' => [new ResourceOperationException('database.server_command_failed', 'Ownership read failed.', 502)],
    'transport failure' => [new RuntimeException('Ownership transport failed.')],
]);

it('keeps the PostgreSQL fenced owner check and restores admission after a synchronous failure', function (): void {
    $this->server->update(['driver' => 'pgsql']);
    $connection = DatabaseConnection::query()->create(['slug' => 'foreign', 'driver' => 'pgsql', 'database_server_id' => $this->server->id,
        'database' => 'foreign', 'username' => 'reserved', 'owner_instance_id' => $this->instance->id]);
    $this->admin->failOn = 'ALLOW_CONNECTIONS false';

    expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($connection);
    expect($this->admin->sql())->toContain("RAISE EXCEPTION 'database.name_conflict'", "datname = 'foreign'", "rolname = 'reserved'", 'CASE WHEN item.allow_connections THEN')
        ->and(Operation::query()->count())->toBe(0)
        ->and(DatabaseExecutionIntent::query()->count())->toBe(0);
});

it('repairs a retained PostgreSQL test pool synchronously without nesting the server lock', function (): void {
    $this->server->update(['driver' => 'pgsql']);
    $action = app(CreateServerDatabaseAction::class);
    $connection = $action->createOnServer($this->server, 'pool', 'pool', $this->instance, null, testProcesses: 2);
    $this->admin->rows = ["WHERE rolname = '{$connection->username}'" => [$connection->username]];
    $this->admin->statements = [];

    $repaired = $action->createOnServer($this->server, 'pool', 'pool', $this->instance, null, testProcesses: 2);

    expect($repaired->id)->toBe($connection->id)
        ->and($this->admin->sql())->toContain('CREATE DATABASE "pool_test"', 'CREATE DATABASE "pool_test_test_2"', "RAISE EXCEPTION 'database.name_conflict'")
        ->not->toContain('DROP DATABASE')
        ->and(Operation::query()->count())->toBe(0);
});

final class RetainedDatabaseAdmin implements DatabaseServerAdmin
{
    public ?Closure $before = null;

    public function __construct(private FakeDatabaseServerAdmin $admin) {}

    public function waitUntilReady(DatabaseServer $server, int $timeoutSeconds): bool
    {
        return $this->admin->waitUntilReady($server, $timeoutSeconds);
    }

    public function execute(DatabaseServer $server, string $sql): array
    {
        ($this->before)?->__invoke($sql);

        return $this->admin->execute($server, $sql);
    }

    public function copyDatabase(DatabaseServer $server, string $source, string $target): void
    {
        $this->admin->copyDatabase($server, $source, $target);
    }
}
