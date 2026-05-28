<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

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

        $app = App::factory()->create([
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
        $target = DatabaseConnectionTarget::factory()->forApp($app)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect(File::get($path.'/.env'))->toContain('# Existing comment')
            ->toContain('APP_NAME=Docs')
            ->toContain('KEEP_ME=yes')
            ->toContain('DB_CONNECTION=pgsql')
            ->toContain('DB_HOST=db.internal')
            ->toContain('DB_PORT=5432')
            ->toContain('DB_DATABASE=docs')
            ->toContain('DB_USERNAME=orbit')
            ->toContain('DB_PASSWORD=secret');
    });

    it('writes remote env content through base64-safe transport', function (): void {
        $node = Node::factory()->appDev()->create(['status' => 'active']);
        $app = App::factory()->create(['node_id' => $node->id, 'name' => 'docs']);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        $connection = DatabaseConnection::factory()->create([
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => "ORBIT_ENV\"#=\nsecret"],
        ]);
        $target = DatabaseConnectionTarget::factory()->forWorkspace($workspace)->create([
            'database_connection_id' => $connection->id,
            'env_prefix' => 'DB',
        ]);
        $shell = new DatabaseConnectionRestorerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        app(DatabaseConnectionRestorer::class)->restore($target);

        expect($shell->scripts)->toHaveCount(2)
            ->and($shell->scripts[1])->not->toContain("ORBIT_ENV\"#=\nsecret")
            ->and($shell->scripts[1])->toContain('base64 -d')
            ->and($shell->scripts[1])->toContain('/srv/docs/.worktrees/feature/.env');
    });
});

final class DatabaseConnectionRestorerRemoteShell implements RemoteShell
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
