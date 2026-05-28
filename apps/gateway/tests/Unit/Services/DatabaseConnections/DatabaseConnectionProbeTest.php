<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionProbe', function (): void {
    it('reports env missing and mismatch for an app target on a local path', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "APP_NAME=Docs\nDB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");

        $app = App::factory()->create([
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
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toHaveCount(2)
            ->and(collect($issues)->pluck('key')->all())->toBe([
                'database_connection.env_missing',
                'database_connection.env_mismatch',
            ]);
    });

    it('masks plaintext password values in mismatch details', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-secret-mismatch');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=observed-secret\n");

        $app = App::factory()->create([
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
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_mismatch');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['mismatched_keys']['DB_PASSWORD'] ?? null)->toBe('masked')
            ->and(json_encode($issue, JSON_THROW_ON_ERROR))->not->toContain('stored-secret')
            ->and(json_encode($issue, JSON_THROW_ON_ERROR))->not->toContain('observed-secret');
    });

    it('reads remote env files through remote shell for hosted workspaces', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
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
        DatabaseConnectionTarget::factory()->forWorkspace($workspace)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $shell = new DatabaseConnectionProbeRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=feature_docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBe([])
            ->and($shell->scripts)->not->toBe([])
            ->and($shell->scripts[0])->toContain("test -f '/srv/docs/.worktrees/feature/.env'")
            ->and($shell->scripts[0])->toContain("cat '/srv/docs/.worktrees/feature/.env'");
    });

    it('reports one actionable extra issue per unmapped observed supported prefix', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
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

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);
        $keys = collect($issues)->pluck('key')->all();

        expect($keys)->toContain('database_connection.env_extra')
            ->not->toContain('database_connection.target_extra')
            ->and(collect($issues)->where('key', 'database_connection.env_extra')->count())->toBe(2)
            ->and(collect($issues)->count())->toBe(2);
    });

    it('discovers custom complete prefixes as adoptable env extras', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-custom-prefix');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\nREPORTING_DB_PORT=5432\nREPORTING_DB_DATABASE=reporting\nREPORTING_DB_USERNAME=reporting\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issue = collect(app(DatabaseConnectionProbe::class)->probe($node))
            ->firstWhere('key', 'database_connection.env_extra');

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['env_prefix'] ?? null)->toBe('REPORTING_DB');
    });

    it('reports partial observed prefix groups as unverifiable instead of adoptable extras', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-partial-prefix');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "REPORTING_DB_CONNECTION=pgsql\nREPORTING_DB_HOST=reporting.internal\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $issues = collect(app(DatabaseConnectionProbe::class)->probe($node));

        expect($issues->pluck('key')->all())->toContain('database_connection.unverifiable')
            ->and($issues->pluck('key')->all())->not->toContain('database_connection.env_extra');
    });

    it('ignores non-database Laravel connection env values', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-laravel-prefixes');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "SESSION_DRIVER=database\nBROADCAST_CONNECTION=log\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        expect(app(DatabaseConnectionProbe::class)->probe($node))->toBe([]);
    });

    it('reports a missing target mapping when observed env matches an existing connection', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-target-missing');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
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

        expect($issue)->not->toBeNull()
            ->and($issue['detail']['database_connection_id'] ?? null)->toBe($connection->id)
            ->and($issue['detail']['connection'] ?? null)->toBe('docs');
    });

    it('requires sqlite node ownership when matching missing target mappings', function (): void {
        $node = Node::factory()->create(['name' => 'gateway-1', 'status' => 'active']);
        $otherNode = Node::factory()->create(['name' => 'gateway-2', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-sqlite-node');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/srv/docs/database/database.sqlite\n");

        App::factory()->create([
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

        expect($keys)->not->toContain('database_connection.target_missing')
            ->and($keys)->toContain('database_connection.env_extra');
    });

    it('uses remote shell for hosted nodes even when the same path exists locally', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'status' => 'active']);
        $path = storage_path('framework/testing/database-probe-shadowed-remote');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=local-shadow\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'remote-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $shell = new DatabaseConnectionProbeRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=pgsql\nDB_HOST=remote-host\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $issues = app(DatabaseConnectionProbe::class)->probe($node);

        expect($issues)->toBe([])
            ->and($shell->scripts)->not->toBe([]);
    });
});

final class DatabaseConnectionProbeRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}
