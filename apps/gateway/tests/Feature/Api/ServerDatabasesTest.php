<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceTestEnvironment;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\Support\FakesEnvironmentSyncInspection;

const SERVER_DATABASE_ROOT_SECRET = 'server-root-secret-5d1e';

beforeEach(function (): void {
    $this->admin = new FakeDatabaseServerAdmin;
    app()->instance(DatabaseServerAdmin::class, $this->admin);
    app()->instance(InstanceOperationPreflight::class, new ServerDatabaseEnvironmentAccess);
    app()->instance(InstanceEnvironmentReader::class, new ServerDatabaseEnvironmentAccess);
    app()->instance(InstanceEnvironmentWriter::class, new ServerDatabaseEnvironmentAccess);

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

    $node = Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.80',
    ]);
    orbit_test_set_app_placement_role($node, false);
    $this->dbNode = $node;
    $process = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $node->id,
        'name' => 'beast-mysql',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'mysql:8.4',
            'command' => ['mysqld'],
            'environment' => [],
            'ports' => ['10.44.0.80:3306:3306'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'beast-mysql',
        'node_id' => $node->id,
        'process_id' => $process->id,
        'tag' => '8.4',
        'port' => 3306,
        'root_password' => SERVER_DATABASE_ROOT_SECRET,
        'status' => LifecycleStatus::Active,
    ]);

    $project = Project::query()->create([
        'name' => 'DLF',
        'slug' => 'dlf',
        'repository_url' => 'https://example.test/dlf.git',
        'default_branch' => 'main',
    ]);
    $this->instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'feature-x',
        'environment' => 'development',
        'checkout_path' => '/srv/orbit/dlf/feature-x',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
});

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

final class ServerDatabaseEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    use FakesEnvironmentSyncInspection;

    public function assertEnvironmentReadable(InstanceEnvironmentContext $context): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function assertEnvironmentWritable(InstanceEnvironmentContext $context, int $requiredCapacityBytes): void
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function read(InstanceEnvironmentContext $context): string
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }

    public function write(InstanceEnvironmentContext $context, #[SensitiveParameter] string $contents): InstanceEnvironmentWriteResult
    {
        throw new RuntimeException('Attachment contacted the workload Node.');
    }
}

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
        $this->admin->rows['pg_database'] = ['app_test_test_99', 'unrelated'];
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
