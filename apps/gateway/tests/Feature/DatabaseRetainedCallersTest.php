<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseOwnership;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseExecutionIntent;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Str;
use Tests\Support\FakeDatabaseServerAdmin;

require_once __DIR__.'/../Support/ServerDatabaseFixtures.php';
uses(ServerDatabaseFixtures::class);

beforeEach(fn () => $this->prepareServerDatabaseFixtures());

it('removes an Instance database using stored password-free lifecycle ownership receipts', function (): void {
    $connection = retainedCreatedDatabase($this->server, $this->instance);
    expect($connection)->toBeInstanceOf(DatabaseConnection::class)
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

it('refuses system names and prefixes before retained removal probes or effects', function (string $driver, string $database, ?string $prefix, string $entrypoint): void {
    $this->server->update(['driver' => $driver]);
    $connection = DatabaseConnection::query()->create(['slug' => 'unsafe-retained', 'driver' => $driver, 'database_server_id' => $this->server->id,
        'database' => $database, 'test_database' => $prefix, 'username' => 'unsafe_owner', 'owner_instance_id' => $this->instance->id]);
    $connection->forceFill(['ownership_adoption' => ['databases' => [$database], 'prefix' => $prefix,
        'username' => 'unsafe_owner', 'server_id' => $this->server->id, 'node_id' => $this->server->node_id]])->save();

    $remove = $entrypoint === 'synchronous'
        ? fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id)
        : fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]);
    expect($remove)->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('database.system_database');
    });

    $this->assertModelExists($connection);
    expect($this->admin->statements)->toBe([]);
    expect(Operation::query()->count())->toBe(0);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
})->with([
    ['mysql', 'mysql', null], ['mysql', 'sys', null], ['mysql', 'performance_schema', null], ['mysql', 'information_schema', null],
    ['mysql', 'MySQL', null], ['mysql', 'safe', 'm'], ['mysql', 'safe', 's'], ['mysql', 'safe', 'performance_'],
    ['mysql', 'safe', 'information_'], ['mysql', 'safe', ''], ['pgsql', 'postgres', null], ['pgsql', 'template0', null],
    ['pgsql', 'template_other', null], ['pgsql', 'Template1', null], ['pgsql', 'safe', 'p'], ['pgsql', 'safe', 'tem'],
])->with(['synchronous', 'admission']);

it('refuses an expanded system database before ownership probes or drop SQL', function (string $entrypoint): void {
    $connection = retainedCreatedDatabase($this->server, $this->instance);
    $this->admin->rows['SCHEMATA'] = ['mysql'];

    $remove = $entrypoint === 'synchronous'
        ? fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id)
        : fn () => app(DropServerDatabaseAction::class)->prepareMany([$connection]);
    expect($remove)->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('database.system_database');
    });

    $this->assertModelExists($connection);
    expect($this->admin->statements)->toHaveCount(1);
    expect($this->admin->sql())->toContain('SELECT SCHEMA_NAME')->not->toContain('DROP DATABASE', 'DROP USER', 'SELECT DISTINCT blocker');
    expect(Operation::query()->count())->toBe(0);
    expect(DatabaseExecutionIntent::query()->count())->toBe(0);
})->with(['synchronous', 'admission']);

it('does not accept a stored lifecycle creation receipt for a changed recorded identity', function (): void {
    $connection = retainedCreatedDatabase($this->server, $this->instance);
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
    app()->instance(DatabaseServerAdmin::class, $admin);
    $connection = retainedCreatedDatabase($this->server, $this->instance);
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

/** Arrange the stored legacy receipt that live removal still accepts. */
function retainedCreatedDatabase(DatabaseServer $server, Instance $instance): DatabaseConnection
{
    $connection = DatabaseConnection::query()->create(['slug' => 'retained', 'driver' => 'mysql',
        'database_server_id' => $server->id, 'node_id' => $server->node_id, 'database' => 'retained',
        'test_database' => 'retained_test', 'username' => 'retained', 'password' => 'stored-private-password',
        'owner_instance_id' => $instance->id]);
    $proofs = [];
    foreach ([['database' => 'retained'], ['database' => 'retained_test'], ['database' => 'retained_test', 'scope' => 'prefix']] as $name) {
        $proof = [...$name, 'username' => $connection->username, 'server_id' => $server->id];
        $activity = Activity::query()->create(['request_id' => (string) Str::uuid(), 'command' => 'database:lifecycle:create',
            'description' => 'Stored lifecycle creation proof.', 'subject_type' => DatabaseConnection::class,
            'subject_id' => $connection->id, 'status' => 'succeeded', 'exit_code' => 0,
            'properties' => ['connection_id' => $connection->id, 'node_id' => $server->node_id, 'receipt' => $proof]]);
        $proofs[] = [...$proof, 'activity_id' => $activity->id];
    }
    $connection->forceFill(['creation_receipts' => $proofs])->save();

    return $connection;
}

final class RetainedDatabaseAdmin implements DatabaseServerAdmin
{
    public ?Closure $before = null;

    public function __construct(private readonly FakeDatabaseServerAdmin $admin) {}

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
