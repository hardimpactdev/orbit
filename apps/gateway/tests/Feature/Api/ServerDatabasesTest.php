<?php

declare(strict_types=1);

use App\Actions\DatabaseConnections\CreateServerDatabaseAction;
use App\Actions\DatabaseConnections\DropOwnedDatabasesAction;
use App\Actions\DatabaseConnections\DropServerDatabaseAction;
use App\Actions\Doctor\DatabaseServerDoctorProbe;
use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Testing\Concerns\TestDatabases;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\FakeDatabaseServerAdmin;
use Tests\TestCase;

require_once __DIR__.'/../../Support/PostgresServer.php';

const SERVER_DATABASE_ROOT_SECRET = 'server-root-secret-5d1e';

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

describe('PostgreSQL test pool repair', function (): void {
    it('detects and repairs members lost through Laravel without changing surviving databases', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            server_database_live_postgres($this, $psql);
            $connection = DatabaseConnection::query()->where('slug', 'app')->sole();
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $psql("\\connect app\nSET ROLE dlf_feature_x; CREATE TABLE application_data (id int); INSERT INTO application_data VALUES (42);\n\\connect app_test_test_2\nSET ROLE dlf_feature_x; CREATE TABLE survivor (id int); INSERT INTO survivor VALUES (17);\n");
            $before = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname IN ('app', 'app_test_test_2') ORDER BY datname;");
            $psql('ALTER DATABASE app_test_test_1 CONNECTION LIMIT 0;');
            $default = config('database.default');
            config()->set('database.connections.pgsql_pool', ['driver' => 'pgsql', 'host' => $directory, 'port' => 5432, 'database' => 'app_test', 'username' => 'dlf_feature_x', 'password' => '', 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'disable']);
            config()->set('database.default', 'pgsql_pool');
            $probe = new class
            {
                use TestDatabases {
                    ensureTestDatabaseExists as public ensure;
                }
            };
            ParallelTesting::resolveTokenUsing(fn (): int => 1);
            try {
                expect(fn () => $probe->ensure('app_test'))->toThrow(QueryException::class, 'permission denied to create database');
            } finally {
                DB::purge('pgsql_pool');
                config()->set('database.default', $default);
                ParallelTesting::resolveTokenUsing(null);
            }
            $psql('DROP DATABASE app_test;');
            $doctor = app(DatabaseServerDoctorProbe::class);
            $context = new DoctorNodeContext($this->dbNode, new NodeInspectionData(true, 'linux', 'amd64', true));
            $report = $doctor->inspect($context);
            expect(array_column($report->issues, 'code'))->toBe(['database_server.test_database_missing', 'database_server.test_database_missing']);
            expect(array_column($report->issues, 'observed'))->toBe(['app_test', 'app_test_test_1']);

            $restored = null;
            foreach ([1, 2] as $attempt) {
                $this->postJson('/api/v1/database-connections', $request)->assertOk()->assertJsonPath('data.id', $connection->id);
                $state = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname IN ('app', 'app_test_test_2') ORDER BY datname;\n\\connect app\nSELECT id FROM application_data;\n\\connect app_test_test_2\nSELECT id FROM survivor;");
                expect($state)->toBe($before."\n42\n17");
                $identities = $psql("SELECT oid FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;");
                $restored ??= $identities;
                expect($identities)->toBe($restored);
            }
            expect($doctor->inspect($context)->issues)->toBe([]);
            expect($psql("SELECT datname || ':' || pg_get_userbyid(datdba) FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1') ORDER BY datname;"))->toBe("app_test:dlf_feature_x\napp_test_test_1:dlf_feature_x");
            expect($psql("\\connect app_test_test_1\nSELECT has_schema_privilege('dlf_feature_x', 'public', 'USAGE, CREATE')::text;\n\\connect app_test\nSELECT has_schema_privilege('dlf_feature_x', 'public', 'USAGE, CREATE')::text; SELECT rolcreatedb::text FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe("true\ntrue\nfalse");
            $this->assertDatabaseCount('database_connections', 1);
            $this->assertDatabaseCount('database_users', 1);
        });
    });

    it('refuses a different pool retry with 409 before running SQL', function (array $change): void {
        $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
        $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
        $this->postJson('/api/v1/database-connections', $request)->assertCreated();
        $this->admin->statements = [];
        $this->postJson('/api/v1/database-connections', [...$request, ...$change])->assertConflict()->assertJsonPath('error.code', 'database.slug_conflict');
        expect($this->admin->sql())->toBe('');
    })->with(['count' => [['test_processes' => 3]], 'default count' => [['test_processes' => 8]], 'locale' => [['locale' => 'C']]]);

    it('keeps partial repairs for retry and leaves a missing primary database missing', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $psql('DROP DATABASE app; DROP DATABASE app_test; DROP DATABASE app_test_test_1;');
            $fail = true;
            $admin = Mockery::mock(DatabaseServerAdmin::class);
            $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, &$fail): array {
                if ($fail && str_contains($sql, 'CREATE DATABASE "app_test_test_1"')) {
                    $fail = false;
                    throw new ResourceOperationException('database.server_command_failed', 'Simulated transport failure.', 502);
                }
                $output = $psql($sql);

                return $output === '' ? [] : explode("\n", $output);
            });
            app()->instance(DatabaseServerAdmin::class, $admin);
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $this->postJson('/api/v1/database-connections', $request)->assertStatus(502)->assertJsonPath('error.code', 'database.server_command_failed');
            expect($psql("SELECT datname FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1') ORDER BY datname;"))->toBe('app_test');
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $this->postJson('/api/v1/database-connections', $request)->assertOk();
            expect($psql("SELECT datname FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;"))->toBe("app_test\napp_test_test_1\napp_test_test_2");
        });
    });

    it('converges every member after repair is interrupted between CREATE and grants', function (string $member): void {
        postgres_statements_server(function (Closure $psql) use ($member): void {
            server_database_live_postgres($this, $psql);
            $request = ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2];
            $psql("\\connect app\nCREATE TABLE primary_data (id int); INSERT INTO primary_data VALUES (42);\n\\connect app_test_test_2\nSET ROLE dlf_feature_x; CREATE TABLE survivor (id int); INSERT INTO survivor VALUES (17);\nRESET ROLE; GRANT CREATE ON SCHEMA public TO PUBLIC; REVOKE ALL ON SCHEMA public FROM dlf_feature_x;\n\\connect postgres\nGRANT CONNECT ON DATABASE app_test_test_2 TO PUBLIC;\nDROP DATABASE {$member};");
            $primary = $psql("SELECT oid::text || ':' || datacl::text FROM pg_database WHERE datname = 'app';");
            $interrupted = true;
            $admin = Mockery::mock(DatabaseServerAdmin::class);
            $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, $member, &$interrupted): array {
                if ($interrupted && str_starts_with($sql, 'CREATE DATABASE "'.$member.'"')) {
                    $psql(explode("\n", $sql)[0]);
                    $interrupted = false;
                    throw new ResourceOperationException('database.server_command_failed', 'Interrupted before managed grants.', 502);
                }
                $output = $psql($sql);

                return $output === '' ? [] : explode("\n", $output);
            });
            app()->instance(DatabaseServerAdmin::class, $admin);
            $publicConnect = "SELECT count(*) FROM pg_database, LATERAL aclexplode(COALESCE(datacl, acldefault('d', datdba))) acl WHERE datname = '{$member}' AND acl.grantee = 0 AND acl.privilege_type = 'CONNECT';";

            $this->postJson('/api/v1/database-connections', $request)->assertStatus(502);
            expect($psql($publicConnect))->toBe('1');
            $identities = $psql("SELECT datname || ':' || oid FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;");

            foreach ([1, 2] as $attempt) {
                $this->postJson('/api/v1/database-connections', $request)->assertOk();
                expect($psql($publicConnect))->toBe('0');
                expect($psql("SELECT datname || ':' || oid FROM pg_database WHERE datname LIKE 'app%' ORDER BY datname;"))->toBe($identities);
                expect($psql("SELECT oid::text || ':' || datacl::text FROM pg_database WHERE datname = 'app';"))->toBe($primary);
                expect($psql("SELECT count(*) FROM pg_database, LATERAL aclexplode(datacl) acl WHERE datname IN ('app_test', 'app_test_test_1', 'app_test_test_2') AND acl.grantee = 0 AND acl.privilege_type = 'CONNECT';"))->toBe('0');
                expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app_test', 'app_test_test_1', 'app_test_test_2') AND pg_get_userbyid(datdba) = 'dlf_feature_x';"))->toBe('3');
                foreach (['app_test', 'app_test_test_1', 'app_test_test_2'] as $name) {
                    expect($psql("\\connect {$name}\nSELECT count(*) FROM pg_namespace, LATERAL aclexplode(nspacl) acl WHERE nspname = 'public' AND acl.grantee = (SELECT oid FROM pg_roles WHERE rolname = 'dlf_feature_x') AND acl.privilege_type IN ('USAGE', 'CREATE'); SELECT count(*) FROM pg_namespace, LATERAL aclexplode(nspacl) acl WHERE nspname = 'public' AND acl.grantee = 0 AND acl.privilege_type = 'CREATE';"))->toBe("2\n0");
                }
                expect($psql("\\connect app\nSELECT id FROM primary_data;\n\\connect app_test_test_2\nSELECT id FROM survivor;"))->toBe("42\n17");
            }
        });
    })->with(['app_test', 'app_test_test_1']);

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

    it('refuses foreign pool members with 409 before restoring anything', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $psql('DROP DATABASE app_test; ALTER DATABASE app_test_test_2 OWNER TO orbit_test;');
            $this->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $this->server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])
                ->assertConflict()->assertJsonPath('error.code', 'database.name_conflict');
            expect($psql("SELECT datname FROM pg_database WHERE datname = 'app_test';"))->toBe('');
            expect($psql("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = 'app_test_test_2';"))->toBe('orbit_test');
        });
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

    it('refuses a real PostgreSQL session on any planned database without dropping anything', function (string $busy): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($busy): void {
            server_database_live_postgres($this, $psql);
            $session = new PDO('pgsql:host='.$directory.';dbname='.$busy, 'orbit_test');

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', [$busy]);

            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $session = null;
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('0');
        });
    })->with(['app', 'app_test', 'app_test_test_2']);

    it('refuses a real logical slot before any drop whether active or inactive', function (bool $active): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($active): void {
            server_database_live_postgres($this, $psql);
            $psql("\\connect app_test_test_2
CREATE PUBLICATION orbit_removal; SELECT slot_name FROM pg_create_logical_replication_slot('orbit_removal', 'pgoutput');");
            $receiver = null;
            try {
                if ($active) {
                    $bin = rtrim(getenv('ORBIT_POSTGRES18_BIN') ?: '/usr/lib/postgresql/18/bin', '/');
                    $receiver = new SymfonyProcess([$bin.'/pg_recvlogical', '--host='.$directory, '--username=orbit_test', '--dbname=app_test_test_2', '--slot=orbit_removal', '--start', '--file=/dev/null', '--no-loop', '--option=proto_version=1', '--option=publication_names=orbit_removal', '--verbose']);
                    $receiver->start();
                    $receiver->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'streaming initiated'));
                    $psql("DO \$wait\$ BEGIN FOR attempt IN 1..100 LOOP EXIT WHEN (SELECT active FROM pg_replication_slots WHERE slot_name = 'orbit_removal'); PERFORM pg_sleep(0.01); END LOOP; END \$wait\$;");
                    $this->assertTrue($receiver->isRunning(), $receiver->getErrorOutput());
                    expect($psql("SELECT active::text FROM pg_replication_slots WHERE slot_name = 'orbit_removal';"))->toBe('true');
                }
                $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                    ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);
                server_database_assert_pool_present($psql);
                $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            } finally {
                $receiver?->stop();
            }
            $psql("SELECT pg_drop_replication_slot('orbit_removal');");
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
        });
    })->with([false, true]);

    it('refuses outside role dependencies before any drop', function (string $dependency): void {
        postgres_statements_server(function (Closure $psql) use ($dependency): void {
            server_database_live_postgres($this, $psql);
            $psql($dependency);

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use');

            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
        });
    })->with([
        'database owner' => 'CREATE DATABASE outside OWNER dlf_feature_x;',
        'table owner' => 'CREATE TABLE outside_object (id int); ALTER TABLE outside_object OWNER TO dlf_feature_x;',
        'grant' => 'GRANT CONNECT ON DATABASE postgres TO dlf_feature_x;',
        'prepared transaction' => "\\connect app_test_test_2\nBEGIN; SELECT 1; PREPARE TRANSACTION 'orbit_removal';",
        'subscription' => "\\connect app_test_test_2\nCREATE SUBSCRIPTION orbit_removal CONNECTION '' PUBLICATION orbit_removal WITH (connect = false, slot_name = NONE);",
    ]);

    it('removes an Instance set with cross-database grants before dropping its distinct owners', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            server_database_live_postgres($this, $psql);
            $this->postJson('/api/v1/database-connections', ['slug' => 'other', 'server' => $this->server->slug])->assertCreated();
            DatabaseConnection::query()->where('slug', 'other')->update(['owner_instance_id' => $this->instance->id]);
            $psql("\\connect other\nCREATE TABLE shared_table (id int); GRANT SELECT ON shared_table TO dlf_feature_x;");
            $session = new PDO('pgsql:host='.$directory.';dbname=other', 'orbit_test');

            expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
                ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.in_use'));

            server_database_assert_pool_present($psql);
            $this->assertDatabaseCount('database_connections', 2);
            $session = null;

            app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%' OR datname = 'other';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname IN ('dlf_feature_x', 'other');"))->toBe('0');
            $this->assertDatabaseCount('database_connections', 0);
        });
    });

    it('coordinates overlapping PostgreSQL removals across the complete two-server set', function (bool $contenderFirst): void {
        postgres_statements_server(function (Closure $first, string $firstDirectory) use ($contenderFirst): void {
            postgres_statements_server(function (Closure $second, string $secondDirectory) use ($first, $contenderFirst): void {
                $this->server->update(['driver' => 'pgsql', 'tag' => '18']);
                $other = $this->server->replicate()->fill(['slug' => 'second-server', 'process_id' => null, 'port' => 5433]);
                $other->save();
                $hook = null;
                $commands = [];
                $admin = Mockery::mock(DatabaseServerAdmin::class);
                $admin->shouldReceive('execute')->andReturnUsing(function (DatabaseServer $server, string $sql) use ($first, $second, &$hook, &$commands): array {
                    $commands[] = $sql;
                    $output = ($server->id === $this->server->id ? $first : $second)($sql);
                    $hook?->__invoke($server, $sql, $output);

                    return $output === '' ? [] : explode("\n", $output);
                });
                app()->instance(DatabaseServerAdmin::class, $admin);
                foreach (['app' => $this->server, 'other' => $other] as $slug => $server) {
                    $this->postJson('/api/v1/database-connections', ['slug' => $slug, 'server' => $server->slug, 'instance_id' => $this->instance->id, 'test_processes' => 2])->assertCreated();
                }
                DatabaseConnectionTarget::query()->delete();
                $connection = DatabaseConnection::query()->where('slug', 'other')->firstOrFail();
                $session = $contenderFirst ? new PDO('pgsql:host='.$secondDirectory.';dbname=other_test_test_2', 'orbit_test') : null;
                $snapshot = "SELECT datname, datallowconn, datconnlimit FROM pg_database WHERE datname LIKE 'app%' OR datname LIKE 'other%' ORDER BY datname;";
                $before = [$first($snapshot), $second($snapshot)];
                $overlapped = false;
                $commands = [];
                $hook = function (DatabaseServer $server, string $sql, string $output) use ($contenderFirst, $other, $connection, $first, $second, $secondDirectory, &$hook, &$session, &$overlapped): void {
                    if ($contenderFirst && $server->id === $other->id && str_starts_with($sql, 'SELECT DISTINCT blocker')) {
                        expect($output)->toBe('other_test_test_2');
                        $hook = null;
                        $session = null;
                        expect(fn () => app(DropOwnedDatabasesAction::class)->execute($this->instance->id))
                            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
                        $overlapped = true;
                    } elseif (! $contenderFirst && $server->id === $this->server->id && str_contains($sql, 'DROP DATABASE')) {
                        $hook = null;
                        expect($first("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
                        expect(fn () => app(DropServerDatabaseAction::class)->execute($connection))
                            ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.server_in_use'));
                        expect(fn () => new PDO('pgsql:host='.$secondDirectory.';dbname=other_test_test_2', 'orbit_test'))->toThrow(PDOException::class);
                        expect($second("SELECT count(*) FROM pg_database WHERE datname LIKE 'other%';"))->toBe('4');
                        $this->assertDatabaseCount('database_connections', 2);
                        $overlapped = true;
                    }
                };
                if ($contenderFirst) {
                    expect(fn () => app(DropServerDatabaseAction::class)->execute($connection))
                        ->toThrow(fn (ResourceOperationException $e) => expect($e->errorCode)->toBe('database.in_use'));
                    expect([$first($snapshot), $second($snapshot)])->toBe($before);
                    expect(implode("\n", $commands))->not->toContain('DROP DATABASE');
                    $this->assertDatabaseCount('database_connections', 2);
                }

                app(DropOwnedDatabasesAction::class)->execute($this->instance->id);

                expect($overlapped)->toBeTrue();
                expect([$first($snapshot), $second($snapshot)])->toBe(['', '']);
                $this->assertDatabaseCount('database_connections', 0);
                expect($second('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            });
        });
    })->with([true, false]);

    it('fences late PostgreSQL worker admission and restores exact settings on refusal', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            $session = null;
            $checks = 0;
            $connectionRefused = false;
            server_database_live_postgres($this, $psql, afterCommand: function (string $sql, string $output) use ($directory, &$session, &$checks, &$connectionRefused): void {
                if (str_starts_with($sql, 'SELECT DISTINCT blocker') && ++$checks === 1) {
                    expect($output)->toBe('');
                    $session = new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test');
                }
                if (str_contains($sql, 'INSERT INTO orbit_admin.database_removal_fences')) {
                    try {
                        new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test');
                    } catch (PDOException) {
                        $connectionRefused = true;
                    }
                }
            });
            $psql('ALTER DATABASE app ALLOW_CONNECTIONS false CONNECTION LIMIT 7; ALTER DATABASE app_test CONNECTION LIMIT 3; ALTER DATABASE app_test_test_1 ALLOW_CONNECTIONS false CONNECTION LIMIT 0;');
            $before = server_database_admission($psql);

            $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                ->assertJsonPath('error.code', 'database.in_use')->assertJsonPath('error.details.blockers', ['app_test_test_2']);

            expect($connectionRefused)->toBeTrue();
            expect(server_database_admission($psql))->toBe($before);
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            server_database_assert_pool_present($psql);
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
            $session = null;

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });

    it('keeps PostgreSQL admission closed between the final check and drops', function (): void {
        postgres_statements_server(function (Closure $psql, string $directory): void {
            $checks = 0;
            $attempted = false;
            server_database_live_postgres($this, $psql, afterCommand: function (string $sql, string $output) use ($directory, &$checks, &$attempted): void {
                if (str_starts_with($sql, 'SELECT DISTINCT blocker') && ++$checks === 2) {
                    expect($output)->toBe('');
                    $attempted = true;
                    expect(fn () => new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test'))->toThrow(PDOException::class);
                }
            });

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($attempted)->toBeTrue();
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });

    it('recovers a committed PostgreSQL fence after a crash by finishing or restoring on refusal', function (bool $busy): void {
        postgres_statements_server(function (Closure $psql, string $directory) use ($busy): void {
            server_database_live_postgres($this, $psql);
            $psql('ALTER DATABASE app ALLOW_CONNECTIONS false CONNECTION LIMIT 7; ALTER DATABASE app_test CONNECTION LIMIT 3;');
            $before = server_database_admission($psql);
            $session = $busy ? new PDO('pgsql:host='.$directory.';dbname=app_test_test_2', 'orbit_test') : null;
            $statements = new PostgresStatements;
            $psql($statements->fenceDatabases(['app', 'app_test', 'app_test_test_1', 'app_test_test_2']));
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('4');
            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%' AND datallowconn;"))->toBe('0');

            if ($busy) {
                $this->deleteJson('/api/v1/database-connections/app')->assertConflict()
                    ->assertJsonPath('error.code', 'database.in_use');
                expect(server_database_admission($psql))->toBe($before);
                expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
                server_database_assert_pool_present($psql);
                $this->assertDatabaseHas('database_connections', ['slug' => 'app']);
                $session = null;
            }
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql('SELECT count(*) FROM orbit_admin.database_removal_fences;'))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    })->with([false, true]);

    it('rolls back an uncommitted PostgreSQL fence when the admin session exits', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql);
            $before = server_database_admission($psql);
            $statements = new PostgresStatements;
            $psql(str_replace('COMMIT;', "\\quit\n", $statements->fenceDatabases(['app', 'app_test', 'app_test_test_1', 'app_test_test_2'])));

            expect(server_database_admission($psql))->toBe($before);
            expect($psql("SELECT to_regclass('orbit_admin.database_removal_fences') IS NULL;"))->toBe('t');
            $this->deleteJson('/api/v1/database-connections/app')->assertOk();
        });
    });

    it('finishes a real PostgreSQL removal after an injected halfway failure', function (): void {
        postgres_statements_server(function (Closure $psql): void {
            server_database_live_postgres($this, $psql, failHalfway: true);

            $this->deleteJson('/api/v1/database-connections/app')->assertStatus(502)
                ->assertJsonPath('error.code', 'database.server_command_failed');

            expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app', 'app_test');"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app_test_test_1', 'app_test_test_2');"))->toBe('2');
            $this->assertDatabaseHas('database_connections', ['slug' => 'app']);

            $this->deleteJson('/api/v1/database-connections/app')->assertOk();

            expect($psql("SELECT count(*) FROM pg_database WHERE datname LIKE 'app%';"))->toBe('0');
            expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('0');
            $this->assertDatabaseMissing('database_connections', ['slug' => 'app']);
        });
    });
});

/** Run the real SQL against a disposable PostgreSQL server instead of SSH. */
function server_database_live_postgres(TestCase $test, Closure $psql, bool $failHalfway = false, ?Closure $afterCommand = null): void
{
    $test->server->update(['driver' => 'pgsql', 'tag' => '18']);
    $admin = Mockery::mock(DatabaseServerAdmin::class);
    $admin->shouldReceive('execute')->andReturnUsing(static function (DatabaseServer $server, string $sql) use ($psql, &$failHalfway, $afterCommand): array {
        if ($failHalfway && str_contains($sql, 'DROP DATABASE')) {
            $failHalfway = false;
            $psql('DROP DATABASE IF EXISTS "app"; DROP DATABASE IF EXISTS "app_test";');
            throw new ResourceOperationException('database.server_command_failed', 'Simulated transport failure.', 502);
        }
        $output = $psql($sql);
        $afterCommand?->__invoke($sql, $output);

        return $output === '' ? [] : explode("\n", $output);
    });
    app()->instance(DatabaseServerAdmin::class, $admin);
    $test->postJson('/api/v1/database-connections', ['slug' => 'app', 'server' => $test->server->slug, 'instance_id' => $test->instance->id, 'test_processes' => 2])->assertCreated();
    DatabaseConnectionTarget::query()->delete();
}

function server_database_assert_pool_present(Closure $psql): void
{
    expect($psql("SELECT count(*) FROM pg_database WHERE datname IN ('app', 'app_test', 'app_test_test_1', 'app_test_test_2');"))->toBe('4');
    expect($psql("SELECT count(*) FROM pg_roles WHERE rolname = 'dlf_feature_x';"))->toBe('1');
}

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

function server_database_admission(Closure $psql): string
{
    return $psql("SELECT datname || ':' || datallowconn::text || ':' || datconnlimit FROM pg_database WHERE datname IN ('app', 'app_test', 'app_test_test_1', 'app_test_test_2') ORDER BY datname;");
}
