<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\Node;
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
