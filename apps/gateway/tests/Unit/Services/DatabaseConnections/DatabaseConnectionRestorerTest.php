<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RemoteEnvFile::class, new RemoteEnvFile(app(RemoteLocalExecutor::class)));
});

describe('DatabaseConnectionRestorer', function (): void {
    it('updates mapped env keys and preserves unrelated content', function (): void {
        $node = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-restorer-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
            # Existing comment
            APP_NAME=Docs
            DB_CONNECTION=mysql
            DB_HOST=127.0.0.1
            KEEP_ME=yes
            ENV);

        $app = databaseConnectionRestorerApp([
            'node_id' => $node->id,
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        $target = DatabaseConnectionTarget::factory()
            ->forAppInstance(databaseConnectionRestorerAppInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))
            ->toContain('# Existing comment')
            ->toContain('APP_NAME=Docs')
            ->toContain('KEEP_ME=yes')
            ->toContain('DB_CONNECTION=pgsql')
            ->toContain('DB_HOST=db.internal')
            ->toContain('DB_PORT=5432')
            ->toContain('DB_DATABASE=docs')
            ->toContain('DB_USERNAME=orbit')
            ->toContain('DB_PASSWORD=secret');
    });

    it('writes managed docker mysql service aliases on the same node as the app target', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'beast',
                'status' => 'active',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-restorer-managed-docker-mysql-alias');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=10.6.0.7\nDB_PORT=3308\n");

        $app = databaseConnectionRestorerApp([
            'node_id' => $node->id,
            'name' => 'dlf-leden',
            'path' => $path,
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'dlf-leden-mysql',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'mysql',
                    'version' => '8.4',
                    'endpoint' => [
                        'host' => '10.6.0.7',
                        'port' => 3308,
                    ],
                    'ports' => [
                        [
                            'published' => 3308,
                            'target' => 3306,
                            'protocol' => 'tcp',
                        ],
                    ],
                ],
            ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'driver' => 'mysql',
            'host' => '10.6.0.7',
            'port' => 3308,
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'credentials' => ['password' => 'secret'],
        ]);
        $target = DatabaseConnectionTarget::factory()
            ->forAppInstance(databaseConnectionRestorerAppInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))
            ->toContain('DB_HOST=dlf-leden-mysql')
            ->toContain('DB_PORT=3306')
            ->not->toContain('DB_HOST=10.6.0.7')
            ->not->toContain('DB_PORT=3308');
    });

    it('writes renamed managed docker mysql service aliases without changing the stored endpoint', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'beast',
                'status' => 'active',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-restorer-renamed-managed-docker-mysql-alias');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=dlf-leden-mysql\nDB_PORT=3306\n");

        $app = databaseConnectionRestorerApp([
            'node_id' => $node->id,
            'name' => 'dlf-leden',
            'path' => $path,
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'dlf-leden-db',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'mysql',
                    'version' => '8.4',
                    'endpoint' => [
                        'host' => '10.6.0.7',
                        'port' => 3308,
                    ],
                    'ports' => [
                        [
                            'published' => 3308,
                            'target' => 3306,
                            'protocol' => 'tcp',
                        ],
                    ],
                ],
            ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'driver' => 'mysql',
            'host' => '10.6.0.7',
            'port' => 3308,
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'credentials' => ['password' => 'secret'],
        ]);
        $target = DatabaseConnectionTarget::factory()
            ->forAppInstance(databaseConnectionRestorerAppInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))
            ->toContain('DB_HOST=dlf-leden-db')
            ->toContain('DB_PORT=3306')
            ->not->toContain('DB_HOST=dlf-leden-mysql')
            ->not->toContain('DB_HOST=10.6.0.7')->and($connection->fresh()->host)->toBe(
                '10.6.0.7',
            )->and($connection->fresh()->port)->toBe(3308);
    });

    it('writes managed database hosts as the owner node WireGuard service address', function (): void {
        $appNode = Node::factory()->gateway()->create(['status' => 'active']);
        $databaseNode = Node::factory()
            ->database()
            ->create([
                'name' => 'database-1',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-restorer-managed-host');
        File::ensureDirectoryExists($path);
        $credentialValue = substr(hash('sha256', 'restorer managed host'), 0, 16);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=localhost\n");

        $app = databaseConnectionRestorerApp([
            'node_id' => $appNode->id,
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => $credentialValue],
        ]);
        $target = DatabaseConnectionTarget::factory()
            ->forAppInstance(databaseConnectionRestorerAppInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))
            ->toContain('DB_HOST=10.6.0.7')
            ->not->toContain('DB_HOST=localhost')
            ->not->toContain('DB_HOST=postgres.orbit');
    });

    it('writes remote managed database env through base64-safe transport', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.75:9477/v1/commands' => Http::sequence()
                ->push(databaseConnectionRestorerEnvReadResponse('DB_CONNECTION=pgsql'.PHP_EOL))
                ->push(databaseConnectionRestorerEnvWriteResponse()),
        ]);
        $node = Node::factory()
            ->appDev()
            ->create([
                'status' => 'active',
                'wireguard_address' => '10.44.0.75',
            ]);
        $databaseNode = Node::factory()
            ->database()
            ->create([
                'name' => 'database-1',
                'wireguard_address' => '10.6.0.7',
            ]);
        $app = databaseConnectionRestorerApp(['node_id' => $node->id, 'name' => 'docs']);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        $credentialValue = "ORBIT_ENV\"#=\nline-two";
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => $credentialValue],
        ]);
        $target = DatabaseConnectionTarget::factory()
            ->forWorkspace($workspace)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        app(DatabaseConnectionRestorer::class)->restore($target);

        $writeRequest = collect(Http::recorded())
            ->map(fn (array $record) => $record[0])
            ->filter(fn (Request $request): bool => ($request['argv'][0] ?? null) === 'internal:env-file')
            ->last();
        $input = json_decode((string) $writeRequest['input'], true);
        $written = is_array($input) ? $input['contents'] ?? null : null;

        expect($writeRequest)
            ->toBeInstanceOf(Request::class)
            ->and($input)
            ->toMatchArray([
                'action' => 'write',
                'path' => '/srv/docs/.worktrees/feature/.env',
            ])
            ->and($written)
            ->toContain('DB_HOST=10.6.0.7')
            ->and($written)
            ->not->toContain('DB_HOST=postgres.orbit')->and($written)
            ->not->toContain('DB_HOST=127.0.0.1');
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function databaseConnectionRestorerApp(array $attributes): App
{
    $app = App::factory()->create($attributes);

    AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $app->node_id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);

    return $app;
}

function databaseConnectionRestorerAppInstance(App $app): AppInstance
{
    return $app->instances()->firstOrFail();
}

/**
 * @return array<string, mixed>
 */
function databaseConnectionRestorerEnvReadResponse(string $contents): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'env-file.read',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'contents' => $contents,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function databaseConnectionRestorerEnvWriteResponse(): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'env-file.write',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'bytes' => 10,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}
