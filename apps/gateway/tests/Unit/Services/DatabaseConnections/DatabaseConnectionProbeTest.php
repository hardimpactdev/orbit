<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RemoteEnvFile::class, new RemoteEnvFile(app(RemoteLocalExecutor::class)));
});

/** @mago-expect lint:cyclomatic-complexity */
describe('DatabaseConnectionProbe', function (): void {
    it('does not inspect workspace database targets on production app nodes', function (): void {
        $node = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-1',
                'status' => 'active',
                'wireguard_address' => '10.44.0.91',
            ]);
        $appPath = '/srv/docs';
        $workspacePath = '/srv/docs/.worktrees/feature';
        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'environment' => 'production',
            'path' => $appPath,
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'instance_id' => databaseConnectionProbeInstance($app)->id,
            'name' => 'feature',
            'path' => $workspacePath,
        ]);
        $appConnection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs',
            'driver' => 'sqlite',
            'path' => '/srv/docs/database/database.sqlite',
        ]);
        $workspaceConnection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'feature-docs',
            'driver' => 'sqlite',
            'path' => '/srv/docs/.worktrees/feature/database/database.sqlite',
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $appConnection->id,
                'env_prefix' => 'DB',
            ]);
        DatabaseConnectionTarget::factory()
            ->forWorkspace($workspace)
            ->create([
                'database_connection_id' => $workspaceConnection->id,
                'env_prefix' => 'DB',
            ]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($appPath, $workspacePath): mixed {
            $input = json_decode((string) $request['input'], associative: true);
            $path = is_array($input) ? $input['path'] ?? null : null;
            $contents = $path === $workspacePath.'/.env'
                ? "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/.worktrees/feature/database/database.sqlite\n"
                : "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/database/database.sqlite\n";

            return Http::response(databaseConnectionProbeEnvReadResponse($contents));
        });

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBe([]);

        Http::assertSent(function (Request $request) use ($appPath): bool {
            $input = json_decode((string) $request['input'], associative: true);

            return is_array($input) && ($input['path'] ?? null) === $appPath.'/.env';
        });
        Http::assertNotSent(function (Request $request) use ($workspacePath): bool {
            $input = json_decode((string) $request['input'], associative: true);

            return is_array($input) && ($input['path'] ?? null) === $workspacePath.'/.env';
        });
    });

    it('reports env missing and mismatch for an app target on a local path', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "APP_NAME=Docs\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)
            ->toHaveCount(2)
            ->and($issues[0])
            ->toBeInstanceOf(DoctorIssue::class)
            ->and(collect($issues)->pluck('key')->all())
            ->toBe([
                'database_connection.env_missing',
                'database_connection.env_mismatch',
            ])
            ->and($issues[0]->toArray())
            ->toMatchArray([
                'node' => 'gateway-1',
                'code' => 'database_connection.env_missing',
                'disposition' => 'genuine_drift',
                'restore_action' => 'restore_database_connection_env_missing',
                'restorable' => true,
                'adoptable' => false,
            ]);
    });

    it('masks plaintext password values in mismatch details', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-secret-mismatch');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=observed-secret\n",
        );

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_mismatch');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->detail['mismatched_keys']['DB_PASSWORD'] ?? null)
            ->toBe(
                'masked',
            )
            ->and(json_encode($issue->toArray(), JSON_THROW_ON_ERROR))
            ->not->toContain('stored-secret')->and(json_encode($issue->toArray(), JSON_THROW_ON_ERROR))
            ->not->toContain('observed-secret');
    });

    it('accepts managed docker mysql service aliases on the same node as the app target', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'beast',
                'status' => 'active',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-managed-docker-mysql-alias');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=mysql\nDB_HOST=dlf-leden-mysql\nDB_PORT=3306\nDB_DATABASE=dlf_leden\nDB_USERNAME=dlf_leden\nDB_PASSWORD=secret\n",
        );

        $app = databaseConnectionProbeApp([
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
            'slug' => 'dlf-leden',
            'driver' => 'mysql',
            'host' => '10.6.0.7',
            'port' => 3308,
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBe([]);
    });

    it('accepts managed docker postgres service aliases on the same node as the app target', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'beast',
                'status' => 'active',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-managed-docker-postgres-alias');
        File::ensureDirectoryExists($path);
        $dbPassword = substr(hash('sha256', 'horizon-demo-db'), 0, 16);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=horizon-demo-postgres\nDB_PORT=5432\nDB_DATABASE=horizon\nDB_USERNAME=orbit\nDB_PASSWORD={$dbPassword}\n",
        );

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'horizon-demo',
            'path' => $path,
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'horizon-demo-postgres',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'postgres',
                    'version' => '16',
                    'endpoint' => [
                        'host' => '10.6.0.7',
                        'port' => 5433,
                    ],
                    'ports' => [
                        [
                            'published' => 5433,
                            'target' => 5432,
                            'protocol' => 'tcp',
                        ],
                    ],
                ],
            ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'horizon-demo',
            'driver' => 'pgsql',
            'host' => '10.6.0.7',
            'port' => 5433,
            'database' => 'horizon',
            'username' => 'orbit',
            'credentials' => ['password' => $dbPassword],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBeEmpty();
    });

    it('treats local sqlite connection-only and complete local sqlite env groups as not applicable', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'nmbp', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-local-sqlite-not-applicable');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
            DB_CONNECTION=sqlite
            HORIZON_DB_CONNECTION=sqlite
            HORIZON_DB_DATABASE=database/database.sqlite
            ENV);

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'mealou',
            'path' => $path,
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)
            ->toBe([])
            ->and(collect($issues)->pluck('key')->all())
            ->not->toContain('database_connection.unverifiable')
            ->not->toContain('database_connection.env_extra');
    });

    it('still reports partial non-sqlite env groups as unverifiable', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'nmbp', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-partial-pgsql-still-unverifiable');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\n");

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.unverifiable');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->detail['reason'] ?? null)
            ->toBe('partial_env_group');
    });

    it('accepts renamed managed docker mysql service aliases as the stored endpoint', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'beast',
                'status' => 'active',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-renamed-managed-docker-mysql-alias');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=mysql\nDB_HOST=dlf-leden-db\nDB_PORT=3306\nDB_DATABASE=dlf_leden\nDB_USERNAME=dlf_leden\nDB_PASSWORD=secret\n",
        );

        $app = databaseConnectionProbeApp([
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
            'slug' => 'dlf-leden',
            'driver' => 'mysql',
            'host' => '10.6.0.7',
            'port' => 3308,
            'database' => 'dlf_leden',
            'username' => 'dlf_leden',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBe([]);
    });

    it('expects managed database hosts to use the owner node WireGuard service address', function (): void {
        $appNode = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $databaseNode = Node::factory()
            ->database()
            ->create([
                'name' => 'database-1',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-managed-host');
        File::ensureDirectoryExists($path);
        $credentialValue = substr(hash('sha256', 'target missing managed host'), 0, 16);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD={$credentialValue}\n",
        );

        $app = databaseConnectionProbeApp([
            'node_id' => $appNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => $credentialValue],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($appNode);

        expect($issues)->toBe([]);
    });

    it('reports WireGuard self-route diagnostics for same-node managed database hosts', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-managed-self-route');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        ProcessFacade::preventStrayProcesses();
        ProcessFacade::fake(fn ($process) => ProcessFacade::result(output: json_encode([
            'success' => [
                'data' => [
                    'exit_code' => 0,
                    'output' => "10.6.0.7 dev wg-orbit src 10.6.0.2\n",
                ],
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR)));
        $shell = new DatabaseConnectionProbeRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.wireguard_self_route_unavailable');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->kind->value)
            ->toBe('unverifiable')
            ->and($issue->detail)
            ->toMatchArray([
                'target_type' => 'instance',
                'app' => 'docs',
                'instance' => 'development',
                'env_prefix' => 'DB',
                'connection' => 'docs',
                'node' => 'gateway-1',
                'wireguard_address' => '10.6.0.7',
                'host' => '10.6.0.7',
                'reason' => 'self_route_missing',
                'message' => 'Linux node does not route its own WireGuard address locally.',
            ])
            ->and($shell->scripts)
            ->toBe([]);

        ProcessFacade::assertRan(
            fn ($process): bool => (
                str_contains((string) $process->command, 'internal:wireguard-self-route')
                && str_contains((string) $process->command, '10.6.0.7')
            ),
        );
    });

    it('treats macOS unsupported same-node database self-route diagnostics as not applicable without drift', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-1',
                'status' => 'active',
                'platform' => 'macos_15-4',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-managed-self-route-macos');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        $shell = new DatabaseConnectionProbeRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.wireguard_self_route_unavailable');

        expect($issue)
            ->toBeNull()
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('reads remote env files through agent-push for hosted workspaces', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.73:9477/v1/commands' => Http::sequence()
                ->push(databaseConnectionProbeEnvReadResponse(
                    "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=feature_docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                ))
                ->push(databaseConnectionProbeEnvReadFailureResponse())
                ->push(databaseConnectionProbeEnvReadResponse(
                    "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=feature_docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                )),
        ]);
        $node = Node::factory()
            ->appDev()
            ->create([
                'name' => 'app-1',
                'status' => 'active',
                'wireguard_address' => '10.44.0.73',
            ]);
        $app = databaseConnectionProbeApp(['node_id' => $node->id, 'name' => 'docs']);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'feature-docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'feature_docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forWorkspace($workspace)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBe([]);

        Http::assertSent(function (Request $request): bool {
            $input = json_decode((string) $request['input'], true);

            return (
                $request->url() === 'http://10.44.0.73:9477/v1/commands'
                && $request['binary'] === 'orbit'
                && $request['argv'][0] === 'internal:env-file'
                && $input === [
                    'action' => 'read',
                    'path' => '/srv/docs/.worktrees/feature/.env',
                ]
            );
        });
    });

    it('reports one actionable extra issue per unmapped observed supported prefix', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-extra-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<'ENV'
            DB_CONNECTION=pgsql
            DB_HOST=db.internal
            DB_PORT=5432
            DB_DATABASE=docs
            DB_USERNAME=orbit
            DB_PASSWORD=secret
            ANALYTICS_DB_CONNECTION=mysql
            ANALYTICS_DB_HOST=analytics.internal
            ANALYTICS_DB_PORT=3306
            ANALYTICS_DB_DATABASE=analytics
            ANALYTICS_DB_USERNAME=analytics
            ANALYTICS_DB_PASSWORD=top-secret
            ENV);

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);
        $keys = collect($issues)->pluck('key')->all();

        expect($keys)
            ->toContain('database_connection.env_extra')
            ->not
            ->toContain('database_connection.target_extra')
            ->and(collect($issues)->where('key', 'database_connection.env_extra')->count())
            ->toBe(2)
            ->and(collect($issues)->count())
            ->toBe(2);
    });

    it('discovers custom complete prefixes as adoptable env extras', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-custom-prefix');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\nREPORTING_DB_PORT=5432\nREPORTING_DB_DATABASE=reporting\nREPORTING_DB_USERNAME=reporting\n",
        );

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_extra');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->detail['env_prefix'] ?? null)
            ->toBe('REPORTING_DB');
    });

    it('reports partial observed prefix groups as unverifiable instead of adoptable extras', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-partial-prefix');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\n");

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = collect(app(DatabaseConnectionProbe::class)->probe($node));

        expect($issues->pluck('key')->all())
            ->toContain('database_connection.unverifiable')
            ->and($issues->pluck('key')->all())
            ->not->toContain('database_connection.env_extra');
    });

    it('ignores non-database Laravel connection env values', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-laravel-prefixes');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "SESSION_DRIVER=database\nBROADCAST_CONNECTION=log\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n",
        );

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBeEmpty();
    });

    it('reports a missing target mapping when observed env matches an existing connection', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-target-missing');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.target_missing');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->detail['database_connection_id'] ?? null)
            ->toBe($connection->id)
            ->and($issue->detail['connection'] ?? null)
            ->toBe('docs');
    });

    it('matches missing target mappings for managed database hosts by owner WireGuard address', function (): void {
        $appNode = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $databaseNode = Node::factory()
            ->database()
            ->create([
                'name' => 'database-1',
                'wireguard_address' => '10.6.0.7',
            ]);
        $path = storage_path('framework/testing/database-probe-target-missing-managed-host');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=10.6.0.7\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        databaseConnectionProbeApp([
            'node_id' => $appNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $databaseNode->id,
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'postgres.orbit',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);

        $issues = collect(app(DatabaseConnectionProbe::class)->probe($appNode));

        expect($issues->pluck('key')->all())
            ->toContain('database_connection.target_missing')
            ->not->toContain('database_connection.env_extra');

        $issue = $issues->firstWhere('key', 'database_connection.target_missing');

        expect($issue)
            ->toBeInstanceOf(DoctorIssue::class)
            ->and($issue->detail['database_connection_id'] ?? null)
            ->toBe($connection->id)
            ->and($issue->detail['connection'] ?? null)
            ->toBe('docs');
    });

    it('does not treat local sqlite env as fleet env_extra even when another node owns a sqlite connection', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $otherNode = Node::factory()->gateway()->create(['status' => 'active']);
        $path = storage_path('framework/testing/database-probe-sqlite-node');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/database/database.sqlite\n");

        databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        DatabaseConnection::factory()->create([
            'node_id' => $otherNode->id,
            'slug' => 'other-docs',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
        ]);

        $keys = collect(app(DatabaseConnectionProbe::class)->probe($node))->pluck('key')->all();

        expect($keys)
            ->not->toContain('database_connection.target_missing')
            ->not->toContain('database_connection.env_extra')
            ->not->toContain('database_connection.unverifiable');
    });

    it('uses remote shell for hosted nodes even when the same path exists locally', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-shadowed-remote');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=local-shadow\n");

        $app = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $credentialValue = substr(hash('sha256', 'remote credential'), 0, 16);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'remote-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => $credentialValue],
        ]);
        DatabaseConnectionTarget::factory()
            ->forInstance(databaseConnectionProbeInstance($app))
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);

        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.74:9477/v1/commands' => Http::response(databaseConnectionProbeEnvReadResponse(
                "DB_CONNECTION=pgsql\nDB_HOST=remote-host\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD={$credentialValue}\n",
            )),
        ]);
        $node->forceFill([
            'wireguard_address' => '10.44.0.74',
        ])->save();

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBeEmpty();

        Http::assertSent(function (Request $request) use ($path): bool {
            $input = json_decode((string) $request['input'], true);

            return (
                $request->url() === 'http://10.44.0.74:9477/v1/commands'
                && $request['argv'][0] === 'internal:env-file'
                && $input === [
                    'action' => 'read',
                    'path' => $path.'/.env',
                ]
            );
        });
    });

    it('limits combined app and workspace scope to the workspace owned by that app', function (): void {
        $node = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);

        $docsFeaturePath = storage_path('framework/testing/database-probe-docs-feature');
        $billingFeaturePath = storage_path('framework/testing/database-probe-billing-feature');
        File::ensureDirectoryExists($docsFeaturePath);
        File::ensureDirectoryExists($billingFeaturePath);
        File::put($docsFeaturePath.'/.env', "DB_CONNECTION=mysql\n");
        File::put($billingFeaturePath.'/.env', "DB_CONNECTION=mysql\n");

        $docs = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => storage_path('framework/testing/database-probe-docs-root'),
        ]);
        $billing = databaseConnectionProbeApp([
            'node_id' => $node->id,
            'name' => 'billing',
            'path' => storage_path('framework/testing/database-probe-billing-root'),
        ]);

        $docsFeature = Workspace::factory()->create([
            'app_id' => $docs->id,
            'name' => 'feature',
            'path' => $docsFeaturePath,
        ]);
        $billingFeature = Workspace::factory()->create([
            'app_id' => $billing->id,
            'name' => 'feature',
            'path' => $billingFeaturePath,
        ]);

        $fixturePassword = substr(hash('sha256', 'database-probe combined app workspace scope'), 0, 16);

        foreach ([$docsFeature, $billingFeature] as $workspace) {
            $connection = DatabaseConnection::factory()->create([
                'slug' => $workspace->name.'-'.$workspace->app?->name,
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => $workspace->app?->name,
                'username' => 'orbit',
                'credentials' => ['password' => $fixturePassword],
            ]);
            DatabaseConnectionTarget::factory()
                ->forWorkspace($workspace)
                ->create([
                    'database_connection_id' => $connection->id,
                    'env_prefix' => 'DB',
                ]);
        }

        $issues = app(DatabaseConnectionProbe::class)->probe(
            $node,
            DoctorTargetScope::from('docs', 'feature'),
        );

        expect($issues)
            ->not
            ->toBeEmpty()
            ->and(collect($issues)->pluck('detail.workspace')->filter()->unique()->values()->all())
            ->toBe(['feature'])
            ->and(collect($issues)->pluck('detail.app')->filter()->unique()->values()->all())
            ->toBe(['docs']);
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function databaseConnectionProbeApp(array $attributes): App
{
    $app = App::factory()->create($attributes);

    Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $app->node_id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);

    return $app;
}

function databaseConnectionProbeInstance(App $app): Instance
{
    return $app->instances()->firstOrFail();
}

final class DatabaseConnectionProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}

/**
 * @return array<string, mixed>
 */
function databaseConnectionProbeEnvReadResponse(string $contents): array
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
                            'path' => '/srv/docs/.env',
                            'contents' => $contents,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function databaseConnectionProbeEnvReadFailureResponse(): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'env-file.read',
        'binary' => 'orbit',
        'status' => 'failed',
        'exit_code' => 1,
        'frames' => [
            [
                'type' => 'stderr',
                'message' => 'Env file was not found.',
            ],
            [
                'type' => 'exit',
                'message' => '1',
            ],
        ],
    ];
}
