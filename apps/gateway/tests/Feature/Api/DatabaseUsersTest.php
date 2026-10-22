<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeDatabaseServerAdmin;

const DATABASE_USER_SECRET = 'db-user-secret-71ae';

beforeEach(function (): void {
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);

    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'tld' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->gateway = $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);

    $server = DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $this->gateway->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => 'root-secret-3c09',
        'status' => LifecycleStatus::Active,
    ]);
    DatabaseConnection::query()->create([
        'slug' => 'dlf-leden',
        'driver' => 'mysql',
        'node_id' => $this->gateway->id,
        'database_server_id' => $server->id,
        'host' => '10.44.0.1',
        'port' => 3306,
        'database' => 'dlf_leden',
        'username' => 'dlf_main',
        'password' => 'main-secret',
    ]);
});

describe('database:user:create', function (): void {
    it('adds a read-only user with SELECT on the database', function (): void {
        $response = $this->postJson('/api/v1/database-connections/dlf-leden/users', [
            'username' => 'reporting',
            'password' => DATABASE_USER_SECRET,
            'read_only' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.username', 'reporting')
            ->assertJsonPath('data.privileges', 'SELECT ON `dlf_leden`.*')
            ->assertJsonPath('data.created_by', 'gateway')
            ->assertJsonMissingPath('data.password');

        expect($this->admin->sql())->toBe(implode("\n", [
            "CREATE USER IF NOT EXISTS 'reporting'@'%' IDENTIFIED BY '".DATABASE_USER_SECRET."';",
            "ALTER USER 'reporting'@'%' IDENTIFIED BY '".DATABASE_USER_SECRET."';",
            "GRANT SELECT ON `dlf_leden`.* TO 'reporting'@'%';",
            "REVOKE ALL PRIVILEGES ON `dlf_leden`.* FROM 'reporting'@'%';",
            "GRANT SELECT ON `dlf_leden`.* TO 'reporting'@'%';",
        ])."\n")
            ->and($response->getContent())->not->toContain(DATABASE_USER_SECRET);

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($activity->command)->toBe('database:user:create')
            ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain(DATABASE_USER_SECRET);

        $user = DatabaseUser::query()->sole();
        $stored = DB::table('database_users')->where('id', $user->id)->value('password');

        expect($user->password)->toBe(DATABASE_USER_SECRET)
            ->and($stored)->toBeString()->not->toContain(DATABASE_USER_SECRET);

        $this->getJson('/api/v1/database-connections/dlf-leden/users')
            ->assertOk()
            ->assertJsonPath('data.0.username', 'reporting')
            ->assertJsonMissingPath('data.0.password');
        $this->getJson('/api/v1/database-connections/dlf-leden')
            ->assertOk()
            ->assertJsonPath('data.users_count', 1);
    });

    it('grants all privileges by default and replaces them on a repeat', function (): void {
        $this->postJson('/api/v1/database-connections/dlf-leden/users', [
            'username' => 'deploy',
            'password' => DATABASE_USER_SECRET,
        ])->assertCreated()->assertJsonPath('data.privileges', 'ALL PRIVILEGES ON `dlf_leden`.*');

        expect($this->admin->sql())->toEndWith("GRANT ALL PRIVILEGES ON `dlf_leden`.* TO 'deploy'@'%';\n");

        $this->postJson('/api/v1/database-connections/dlf-leden/users', [
            'username' => 'deploy',
            'password' => 'rotated-'.DATABASE_USER_SECRET,
            'read_only' => true,
        ])->assertOk()->assertJsonPath('data.privileges', 'SELECT ON `dlf_leden`.*');

        expect(DatabaseUser::query()->count())->toBe(1)
            ->and(DatabaseUser::query()->sole()->password)->toBe('rotated-'.DATABASE_USER_SECRET);
    });

    it('requires a connection on a server', function (): void {
        DatabaseConnection::query()->create([
            'slug' => 'external',
            'driver' => 'mysql',
            'host' => 'db.example.test',
            'database' => 'external',
            'username' => 'external',
            'password' => 'external-secret',
        ]);

        $this->postJson('/api/v1/database-connections/external/users', [
            'username' => 'reporting',
            'password' => DATABASE_USER_SECRET,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'database.server_required');

        expect($this->admin->statements)->toBe([]);
    });

    it('refuses the user of a connection, unsafe names, and unknown keys', function (): void {
        $this->postJson('/api/v1/database-connections/dlf-leden/users', [
            'username' => 'dlf_main',
            'password' => DATABASE_USER_SECRET,
        ])->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');

        foreach ([
            ['username' => 'bad-name', 'password' => DATABASE_USER_SECRET],
            ['username' => 'reporting', 'password' => "two\nlines"],
            ['username' => 'reporting', 'password' => DATABASE_USER_SECRET, 'read_only' => 'yes'],
            ['username' => 'reporting', 'password' => DATABASE_USER_SECRET, 'database' => 'other'],
        ] as $body) {
            $this->postJson('/api/v1/database-connections/dlf-leden/users', $body)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed');
        }

        expect($this->admin->statements)->toBe([]);
    });

    it('no longer creates users through a Process', function (): void {
        $this->postJson('/api/v1/processes/1/database-users', [
            'slug' => 'app',
            'database' => 'app',
            'username' => 'app',
            'password' => DATABASE_USER_SECRET,
        ])->assertNotFound();
    });
});

it('replaces PostgreSQL write grants with read-only grants including owner defaults', function (): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $connection->update(['driver' => 'pgsql']);
    $response = $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => 'reader', 'password' => DATABASE_USER_SECRET, 'read_only' => true]);
    $response->assertCreated();
    expect($this->admin->sql())->toContain('SCRAM-SHA-256$', 'GRANT CONNECT ON DATABASE "dlf_leden" TO "reader";', 'GRANT USAGE ON SCHEMA public TO "reader";', 'REVOKE ALL ON ALL TABLES', 'REVOKE ALL ON ALL SEQUENCES', 'FOR ROLE "dlf_main" IN SCHEMA public REVOKE ALL ON TABLES', 'FOR ROLE "dlf_main" IN SCHEMA public GRANT SELECT ON TABLES')
        ->not->toContain(DATABASE_USER_SECRET);
});

it('labels PostgreSQL write grants without claiming database ownership', function (): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $connection->update(['driver' => 'pgsql']);
    $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => 'writer', 'password' => DATABASE_USER_SECRET])->assertCreated()->assertJsonPath('data.privileges', 'DATA WRITE ON "dlf_leden"');
});

it('manages a PostgreSQL replication login with reader grants and replaces it on repeat', function (): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $connection->update(['driver' => 'pgsql']);
    $this->admin->rows['SHOW wal_level'] = ['logical'];
    $body = ['username' => 'sync_reader', 'password' => DATABASE_USER_SECRET, 'replication' => true];

    $response = $this->postJson('/api/v1/database-connections/dlf-leden/users', $body);

    $response->assertCreated()->assertJsonPath('data.replication', true)->assertJsonPath('data.privileges', 'CONNECT, USAGE, SELECT ON "dlf_leden"');
    expect($this->admin->sql())->toContain('LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE REPLICATION PASSWORD', 'GRANT SELECT ON ALL TABLES', 'FOR ROLE "dlf_main" IN SCHEMA public GRANT SELECT ON TABLES')->not->toContain(DATABASE_USER_SECRET, 'GRANT SELECT, INSERT');
    expect(DatabaseUser::query()->sole()->replication)->toBeTrue();
    expect($response->getContent())->not->toContain(DATABASE_USER_SECRET);
    $this->getJson('/api/v1/database-connections/dlf-leden/users')->assertOk()->assertJsonPath('data.0.replication', true)->assertJsonMissingPath('data.0.password');
    $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();
    expect(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))->not->toContain(DATABASE_USER_SECRET);

    $this->postJson('/api/v1/database-connections/dlf-leden/users', [...$body, 'password' => 'rotated-'.DATABASE_USER_SECRET, 'read_only' => true])->assertOk()->assertJsonPath('data.replication', true);
    expect(DatabaseUser::query()->count())->toBe(1);
    expect(DatabaseUser::query()->sole()->password)->toBe('rotated-'.DATABASE_USER_SECRET);

    $this->admin->statements = [];
    $this->postJson('/api/v1/database-connections/dlf-leden/users', [...$body, 'replication' => false, 'read_only' => true])->assertOk()->assertJsonPath('data.replication', false);
    expect($this->admin->sql())->toContain('NOREPLICATION PASSWORD')->not->toContain('SHOW wal_level');
    expect(DatabaseUser::query()->sole()->replication)->toBeFalse();
});

it('refuses replication on non-PostgreSQL drivers with 422 before admin commands', function (string $driver): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => $driver]);
    $connection->update(['driver' => $driver]);

    $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => 'sync_reader', 'password' => DATABASE_USER_SECRET, 'replication' => true])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.driver_unsupported');

    expect($this->admin->statements)->toBe([]);
    $this->assertDatabaseCount('database_users', 0);
})->with(['mysql', 'redis', 'sqlite']);

it('refuses replication without logical WAL with 422 before changing roles or records', function (array $walLevel): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $connection->update(['driver' => 'pgsql']);
    $this->admin->rows['SHOW wal_level'] = $walLevel;

    $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => 'sync_reader', 'password' => DATABASE_USER_SECRET, 'replication' => true])
        ->assertUnprocessable()->assertJsonPath('error.code', 'database.wal_level_not_logical');

    expect($this->admin->sql())->not->toContain('ALTER ROLE', 'GRANT');
    $this->assertDatabaseCount('database_users', 0);
})->with(['replica' => [['replica']], 'minimal' => [['minimal']], 'unverified' => [[]]]);

it('refuses replication for connection owners and unowned PostgreSQL roles with 409', function (string $username): void {
    $connection = DatabaseConnection::query()->sole();
    $connection->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $connection->update(['driver' => 'pgsql']);
    $this->admin->rows['SHOW wal_level'] = ['logical'];
    $this->admin->rows['SELECT rolname FROM pg_roles'] = ['other_role'];

    $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => $username, 'password' => DATABASE_USER_SECRET, 'replication' => true])
        ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');

    expect($this->admin->sql())->not->toContain('ALTER ROLE', 'GRANT');
    $this->assertDatabaseCount('database_users', 0);
})->with(['dlf_main', 'other_role']);

it('validates replication as a strict boolean with 422', function (): void {
    $this->postJson('/api/v1/database-connections/dlf-leden/users', ['username' => 'sync_reader', 'password' => DATABASE_USER_SECRET, 'replication' => 'true'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

    expect($this->admin->statements)->toBe([]);
});
