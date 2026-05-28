<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

function configureDatabaseActivityGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    Node::factory()->create([
        'name' => 'gateway',

        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1',
    ]);
}

describe('database command activity logs', function (): void {
    it('logs query audit metadata without SQL rows or credentials', function (): void {
        configureDatabaseActivityGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node']);
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
            'credentials' => ['password' => 'never-log-password'],
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        app()->instance(RemoteShell::class, new DatabaseActivityLogRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['email'],
                        'rows' => [['email' => 'ada@example.test']],
                    ],
                    'meta' => [
                        'mode' => 'read',
                        'limit' => 50,
                        'returned_rows' => 1,
                        'truncated' => false,
                        'duration_ms' => 12,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $sql = 'select email from users where token = "raw-token-value"';
        $exitCode = Artisan::call('database:query', [
            'target' => 'docs',
            '--sql' => $sql,
        ]);

        $entry = Activity::query()->where('event', 'database:query')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('connection'))->toBe('docs-db')
            ->and($properties->get('driver'))->toBe('sqlite')
            ->and($properties->get('target'))->toBe('docs')
            ->and($properties->get('target_type'))->toBe('app')
            ->and($properties->get('target_name'))->toBe('docs')
            ->and($properties->get('owning_node'))->toBe('app-node')
            ->and($properties->get('operation'))->toBe('query')
            ->and($properties->get('statement_hash'))->toBe(hash('sha256', $sql))
            ->and($properties->get('statement_type'))->toBe('select')
            ->and($properties->get('statement_class'))->toBe('read')
            ->and($properties->get('mode'))->toBe('read')
            ->and($properties->get('returned_rows'))->toBe(1)
            ->and($properties->get('truncated'))->toBeFalse()
            ->and($properties->get('duration_ms'))->toBe(12)
            ->and($properties->get('exit_status'))->toBe('success')
            ->and($loggedJson)->not->toContain('select email from users')
            ->and($loggedJson)->not->toContain('raw-token-value')
            ->and($loggedJson)->not->toContain('ada@example.test')
            ->and($loggedJson)->not->toContain('never-log-password');
    });

    it('logs registry operations without passwords', function (): void {
        configureDatabaseActivityGatewayCaller();
        createTestAppHostNode(['name' => 'db-node', 'role' => 'app']);

        $exitCode = Artisan::call('database:add', [
            'slug' => 'primary-db',
            '--driver' => 'pgsql',
            '--host' => 'postgres.internal',
            '--port' => 5432,
            '--database' => 'orbit',
            '--username' => 'orbit',
            '--password' => 'never-log-password',
            '--node' => 'db-node',
        ]);

        $entry = Activity::query()->where('event', 'database:add')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('operation'))->toBe('add')
            ->and($properties->get('connection'))->toBe('primary-db')
            ->and($properties->get('driver'))->toBe('pgsql')
            ->and($properties->get('owning_node'))->toBe('db-node')
            ->and($properties->get('exit_status'))->toBe('success')
            ->and($loggedJson)->not->toContain('never-log-password');
    });

    it('logs failed registry attempts with non-secret context', function (): void {
        configureDatabaseActivityGatewayCaller();

        $exitCode = Artisan::call('database:add', [
            'slug' => 'primary-db',
            '--driver' => 'pgsql',
            '--node' => 'missing-node',
            '--password' => 'never-log-password',
        ]);

        $entry = Activity::query()->where('event', 'database:add')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('operation'))->toBe('add')
            ->and($properties->get('connection'))->toBe('primary-db')
            ->and($properties->get('driver'))->toBe('pgsql')
            ->and($properties->get('node'))->toBe('missing-node')
            ->and($properties->get('exit_status'))->toBe('failed')
            ->and($loggedJson)->not->toContain('never-log-password');
    });

    it('logs failed query resolution with statement fingerprint but not SQL text', function (): void {
        configureDatabaseActivityGatewayCaller();

        $sql = 'select email from users where token = "raw-token-value"';
        $exitCode = Artisan::call('database:query', [
            'target' => 'missing-target',
            '--sql' => $sql,
        ]);

        $entry = Activity::query()->where('event', 'database:query')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('operation'))->toBe('query')
            ->and($properties->get('target'))->toBe('missing-target')
            ->and($properties->get('statement_hash'))->toBe(hash('sha256', $sql))
            ->and($properties->get('statement_type'))->toBe('select')
            ->and($properties->get('statement_class'))->toBe('read')
            ->and($properties->get('exit_status'))->toBe('failed')
            ->and($loggedJson)->not->toContain('select email from users')
            ->and($loggedJson)->not->toContain('raw-token-value');
    });

    it('logs write affected row counts without SQL text', function (): void {
        configureDatabaseActivityGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node']);
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
        ]);
        DatabaseConnectionTarget::factory()->for($connection, 'connection')->forApp($app)->create(['env_prefix' => 'DB']);
        app()->instance(RemoteShell::class, new DatabaseActivityLogRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => ['affected_rows' => 3],
                    'meta' => ['mode' => 'write', 'duration_ms' => 15],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $sql = 'update users set remember_token = "raw-write-token"';
        $exitCode = Artisan::call('database:query', [
            'target' => 'docs',
            '--sql' => $sql,
            '--write' => true,
        ]);

        $entry = Activity::query()->where('event', 'database:query')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('type'))->toBe('write')
            ->and($properties->get('operation'))->toBe('query')
            ->and($properties->get('connection'))->toBe('docs-db')
            ->and($properties->get('statement_hash'))->toBe(hash('sha256', $sql))
            ->and($properties->get('statement_type'))->toBe('update')
            ->and($properties->get('statement_class'))->toBe('write')
            ->and($properties->get('mode'))->toBe('write')
            ->and($properties->get('affected_rows'))->toBe(3)
            ->and($properties->get('duration_ms'))->toBe(15)
            ->and($properties->get('exit_status'))->toBe('success')
            ->and($loggedJson)->not->toContain('update users')
            ->and($loggedJson)->not->toContain('raw-write-token');
    });

    it('logs schema count metadata without result rows', function (): void {
        configureDatabaseActivityGatewayCaller();
        $node = Node::factory()->create(['name' => 'app-node']);
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
        app()->instance(RemoteShell::class, new DatabaseActivityLogRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['name'],
                        'rows' => [['name' => 'users']],
                    ],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1, 'duration_ms' => 8],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $exitCode = Artisan::call('database:tables', [
            'target' => $connection->slug,
            '--json' => true,
        ]);

        $entry = Activity::query()->where('event', 'database:tables')->first();
        $properties = $entry?->properties;
        $loggedJson = json_encode($properties, JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($entry)->not->toBeNull()
            ->and($properties->get('operation'))->toBe('tables')
            ->and($properties->get('connection'))->toBe('docs-db')
            ->and($properties->get('driver'))->toBe('sqlite')
            ->and($properties->get('returned_rows'))->toBe(1)
            ->and($properties->get('duration_ms'))->toBe(8)
            ->and($properties->get('exit_status'))->toBe('success')
            ->and($loggedJson)->not->toContain('users');
    });
});

final class DatabaseActivityLogRemoteShell implements RemoteShell
{
    public function __construct(private readonly RemoteShellResult $result) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return $this->result;
    }
}
