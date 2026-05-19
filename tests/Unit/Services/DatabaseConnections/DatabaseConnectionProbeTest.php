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
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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

    it('reads remote env files through remote shell for hosted workspaces', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
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
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain("test -f '/srv/docs/.worktrees/feature/.env'")
            ->and($shell->scripts[0])->toContain("cat '/srv/docs/.worktrees/feature/.env'");
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
