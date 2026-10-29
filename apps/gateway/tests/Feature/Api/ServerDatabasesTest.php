<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;

require_once __DIR__.'/../../Support/ServerDatabaseFixtures.php';
require_once __DIR__.'/../../Support/DatabaseCreationProofs.php';

uses(ServerDatabaseFixtures::class);

beforeEach(fn () => $this->prepareServerDatabaseFixtures());

describe('database:create --server', function (): void {

    it('refuses a database or user name that the server already has', function (): void {
        $this->admin->rows = ["SCHEMA_NAME = 'dlf_leden'" => ['dlf_leden']];

        $this->postJson('/api/v1/database-connections', ['slug' => 'dlf-leden', 'server' => 'beast-mysql'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');

        $this->admin->rows = ["WHERE User = 'dlf_leden'" => ['dlf_leden']];

        $this->postJson('/api/v1/database-connections', ['slug' => 'dlf-leden', 'server' => 'beast-mysql'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');

        expect(DatabaseConnection::query()->exists())->toBeFalse()
            ->and($this->admin->sql())->not->toContain('CREATE DATABASE');
    });

    it('refuses a database whose name falls under another database\'s test databases', function (): void {
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'shop',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertAccepted();

        $this->postJson('/api/v1/database-connections', ['slug' => 'shop-testimonials', 'server' => 'beast-mysql'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.name_conflict');
    });

    it('refuses a missing server, an existing slug, and fields that a server derives', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => 'missing'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'database.server_missing');

        DatabaseConnection::query()->create(['slug' => 'app', 'driver' => 'sqlite', 'path' => '/tmp/app.sqlite']);

        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => 'beast-mysql'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.slug_conflict');

        foreach (['driver' => 'mysql', 'host' => 'db.test', 'port' => 3306, 'database' => 'x', 'username' => 'x', 'password' => 'x', 'path' => '/x', 'node_id' => $this->dbNode->id] as $field => $value) {
            $this->postJson('/api/v1/database-connections', ['slug' => 'other', 'server' => 'beast-mysql', $field => $value])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation.failed');
        }

        $this->postJson('/api/v1/database-connections', [
            'slug' => 'other',
            'driver' => 'sqlite',
            'path' => '/tmp/other.sqlite',
            'instance_id' => $this->instance->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect($this->admin->statements)->toBe([]);
    });

    it('refuses a server that is not active', function (): void {
        $this->server->update(['status' => LifecycleStatus::Failed]);

        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => 'beast-mysql'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'database.server_inactive');
    });

});

describe('database:destroy on a server', function (): void {});

it('keeps the MySQL-only automatic Instance creation and clone entry point guarded for PostgreSQL', function (): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
    expect(fn () => app(CreateServerDatabaseAction::class)->activeServer($this->server->slug))
        ->toThrow(ResourceOperationException::class, 'Instance-owned databases require MySQL.');
    expect($this->admin->sql())->toBe('');
});

it('returns the API locale validation error through MCP without creating a database', function (): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'database-create', 'arguments' => ['slug' => 'mcp-pg', 'server' => $this->server->slug, 'locale' => 'en_US.utf8']],
    ])->assertOk()->assertJsonPath('result.isError', true);
    $error = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

    expect($error['status'])->toBe(422);
    expect($error['error']['code'])->toBe('validation.failed');
    expect($error['error']['details'])->toHaveKey('locale');
    $this->assertDatabaseMissing('database_connections', ['slug' => 'mcp-pg']);
    expect($this->admin->sql())->toBe('');
});

it('refuses unsupported or injected PostgreSQL locales before SQL execution', function (mixed $locale): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $this->postJson('/api/v1/database-connections', ['slug' => 'mealou', 'server' => $this->server->slug, 'locale' => $locale])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($this->admin->sql())->toBe('');
})->with(['en_US.utf8', "C.UTF-8'; DROP DATABASE postgres;--", '', null, 18]);

it('refuses a locale for MySQL instead of silently ignoring it', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'mealou', 'server' => $this->server->slug, 'locale' => 'C.UTF-8'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($this->admin->sql())->toBe('');
});

it('refuses locale on external registration', function (): void {
    $this->postJson('/api/v1/database-connections', ['slug' => 'external', 'driver' => 'pgsql', 'host' => 'db.example.test', 'database' => 'app', 'username' => 'app', 'password' => 'fixture', 'locale' => 'C.UTF-8'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect($this->admin->sql())->toBe('');
});

it('refuses locale on a Valkey server', function (): void {
    $this->server->update(['driver' => 'redis']);
    $this->postJson('/api/v1/database-connections', ['slug' => 'cache', 'server' => $this->server->slug, 'patterns' => ['app:*'], 'allow' => ['@read'], 'locale' => 'C.UTF-8'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
});

it('refuses changing a managed PostgreSQL locale after creation', function (): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $this->postJson('/api/v1/database-connections', ['slug' => 'mealou', 'server' => $this->server->slug, 'locale' => 'C.UTF-8'])->assertAccepted();
    $this->patchJson('/api/v1/database-connections/mealou', ['locale' => 'C'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(DatabaseConnection::query()->sole()->locale)->toBe('C.UTF-8');
});

describe('PostgreSQL test pool repair', function (): void {

    it('refuses a foreign owner before issuing any managed grants or creation', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
        $this->admin->rows = ['SELECT rolname' => ['dlf_feature_x'], 'AND pg_get_userbyid' => ['app_test_test_2']];
        $this->admin->statements = [];

        $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');

        expect($this->admin->sql())->not->toContain('CREATE', 'ALTER', 'GRANT', 'REVOKE', 'DROP');
    });

    it('refuses a different pool retry with 409 before running SQL', function (array $change): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', [...$request, ...$change])->assertConflict()->assertJsonPath('error.code', 'database.slug_conflict');
        expect($this->admin->sql())->toBe('');
    })->with(['count' => [['test_processes' => 3]], 'default count' => [['test_processes' => 8]], 'locale' => [['locale' => 'C']]]);
    it('refuses a missing managed role with 409 without creating databases or a role', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertAccepted();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->not->toContain('CREATE', 'ALTER', 'DROP');
    });

});

describe('managed PostgreSQL test databases', function (): void {

    it('refuses invalid worker counts with 422 before SQL execution', function (mixed $count): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => $count])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['test_processes']]]);
        expect($this->admin->sql())->toBe('');
    })->with([0, 33, -1, '2', 2.5, null, true]);

    it('requires an Instance for a worker count with 422 before SQL execution', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'test_processes' => 2])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['instance_id']]]);
        expect($this->admin->sql())->toBe('');
    });

    it('refuses worker counts on MySQL or Valkey with 422', function (string $driver): void {
        $this->server->update(['driver' => $driver]);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2, ...($driver === 'redis' ? ['patterns' => ['app:*'], 'allow' => ['@list']] : [])])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['test_processes']]]);
        expect($this->admin->sql())->toBe('');
    })->with(['mysql', 'redis']);

    it('refuses a worker count on external registration with 422', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'driver' => 'pgsql', 'host' => 'db.example.test', 'database' => 'app', 'username' => 'app', 'password' => 'fixture', 'test_processes' => 2])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['test_processes']]]);
        expect($this->admin->sql())->toBe('');
    });

    it('refuses a live worker name collision with 409 before creating objects', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->admin->rows["'app_test_test_2'"] = ['app_test_test_2'];
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
            ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->not->toContain('CREATE DATABASE', 'CREATE ROLE', 'DROP DATABASE');
        $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
    });

    it('refuses a registered primary database that overlaps a planned worker with 409', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app-test-test-2', 'server' => $this->server->slug])->assertAccepted();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
            ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->toBe('');
    });

    it('refuses a primary name inside a recorded worker pool with 409 even when the live query returns no names', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertAccepted();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', ['slug' => 'app-test-test-2', 'server' => $this->server->slug])
            ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->toBe('');
    });

});

describe('managed database removal preflight', function (): void {
    it('refuses PostgreSQL blockers without dropping the recorded pool', function (string $blocker): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertAccepted();
        DatabaseConnectionTarget::query()->delete();
        $this->admin->rows['SELECT DISTINCT blocker'] = [$blocker];
        $this->admin->statements = [];

        $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
            ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', [$blocker]);

        expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP ROLE');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
    })->with(['app', 'app_test', 'app_test_test_2', 'outside']);

    it('refuses MySQL sessions and external definers before dropping anything', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug])->assertAccepted();
        databaseCreatedProof(DatabaseConnection::query()->where('slug', 'app')->sole());
        $this->admin->statements = [];
        $this->admin->rows['SELECT DISTINCT blocker'] = ['app', 'external'];

        $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
            ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app', 'external']);

        expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP USER');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
    });

});

describe('MySQL removal against a real server', function (): void {})->group('privileged');
