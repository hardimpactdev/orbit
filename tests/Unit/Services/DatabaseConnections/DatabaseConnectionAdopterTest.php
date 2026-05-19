<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('DatabaseConnectionAdopter', function (): void {
    it('materializes a connection and target for an existing app env and encrypts the password', function (): void {
        $node = Node::factory()->create(['role' => 'gateway', 'status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n");

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection = DatabaseConnection::query()->where('slug', 'docs')->first();

        expect($results)->toHaveCount(1)
            ->and($connection)->not->toBeNull()
            ->and($connection?->credentials)->toMatchArray(['password' => 'secret'])
            ->and(DB::table('database_connections')->where('id', $connection?->id)->value('credentials'))->not->toBe(json_encode(['password' => 'secret']))
            ->and($connection?->targets()->first()?->env_prefix)->toBe('DB');
    });

    it('materializes a workspace connection with the workspace-app slug', function (): void {
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);

        app()->instance(RemoteShell::class, new DatabaseConnectionAdopterRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_DATABASE=feature_docs\nDB_USERNAME=feature\nDB_PASSWORD=secret\n", stderr: '', durationMs: 1),
        ]));

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toHaveCount(1)
            ->and(DatabaseConnection::query()->where('slug', 'feature-docs')->exists())->toBeTrue();
    });

    it('reuses an existing app target connection instead of creating slug duplicates on rerun', function (): void {
        $node = Node::factory()->create(['role' => 'gateway', 'status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-idempotent-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\nDB_HOST=new-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=new-user\nDB_PASSWORD=new-secret\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'old-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'old-user',
            'credentials' => ['password' => 'old-secret'],
        ]);
        DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        $connection->refresh();

        expect($results)->toHaveCount(1)
            ->and(DatabaseConnection::query()->count())->toBe(1)
            ->and(DatabaseConnection::query()->where('slug', 'docs-2')->exists())->toBeFalse()
            ->and($connection)->toMatchArray([
                'driver' => 'mysql',
                'host' => 'new-host',
                'port' => 3306,
                'database' => 'docs_v2',
                'username' => 'new-user',
            ])
            ->and($connection->credentials)->toMatchArray(['password' => 'new-secret']);
    });

    it('adopts both DB and ANALYTICS_DB prefixes for the same app', function (): void {
        $node = Node::factory()->create(['role' => 'gateway', 'status' => 'active']);
        $path = storage_path('framework/testing/database-adopter-multi-prefix-app');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', <<<ENV
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

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);

        $results = app(DatabaseConnectionAdopter::class)->adopt($node);

        expect($results)->toHaveCount(2)
            ->and($app->databaseConnectionTargets()->pluck('env_prefix')->sort()->values()->all())->toBe(['ANALYTICS_DB', 'DB'])
            ->and(DatabaseConnection::query()->where('slug', 'docs')->exists())->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'docs-analytics-db')->exists())->toBeTrue();
    });
});

final class DatabaseConnectionAdopterRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(1, '', 'unexpected remote shell call', 1);
    }
}
