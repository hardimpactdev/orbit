<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function configureDatabaseQueryGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);
    Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1',
    ]);
}

function strictDatabaseQueryCommandPayload(): array
{
    $stdout = Artisan::output();

    expect($stdout)->toMatch('/^\{.*\}\n?$/s');

    return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
}

describe('database:query', function (): void {
    it('runs sqlite queries on the owning node with the payload passed through stdin', function (): void {
        configureDatabaseQueryGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs-db',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
            'credentials' => ['password' => 'never-print-me'],
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        $shell = new DatabaseQueryCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['id', 'name'],
                        'rows' => [['id' => 1, 'name' => 'Ada']],
                    ],
                    'meta' => [
                        'mode' => 'read',
                        'limit' => 50,
                        'returned_rows' => 1,
                        'truncated' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('database:query', [
            'target' => 'docs',
            '--sql' => 'select id, name from users',
        ]);
        $payload = strictDatabaseQueryCommandPayload();
        $remotePayload = json_decode($shell->options['input'], true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rows'])->toBe([['id' => 1, 'name' => 'Ada']])
            ->and($payload['success']['meta']['connection'])->toBe('docs-db')
            ->and(Artisan::output())->not->toContain('+---')
            ->and($shell->node->is($node))->toBeTrue()
            ->and($shell->script)->toBe('orbit database:query-local')
            ->and($shell->options)->toHaveKey('input')
            ->and(json_encode(array_diff_key($shell->options, ['input' => true]), JSON_THROW_ON_ERROR))->not->toContain('never-print-me')
            ->and($remotePayload['connection']['path'])->toBe('/srv/docs/database/database.sqlite')
            ->and($remotePayload['connection'])->not->toHaveKey('credentials')
            ->and($remotePayload['sql'])->toBe('select id, name from users')
            ->and($remotePayload['write'])->toBeFalse()
            ->and($shell->script)->not->toContain('never-print-me')
            ->and(Artisan::output())->not->toContain('never-print-me');
    });

    it('requires an explicit connection when a target has multiple non-default mappings', function (): void {
        configureDatabaseQueryGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $primary = DatabaseConnection::factory()->create(['node_id' => $node->id, 'slug' => 'primary-db']);
        $analytics = DatabaseConnection::factory()->create(['node_id' => $node->id, 'slug' => 'analytics-db']);
        DatabaseConnectionTarget::factory()->for($primary, 'connection')->forApp($app)->create(['env_prefix' => 'PRIMARY_DB']);
        DatabaseConnectionTarget::factory()->for($analytics, 'connection')->forApp($app)->create(['env_prefix' => 'ANALYTICS_DB']);

        $exitCode = Artisan::call('database:query', [
            'target' => 'docs',
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $payload = strictDatabaseQueryCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('database_connection.ambiguous_target')
            ->and($payload['error']['meta']['connections'])->toBe(['analytics-db', 'primary-db']);
    });

    it('allows writes only when write mode is explicit', function (): void {
        configureDatabaseQueryGatewayCaller();
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $exitCode = Artisan::call('database:query', [
            'target' => $connection->slug,
            '--sql' => 'update users set name = "Changed"',
            '--json' => true,
        ]);
        $payload = strictDatabaseQueryCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('database_query.write_not_allowed');
    });

    it('requires explicit connection selectors to belong to the app target', function (): void {
        configureDatabaseQueryGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $attached = DatabaseConnection::factory()->create(['node_id' => $node->id, 'slug' => 'docs-db']);
        $other = DatabaseConnection::factory()->create(['node_id' => $node->id, 'slug' => 'other-db']);
        DatabaseConnectionTarget::factory()->for($attached, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);

        $exitCode = Artisan::call('database:query', [
            'target' => 'docs',
            '--connection' => $other->slug,
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $payload = strictDatabaseQueryCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('database_connection.target_not_found')
            ->and($payload['error']['meta']['slug'])->toBe('other-db');
    });

    it('rejects explicit connection options for direct connection targets', function (): void {
        configureDatabaseQueryGatewayCaller();
        $connection = DatabaseConnection::factory()->create(['slug' => 'primary-db']);

        $exitCode = Artisan::call('database:query', [
            'target' => $connection->slug,
            '--connection' => $connection->slug,
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $payload = strictDatabaseQueryCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('connection');
    });

    it('resolves workspace targets and explicit attached workspace connections', function (): void {
        configureDatabaseQueryGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node', 'role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        $default = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'workspace-db',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/workspaces/feature/database.sqlite',
            'username' => null,
        ]);
        $analytics = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'workspace-analytics-db',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/workspaces/feature/analytics.sqlite',
            'username' => null,
        ]);
        DatabaseConnectionTarget::factory()->for($default, 'connection')->forWorkspace($workspace)->create(['env_prefix' => 'DB']);
        DatabaseConnectionTarget::factory()->for($analytics, 'connection')->forWorkspace($workspace)->create(['env_prefix' => 'ANALYTICS_DB']);

        $defaultShell = new DatabaseQueryCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(['success' => ['data' => ['columns' => ['one'], 'rows' => [['one' => 1]]], 'meta' => []]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $defaultShell);

        $defaultExitCode = Artisan::call('database:query', [
            'target' => 'feature-docs',
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $defaultPayload = strictDatabaseQueryCommandPayload();
        $defaultRemotePayload = json_decode($defaultShell->options['input'], true, flags: JSON_THROW_ON_ERROR);

        $explicitShell = new DatabaseQueryCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(['success' => ['data' => ['columns' => ['two'], 'rows' => [['two' => 2]]], 'meta' => []]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $explicitShell);

        $explicitExitCode = Artisan::call('database:query', [
            'target' => 'feature-docs',
            '--connection' => 'workspace-analytics-db',
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $explicitPayload = strictDatabaseQueryCommandPayload();
        $explicitRemotePayload = json_decode($explicitShell->options['input'], true, flags: JSON_THROW_ON_ERROR);

        expect($defaultExitCode)->toBe(0)
            ->and($defaultPayload['success']['meta']['connection'])->toBe('workspace-db')
            ->and($defaultRemotePayload['connection']['path'])->toBe('/srv/docs/workspaces/feature/database.sqlite')
            ->and($explicitExitCode)->toBe(0)
            ->and($explicitPayload['success']['meta']['connection'])->toBe('workspace-analytics-db')
            ->and($explicitRemotePayload['connection']['path'])->toBe('/srv/docs/workspaces/feature/analytics.sqlite');
    });

    it('returns an error when remote sqlite query output is mixed with logs', function (): void {
        configureDatabaseQueryGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node', 'role' => 'app']);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'docs-db',
            'driver' => 'sqlite',
            'host' => null,
            'port' => null,
            'database' => null,
            'path' => '/srv/docs/database/database.sqlite',
            'username' => null,
        ]);
        app()->instance(RemoteShell::class, new DatabaseQueryCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: "debug log\n".json_encode(['success' => ['data' => ['rows' => []], 'meta' => []]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $exitCode = Artisan::call('database:query', [
            'target' => $connection->slug,
            '--sql' => 'select 1',
            '--json' => true,
        ]);
        $payload = strictDatabaseQueryCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('database_query.invalid_remote_json');
    });
});

final class DatabaseQueryCommandRemoteShell implements RemoteShell
{
    public Node $node;

    public string $script = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(private readonly RemoteShellResult $result) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->node = $node;
        $this->script = $script;
        $this->options = $options;

        return $this->result;
    }
}
