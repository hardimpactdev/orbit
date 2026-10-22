<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\InstanceEnvironmentValue;
use App\Models\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once __DIR__.'/../../Support/ServerDatabaseFixtures.php';

// Only the privileged MySQL regression uses this disposable server.
$mysqlRemovalContainer = 'orbit-test-destroy-mysql-'.bin2hex(random_bytes(6));
$mysqlRemovalProcess = null;
beforeAll(function () use ($mysqlRemovalContainer, &$mysqlRemovalProcess): void {
    if (getenv('ORBIT_TEST_DOCKER') !== '1') {
        return;
    }
    $mysqlRemovalProcess = new SymfonyProcess(['docker', 'run', '--rm', '--name', $mysqlRemovalContainer, '--log-driver=none', '--publish=127.0.0.1::3306', '--tmpfs=/var/lib/mysql', '--env=MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env=MYSQL_ROOT_HOST=%', 'mysql:8.4', '--skip-log-bin', '--innodb-buffer-pool-size=32M']);
    $mysqlRemovalProcess->setTimeout(60)->start();
    try {
        if (! $mysqlRemovalProcess->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'port: 3306'))) {
            throw new RuntimeException('The disposable MySQL server did not start.');
        }
    } catch (Throwable $e) {
        (new SymfonyProcess(['docker', 'rm', '--force', '--volumes', $mysqlRemovalContainer]))->run();
        $mysqlRemovalProcess->stop();
        throw $e;
    }
});
afterAll(function () use ($mysqlRemovalContainer, &$mysqlRemovalProcess): void {
    if ($mysqlRemovalProcess !== null) {
        (new SymfonyProcess(['docker', 'rm', '--force', '--volumes', $mysqlRemovalContainer]))->run();
        $mysqlRemovalProcess->stop();
    }
});

uses(ServerDatabaseFixtures::class);

beforeEach(fn () => $this->prepareServerDatabaseFixtures());

describe('database:create --server', function (): void {
    it('creates the database, its test database, and the Instance user, then attaches it under DB', function (): void {
        $response = $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-leden',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.slug', 'dlf-leden')
            ->assertJsonPath('data.driver', 'mysql')
            ->assertJsonPath('data.node_id', $this->dbNode->id)
            ->assertJsonPath('data.host', '10.44.0.80')
            ->assertJsonPath('data.port', 3306)
            ->assertJsonPath('data.database', 'dlf_leden')
            ->assertJsonPath('data.username', 'dlf_feature_x')
            ->assertJsonPath('data.has_password', true)
            ->assertJsonPath('data.server', 'beast-mysql')
            ->assertJsonPath('data.owner_instance_id', $this->instance->id)
            ->assertJsonPath('data.test_database', 'dlf_leden_test')
            ->assertJsonMissingPath('data.password');

        $connection = DatabaseConnection::query()->sole();
        $password = (string) $connection->password;

        expect(strlen($password))->toBe(40)
            ->and($response->getContent())->not->toContain($password)
            ->not->toContain(SERVER_DATABASE_ROOT_SECRET)
            ->and($connection->database_server_id)->toBe($this->server->id)
            ->and($this->admin->sql())
            ->toContain("SCHEMA_NAME = 'dlf_leden' OR SCHEMA_NAME LIKE 'dlf\\\\_leden\\\\_test%'")
            ->toContain("WHERE User = 'dlf_feature_x'")
            ->toContain("CREATE USER IF NOT EXISTS 'dlf_feature_x'@'%' IDENTIFIED BY '{$password}';")
            ->toContain('CREATE DATABASE `dlf_leden`;')
            ->toContain("GRANT ALL PRIVILEGES ON `dlf_leden`.* TO 'dlf_feature_x'@'%';")
            ->toContain('CREATE DATABASE `dlf_leden_test`;')
            ->toContain("GRANT ALL PRIVILEGES ON `dlf\\_leden\\_test%`.* TO 'dlf_feature_x'@'%';");

        $user = DatabaseUser::query()->sole();

        expect($user->username)->toBe('dlf_feature_x')
            ->and($user->privileges)->toBe('ALL PRIVILEGES ON `dlf_leden`.*, `dlf\\_leden\\_test%`.*')
            ->and($user->created_by)->toBe('gateway');

        $target = DatabaseConnectionTarget::query()->sole();
        $environment = InstanceEnvironmentValue::query()
            ->where('instance_id', $this->instance->id)
            ->pluck('env_value', 'env_key')
            ->all();

        expect($target->prefix)->toBe('DB')
            ->and($target->database_connection_id)->toBe($connection->id)
            ->and($environment['DB_DATABASE'] ?? null)->toBe('dlf_leden')
            ->and($environment['DB_USERNAME'] ?? null)->toBe('dlf_feature_x')
            ->and($environment['DB_PASSWORD'] ?? null)->toBe($password);

        $activity = Activity::query()->where('request_id', $response->json('meta.request_id'))->sole();

        expect($activity->command)->toBe('database:create')
            ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
            ->not->toContain($password)
            ->not->toContain(SERVER_DATABASE_ROOT_SECRET);
    });

    it('points a same-Node Instance at the server\'s WireGuard address over stale keys and an older MySQL Process', function (): void {
        $older = Process::query()->findOrFail($this->server->process_id);
        $older->update([
            'name' => 'mysql-84',
            'runtime_config' => [...$older->runtime_config, 'ports' => ['3308:3306']],
        ]);
        $serverProcess = Process::query()->create([
            ...$older->only(['owner_type', 'owner_id', 'runtime', 'working_directory', 'restart_policy', 'desired_state', 'status']),
            'name' => 'beast-mysql',
            'runtime_config' => [...$older->runtime_config, 'ports' => ['10.44.0.80:3306:3306']],
        ]);
        $this->server->update(['process_id' => $serverProcess->id]);
        $this->instance->environmentValues()->createMany([
            ['env_key' => 'DB_HOST', 'env_value' => '127.0.0.1'],
            ['env_key' => 'DB_PORT', 'env_value' => '13306'],
        ]);

        $this->postJson('/api/v1/database-connections', [
            'slug' => 'ohdear',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertCreated()->assertJsonPath('data.host', '10.44.0.80')->assertJsonPath('data.port', 3306);

        $environment = InstanceEnvironmentValue::query()
            ->where('instance_id', $this->instance->id)
            ->pluck('env_value', 'env_key')
            ->all();

        expect($environment['DB_HOST'] ?? null)->toBe('10.44.0.80')
            ->and($environment['DB_PORT'] ?? null)->toBe('3306');
    });

    it('reuses the Instance user and its password for a second database on the same server', function (): void {
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-leden',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertCreated();
        $first = DatabaseConnection::query()->where('slug', 'dlf-leden')->sole();
        $this->admin->statements = [];

        $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-archief',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertCreated()->assertJsonPath('data.username', 'dlf_feature_x');

        $second = DatabaseConnection::query()->where('slug', 'dlf-archief')->sole();

        expect($second->password)->toBe($first->password)
            ->and($this->admin->sql())->not->toContain('mysql.user')
            ->toContain("ALTER USER 'dlf_feature_x'@'%' IDENTIFIED BY '{$first->password}';");
    });

    it('gives a database without an Instance its own user and no test database', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'reporting', 'server' => 'beast-mysql'])
            ->assertCreated()
            ->assertJsonPath('data.database', 'reporting')
            ->assertJsonPath('data.username', 'reporting')
            ->assertJsonPath('data.owner_instance_id', null)
            ->assertJsonPath('data.test_database', null);

        expect($this->admin->sql())->not->toContain('_test')
            ->and(DatabaseConnectionTarget::query()->exists())->toBeFalse()
            ->and(DatabaseUser::query()->sole()->privileges)->toBe('ALL PRIVILEGES ON `reporting`.*');
    });

    it('shortens long names with a stable hash', function (): void {
        $slug = 'a-very-long-database-slug-that-names-the-reporting-warehouse-xyz';
        $database = str_replace('-', '_', substr($slug, 0, 63));

        $this->postJson('/api/v1/database-connections', [
            'slug' => substr($slug, 0, 63),
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertCreated()
            ->assertJsonPath('data.database', $database)
            ->assertJsonPath('data.test_database', substr($database.'_test', 0, 55).'_'.substr(sha1($database.'_test'), 0, 8));

        $this->postJson('/api/v1/database-connections', ['slug' => 'warehouse-of-the-reporting-department', 'server' => 'beast-mysql'])
            ->assertCreated()
            ->assertJsonPath('data.username', 'warehouse_of_the_report_'.substr(sha1('warehouse_of_the_reporting_department'), 0, 8));
    });

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
        ])->assertCreated();

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

    it('drops what a failed create left and records nothing', function (): void {
        $this->admin->failOn = 'GRANT ALL';

        $response = $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-leden',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ]);

        $response->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');

        expect(DatabaseConnection::query()->exists())->toBeFalse()
            ->and(end($this->admin->statements))->toBe(implode("\n", [
                'DROP DATABASE IF EXISTS `dlf_leden`;',
                'DROP DATABASE IF EXISTS `dlf_leden_test`;',
                "DROP USER IF EXISTS 'dlf_feature_x'@'%';",
            ])."\n")
            ->and($response->getContent())->not->toContain(SERVER_DATABASE_ROOT_SECRET);
    });
});

describe('database:destroy on a server', function (): void {
    it('drops the database, its test databases, and its user', function (): void {
        $this->postJson('/api/v1/database-connections', [
            'slug' => 'dlf-leden',
            'server' => 'beast-mysql',
            'instance_id' => $this->instance->id,
        ])->assertCreated();
        DatabaseConnectionTarget::query()->delete();
        $this->admin->statements = [];
        $this->admin->rows = ["LIKE 'dlf\\\\_leden\\\\_test%'" => ['dlf_leden_test', 'dlf_leden_test_test_1']];

        $this->deleteJson('/api/v1/database-connections/dlf-leden')->assertOk();

        expect(DatabaseConnection::query()->exists())->toBeFalse()
            ->and(end($this->admin->statements))->toBe(implode("\n", [
                'DROP DATABASE IF EXISTS `dlf_leden`;',
                'DROP DATABASE IF EXISTS `dlf_leden_test`;',
                'DROP DATABASE IF EXISTS `dlf_leden_test_test_1`;',
                "DROP USER IF EXISTS 'dlf_feature_x'@'%';",
            ])."\n");
    });

    it('keeps a user that another connection on the server still uses', function (): void {
        foreach (['dlf-leden', 'dlf-archief'] as $slug) {
            $this->postJson('/api/v1/database-connections', [
                'slug' => $slug,
                'server' => 'beast-mysql',
                'instance_id' => $this->instance->id,
            ])->assertCreated();
        }
        DatabaseConnectionTarget::query()->delete();
        $this->admin->statements = [];

        $this->deleteJson('/api/v1/database-connections/dlf-leden')->assertOk();

        expect($this->admin->sql())->toContain('DROP DATABASE IF EXISTS `dlf_leden`;')
            ->not->toContain('DROP USER');
    });

    it('keeps the record when the drop fails and never drops a registered database', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'reporting', 'server' => 'beast-mysql'])->assertCreated();
        $this->admin->failOn = 'DROP DATABASE';

        $this->deleteJson('/api/v1/database-connections/reporting')
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'database.server_command_failed');

        expect(DatabaseConnection::query()->where('slug', 'reporting')->exists())->toBeTrue();

        DatabaseConnection::query()->create([
            'slug' => 'external',
            'driver' => 'mysql',
            'host' => 'db.example.test',
            'database' => 'external',
            'username' => 'external',
            'password' => 'external-secret',
        ]);
        $this->admin->statements = [];

        $this->deleteJson('/api/v1/database-connections/external')->assertOk();

        expect($this->admin->statements)->toBe([]);
    });
});

it('creates a managed PostgreSQL database and non-superuser owner through the SQL server path', function (): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18', 'port' => 5434]);
    $response = $this->postJson('/api/v1/database-connections', ['slug' => 'mealou-food-catalog', 'server' => $this->server->slug]);
    $response->assertCreated()->assertJsonPath('data.driver', 'pgsql')->assertJsonPath('data.database', 'mealou_food_catalog');
    $connection = DatabaseConnection::query()->where('slug', 'mealou-food-catalog')->sole();
    expect($this->admin->sql())->toContain('CREATE DATABASE "mealou_food_catalog";', 'ALTER DATABASE "mealou_food_catalog" OWNER TO "mealou_food_catalog";', 'NOSUPERUSER NOCREATEDB NOCREATEROLE', 'SCRAM-SHA-256$')
        ->not->toContain($connection->password)
        ->and($connection->test_database)->toBeNull();
});

it('keeps the MySQL-only automatic Instance creation and clone entry point guarded for PostgreSQL', function (): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
    expect(fn () => app(CreateServerDatabaseAction::class)->activeServer($this->server->slug))
        ->toThrow(ResourceOperationException::class, 'Instance-owned databases require MySQL.');
    expect($this->admin->sql())->toBe('');
});

it('records a chosen PostgreSQL builtin locale and creates from template0', function (string $locale): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $this->postJson('/api/v1/database-connections', ['slug' => 'mealou', 'server' => $this->server->slug, 'locale' => $locale])
        ->assertCreated()->assertJsonPath('data.locale', $locale);
    expect(DatabaseConnection::query()->where('slug', 'mealou')->sole()->locale)->toBe($locale);
    expect($this->admin->sql())->toContain("TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE '{$locale}'");
})->with(['C', 'C.UTF-8']);

it('advertises PostgreSQL locale through the generated API and served MCP schema', function (): void {
    $openapi = json_decode(file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $variants = $openapi['paths']['/api/v1/database-connections']['post']['requestBody']['content']['application/json']['schema']['oneOf'];
    expect($variants[1]['properties']['locale']['enum'])->toBe(['C', 'C.UTF-8']);
    expect($variants[0]['properties'])->not->toHaveKey('locale');
    expect($variants[2]['properties'])->not->toHaveKey('locale');

    $response = $this->postJson('/mcp/search', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'search_tools', 'arguments' => ['query' => 'database-create', 'limit' => 1]],
    ])->assertOk()->assertJsonPath('result.isError', false);
    $found = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    expect($found['tools'][0]['name'])->toBe('database-create');
    $schema = $found['tools'][0]['inputSchema'];
    expect($schema['properties']['locale']['enum'] ?? null)->toBe(['C', 'C.UTF-8']);
    expect($schema['oneOf'][1]['properties']['locale'])->toBe($schema['properties']['locale']);
    expect($schema['oneOf'][0]['properties'])->not->toHaveKey('locale');
    expect($schema['oneOf'][2]['properties'])->not->toHaveKey('locale');
});

it('creates PostgreSQL databases with the requested locale through MCP', function (string $locale): void {
    $this->server->update(['driver' => 'pgsql', 'tag' => '18']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
        'params' => ['name' => 'database-create', 'arguments' => ['slug' => 'mcp-pg', 'server' => $this->server->slug, 'locale' => $locale]],
    ])->assertOk()->assertJsonPath('result.isError', false);
    $created = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

    expect($created['data']['locale'])->toBe($locale);
    $this->assertDatabaseHas('database_connections', ['slug' => 'mcp-pg', 'locale' => $locale]);
    expect($this->admin->sql())->toContain("TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE '{$locale}'");
})->with(['C', 'C.UTF-8']);

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
    $this->postJson('/api/v1/database-connections', ['slug' => 'mealou', 'server' => $this->server->slug, 'locale' => 'C.UTF-8'])->assertCreated();
    $this->patchJson('/api/v1/database-connections/mealou', ['locale' => 'C'])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    expect(DatabaseConnection::query()->sole()->locale)->toBe('C.UTF-8');
});

describe('PostgreSQL test pool repair', function (): void {
    it('restores only missing recorded members and converges all grants on repeated retries', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $connection = DatabaseConnection::query()->sole();
        $before = $connection->getAttributes();
        $target = DatabaseConnectionTarget::query()->sole()->getAttributes();
        $this->admin->rows = ['SELECT rolname' => ['dlf_feature_x'], 'AND pg_get_userbyid' => [], 'SELECT datname' => ['app_test_test_2']];
        $statements = new PostgresStatements;

        foreach ([['app_test', 'app_test_test_1'], []] as $missing) {
            $this->admin->statements = [];
            $this->postJson('/api/v1/database-connections', $request)->assertOk()->assertJsonPath('data.id', $connection->id);
            foreach (['app_test', 'app_test_test_1', 'app_test_test_2'] as $name) {
                expect($this->admin->sql())->toContain($statements->grantAll($name, 'dlf_feature_x'));
                expect(str_contains($this->admin->sql(), 'CREATE DATABASE "'.$name.'"'))->toBe(in_array($name, $missing, true));
            }
            expect($this->admin->sql())->not->toContain('DROP', 'ALTER ROLE', 'ALTER DATABASE "app"', 'CREATE DATABASE "app"');
            expect($connection->fresh()->getAttributes())->toBe($before);
            expect(DatabaseConnectionTarget::query()->sole()->getAttributes())->toBe($target);
            $this->assertDatabaseCount('database_connections', 1);
            $this->assertDatabaseCount('database_users', 1);
            $this->admin->rows['SELECT datname'] = ['app_test', 'app_test_test_1', 'app_test_test_2'];
        }
    });

    it('finishes managed grants on retry after an interrupted command batch', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->rows = ['SELECT rolname' => ['dlf_feature_x'], 'AND pg_get_userbyid' => [], 'SELECT datname' => ['app_test_test_2']];
        $this->admin->failOn = 'CREATE DATABASE "app_test_test_1"';

        $this->postJson('/api/v1/database-connections', $request)->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
        $this->admin->failOn = null;
        $this->admin->rows['SELECT datname'] = ['app_test', 'app_test_test_1', 'app_test_test_2'];
        $this->admin->statements = [];

        $this->postJson('/api/v1/database-connections', $request)->assertOk();

        expect($this->admin->sql())->not->toContain('CREATE DATABASE', 'DROP');
        foreach (['app_test', 'app_test_test_1', 'app_test_test_2'] as $name) {
            expect($this->admin->sql())->toContain(new PostgresStatements()->grantAll($name, 'dlf_feature_x'));
        }
    });

    it('refuses a foreign owner before issuing any managed grants or creation', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->rows = ['SELECT rolname' => ['dlf_feature_x'], 'AND pg_get_userbyid' => ['app_test_test_2']];
        $this->admin->statements = [];

        $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');

        expect($this->admin->sql())->not->toContain('CREATE', 'ALTER', 'GRANT', 'REVOKE', 'DROP');
    });

    it('refuses a different pool retry with 409 before running SQL', function (array $change): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', [...$request, ...$change])->assertConflict()->assertJsonPath('error.code', 'database.slug_conflict');
        expect($this->admin->sql())->toBe('');
    })->with(['count' => [['test_processes' => 3]], 'default count' => [['test_processes' => 8]], 'locale' => [['locale' => 'C']]]);
    it('refuses a missing managed role with 409 without creating databases or a role', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->not->toContain('CREATE', 'ALTER', 'DROP');
    });
    it('restores the recorded builtin locale and refuses a retry during database removal', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2, 'locale' => 'C.UTF-8'];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->statements = [];
        $this->admin->rows['SELECT rolname'] = ['dlf_feature_x'];
        $this->admin->rows['AND pg_get_userbyid'] = [];
        $this->admin->rows['SELECT datname'] = ['app_test', 'app_test_test_2'];
        $lock = app(DatabaseRemovalLock::class);
        $lock->run([$this->server->id], function () use ($request): void {
            $this->postJson('/api/v1/database-connections', $request)->assertConflict()->assertJsonPath('error.code', 'database.server_in_use');
        });
        expect($this->admin->sql())->toBe('');
        $this->postJson('/api/v1/database-connections', $request)->assertOk();
        expect($this->admin->sql())->toContain('CREATE DATABASE "app_test_test_1" OWNER "dlf_feature_x" TEMPLATE template0 ENCODING \'UTF8\' LOCALE_PROVIDER builtin BUILTIN_LOCALE \'C.UTF-8\';');
        expect($this->admin->sql())->not->toContain('CREATE DATABASE "app_test"', 'CREATE DATABASE "app_test_test_2"', 'DROP', 'ALTER ROLE');
    });
});

describe('managed PostgreSQL test databases', function (): void {
    it('creates the default eight workers under the managed Instance owner and plans the testing environment', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18', 'port' => 5434]);
        $response = $this->postJson('/api/v1/database-connections', ['slug' => 'app-dev', 'server' => $this->server->slug, 'instance_id' => $this->instance->id]);
        $response->assertCreated()->assertJsonPath('data.test_database', 'app_dev_test')->assertJsonPath('data.test_processes', 8)->assertJsonMissingPath('data.password');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app-dev', 'owner_instance_id' => $this->instance->id, 'test_processes' => 8]);
        $sql = $this->admin->sql();
        expect(substr_count($sql, 'CREATE DATABASE "'))->toBe(10);
        expect($sql)->toContain('NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', 'ALTER DATABASE "app_dev_test" OWNER TO "dlf_feature_x";');
        foreach (range(1, 8) as $token) {
            expect($sql)->toContain('CREATE DATABASE "app_dev_test_test_'.$token.'";', 'ALTER DATABASE "app_dev_test_test_'.$token.'" OWNER TO "dlf_feature_x";');
        }
        $plan = app(InstanceTestEnvironment::class)->plan($this->instance->id, ['APP_NAME' => 'App']);
        expect($plan?->values)->toMatchArray(['APP_ENV' => 'testing', 'APP_NAME' => 'App', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'app_dev_test', 'DB_PORT' => '5434', 'DB_USERNAME' => 'dlf_feature_x']);
    });

    it('applies the selected locale to the primary, serial and configured worker databases', function (int $count): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'locale' => 'C.UTF-8', 'test_processes' => $count])
            ->assertCreated()->assertJsonPath('data.test_processes', $count);
        expect(substr_count($this->admin->sql(), "TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER builtin BUILTIN_LOCALE 'C.UTF-8'"))->toBe($count + 2);
    })->with([1, 32]);

    it('fits long database names and the largest worker suffix within PostgreSQL identifiers', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $response = $this->postJson('/api/v1/database-connections', ['slug' => str_repeat('a', 63), 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 32])->assertCreated();
        expect($response->json('data.database'))->toHaveLength(50);
        expect($response->json('data.test_database'))->toHaveLength(55);
        expect($this->admin->sql())->toContain('CREATE DATABASE "'.$response->json('data.test_database').'_test_32";');
    });

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
        $this->postJson('/api/v1/database-connections', ['slug' => 'app-test-test-2', 'server' => $this->server->slug])->assertCreated();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
            ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->toBe('');
    });

    it('refuses a primary name inside a recorded worker pool with 409 even when the live query returns no names', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', ['slug' => 'app-test-test-2', 'server' => $this->server->slug])
            ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
        expect($this->admin->sql())->toBe('');
    });

    it('removes every planned test database when worker creation fails and records nothing', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->admin->failOn = 'CREATE DATABASE "app_test_test_2"';
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
            ->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');
        expect(end($this->admin->statements))->toBe('DROP DATABASE IF EXISTS "app";'."\n".'DROP DATABASE IF EXISTS "app_test";'."\n".'DROP DATABASE IF EXISTS "app_test_test_1";'."\n".'DROP DATABASE IF EXISTS "app_test_test_2";'."\n".'DROP ROLE IF EXISTS "dlf_feature_x";'."\n");
        $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        $this->assertDatabaseCount('database_users', 0);
        $this->assertDatabaseCount('database_connection_targets', 0);
    });

    it('drops only the recorded pool and preserves a shared Instance owner until its final connection is removed', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        foreach (['app', 'other'] as $slug) {
            $this->postJson('/api/v1/database-connections', ['slug' => $slug, 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        }
        $connections = DatabaseConnection::query()->orderBy('id')->get();
        expect($connections[0]->username)->toBe($connections[1]->username);
        expect($connections[0]->password)->toBe($connections[1]->password);
        $this->admin->statements = [];
        $this->admin->rows['SELECT datname FROM pg_database'] = ['app_test_test_99', 'unrelated'];
        DatabaseConnectionTarget::query()->delete();
        $this->deleteJson('/api/v1/database-connections/app')->assertOk();
        expect($this->admin->sql())->toContain('DROP DATABASE IF EXISTS "app_test_test_1";', 'DROP DATABASE IF EXISTS "app_test_test_2";')->not->toContain('app_test_test_99', 'unrelated', 'DROP ROLE', 'other');
        $this->admin->statements = [];
        $this->deleteJson('/api/v1/database-connections/other')->assertOk();
        expect($this->admin->sql())->toContain('DROP ROLE IF EXISTS "dlf_feature_x";');
        $this->assertDatabaseCount('database_connections', 0);
    });

    it('creates the configured pool through MCP and advertises its bounds', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'database-create', 'arguments' => ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2]]])
            ->assertOk()->assertJsonPath('result.isError', false);
        $data = json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        expect($data['data']['test_processes'])->toBe(2);
        $this->assertDatabaseHas('database_connections', ['slug' => 'app', 'test_processes' => 2]);
        $schema = json_decode(file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR)['paths']['/api/v1/database-connections']['post']['requestBody']['content']['application/json']['schema'];
        expect($schema['oneOf'][1]['properties']['test_processes'])->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 32]);
        expect($schema['oneOf'][0]['properties'])->not->toHaveKey('test_processes');
        expect($schema['oneOf'][2]['properties'])->not->toHaveKey('test_processes');
    });
});

describe('managed database removal preflight', function (): void {
    it('refuses PostgreSQL blockers without dropping the recorded pool', function (string $blocker): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        DatabaseConnectionTarget::query()->delete();
        $this->admin->rows['SELECT DISTINCT blocker'] = [$blocker];
        $this->admin->statements = [];

        $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
            ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', [$blocker]);

        expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP ROLE');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
    })->with(['app', 'app_test', 'app_test_test_2', 'outside']);

    it('restores admission after a late blocker and finishes on retry', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        DatabaseConnectionTarget::query()->delete();
        $fake = $this->admin;
        $fake->statements = [];
        $checks = 0;
        $admin = Mockery::mock(DatabaseServerAdmin::class);
        $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($fake, &$checks): array {
            $result = $fake->execute($server, $sql);

            return str_starts_with($sql, 'SELECT DISTINCT blocker') && ++$checks === 2 ? ['app_test_test_2'] : $result;
        });
        app()->instance(DatabaseServerAdmin::class, $admin);

        $this->deleteJson('/api/v1/database-connections/app')->assertConflict()->assertJsonPath('error.code', 'database.in_use');

        $statements = new PostgresStatements;
        $names = ['app', 'app_test', 'app_test_test_1', 'app_test_test_2'];
        expect($fake->sql())->toContain($statements->fenceDatabases($names), $statements->restoreDatabaseAdmission($names))->not->toContain('DROP DATABASE');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);

        $this->deleteJson('/api/v1/database-connections/app')->assertOk();

        expect($fake->sql())->toContain('DROP DATABASE');
        $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
    });

    it('keeps the connection on a failed drop and completes a repeated request', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        DatabaseConnectionTarget::query()->delete();
        $this->admin->failOn = 'DROP DATABASE';
        $this->admin->statements = [];

        $this->deleteJson('/api/v1/database-connections/app')->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');

        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
        expect($this->admin->sql())->toContain(new PostgresStatements()->restoreDatabaseAdmission(['app', 'app_test', 'app_test_test_1', 'app_test_test_2']));
        $this->admin->failOn = null;

        $this->deleteJson('/api/v1/database-connections/app')->assertOk();

        $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
    });

    it('refuses MySQL sessions and external definers before dropping anything', function (): void {
        $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug])->assertCreated();
        $this->admin->statements = [];
        $this->admin->rows['SELECT DISTINCT blocker'] = ['app', 'external'];

        $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
            ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app', 'external']);

        expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP USER');
        $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
    });
    it('checks all Instance connections before dropping the first and finishes on retry', function (): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        foreach (['app', 'other'] as $slug) {
            $this->postJson('/api/v1/database-connections', ['slug' => $slug, 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
        }
        $this->admin->statements = [];
        $this->admin->rows['SELECT DISTINCT blocker'] = ['other_test_test_2'];

        expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.in_use'));

        expect($this->admin->sql())->not->toContain('DROP DATABASE', 'DROP ROLE');
        $this->assertDatabaseCount('database_connections', 2);
        $this->assertDatabaseCount('database_connection_targets', 1);
        expect($this->admin->statements[0])->toContain("'app'", "'other_test_test_2'", "'dlf_feature_x'");
        $this->admin->rows = [];

        app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

        $this->assertDatabaseCount('database_connections', 0);
        $this->assertDatabaseCount('database_connection_targets', 0);
        expect($this->admin->sql())->toContain('DROP ROLE IF EXISTS "dlf_feature_x";');
    });
});

describe('MySQL removal against a real server', function () use ($mysqlRemovalContainer): void {
    it('checks sessions, outside definers and incoming foreign keys before any drop', function () use ($mysqlRemovalContainer): void {
        if (getenv('ORBIT_TEST_DOCKER') !== '1') {
            $this->markTestSkipped('Set ORBIT_TEST_DOCKER=1 to run against MySQL in Docker.');
        }
        $container = $mysqlRemovalContainer;
        $mysql = static function (string $sql) use ($container): array {
            $command = new SymfonyProcess(['docker', 'exec', '-i', $container, 'mysql', '--user=root', '--batch', '--skip-column-names']);
            $output = trim($command->setInput($sql)->mustRun()->getOutput());

            return $output === '' ? [] : explode("\n", $output);
        };
        try {
            $port = trim((new SymfonyProcess(['docker', 'port', $container, '3306']))->mustRun()->getOutput());
            $admin = Mockery::mock(DatabaseServerAdmin::class);
            $admin->shouldReceive('execute')->andReturnUsing(static fn (DatabaseServer $server, string $sql): array => $mysql($sql));
            app()->instance(DatabaseServerAdmin::class, $admin);
            $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id])->assertCreated();
            DatabaseConnectionTarget::query()->delete();
            $mysql('CREATE DATABASE app_test_test_1; CREATE DATABASE app_test_test_2; CREATE DATABASE outside; CREATE TABLE outside.source (id int PRIMARY KEY); CREATE VIEW outside.unrelated AS SELECT * FROM outside.source;');
            $assertIntact = function () use ($mysql): void {
                expect($mysql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('app', 'app_test', 'app_test_test_1', 'app_test_test_2');"))->toBe(['4']);
                expect($mysql("SELECT COUNT(*) FROM mysql.user WHERE User = 'dlf_feature_x';"))->toBe(['1']);
                $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            };
            $session = new PDO('mysql:host=127.0.0.1;port='.substr($port, strrpos($port, ':') + 1).';dbname=app_test_test_2', 'root');

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);
            $assertIntact();
            $session = null;
            $mysql('CREATE TABLE app_test_test_2.locked_table (id int);');
            $session = new PDO('mysql:host=127.0.0.1;port='.substr($port, strrpos($port, ':') + 1), 'root');
            $session->beginTransaction();
            $session->query('SELECT * FROM app_test_test_2.locked_table')->fetchAll();

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);
            $assertIntact();
            $session->rollBack();
            $session = null;
            $mysql("CREATE DEFINER = 'dlf_feature_x'@'%' VIEW outside.dependent AS SELECT * FROM outside.source;");

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['outside']);
            $assertIntact();
            $mysql('DROP VIEW outside.dependent; CREATE TABLE app_test.parent (id int PRIMARY KEY); CREATE TABLE outside.child (id int, FOREIGN KEY (id) REFERENCES app_test.parent(id));');

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['outside']);
            $assertIntact();
            $mysql('DROP TABLE outside.child; CREATE TABLE app_test_test_2.child (id int, FOREIGN KEY (id) REFERENCES app_test.parent(id));');

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);
            $assertIntact();
            $mysql('DROP TABLE app_test_test_2.child;');

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($mysql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'app%';"))->toBe(['0']);
            expect($mysql("SELECT COUNT(*) FROM mysql.user WHERE User = 'dlf_feature_x';"))->toBe(['0']);
            expect($mysql('SELECT COUNT(*) FROM outside.unrelated;'))->toBe(['0']);
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        } finally {
            $session = null;

        }
    });
})->group('privileged');
