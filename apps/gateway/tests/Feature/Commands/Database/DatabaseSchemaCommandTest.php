<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function configureDatabaseSchemaGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);
    Node::factory()->create([
        'name' => 'gateway',

        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1',
    ]);
}

function strictDatabaseSchemaCommandPayload(): array
{
    $stdout = Artisan::output();

    expect($stdout)->toMatch('/^\{.*\}\n?$/s');

    return json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
}

describe('database schema commands', function (): void {
    it('lists tables as strict json for sqlite connections through the owning node', function (): void {
        configureDatabaseSchemaGatewayCaller();
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
        $shell = new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['name'],
                        'rows' => [['name' => 'users']],
                    ],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        ));
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('database:tables', [
            'target' => $connection->slug,
            '--json' => true,
        ]);
        $payload = strictDatabaseSchemaCommandPayload();
        $remotePayload = json_decode($shell->options['input'], true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rows'])->toBe([['name' => 'users']])
            ->and($payload['success']['meta']['connection'])->toBe('docs-db')
            ->and($shell->script)->toBe('orbit database:query-local')
            ->and($remotePayload['sql'])->toContain('sqlite_master')
            ->and($remotePayload['write'])->toBeFalse()
            ->and($remotePayload['full'])->toBeTrue();
    });

    it('lists tables in human output with the prompts table primitive', function (): void {
        configureDatabaseSchemaGatewayCaller();
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
        app()->instance(RemoteShell::class, new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['name'],
                        'rows' => [['name' => 'users']],
                    ],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $exitCode = Artisan::call('database:tables', ['target' => $connection->slug]);
        $stdout = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($stdout)->toContain('Showing 1 table(s).')
            ->and($stdout)->toContain('NAME')
            ->and($stdout)->toContain('users')
            ->and($stdout)->not->toContain('+---');
    });

    it('describes a table in human output without leaking passwords', function (): void {
        configureDatabaseSchemaGatewayCaller();
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
            'credentials' => ['password' => 'never-print-me'],
        ]);
        app()->instance(RemoteShell::class, new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'columns' => ['name', 'type'],
                        'rows' => [['name' => 'email', 'type' => 'varchar']],
                    ],
                    'meta' => ['mode' => 'read', 'returned_rows' => 1],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $exitCode = Artisan::call('database:describe', [
            'target' => $connection->slug,
            'table' => 'users',
        ]);
        $stdout = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($stdout)->toContain("Showing description for table 'users'.")
            ->and($stdout)->toContain('NAME')
            ->and($stdout)->toContain('TYPE')
            ->and($stdout)->toContain('email')
            ->and($stdout)->toContain('varchar')
            ->and($stdout)->not->toContain('+---')
            ->and($stdout)->not->toContain('never-print-me');
    });

    it('returns an error when remote sqlite schema output is mixed with logs', function (): void {
        configureDatabaseSchemaGatewayCaller();
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
        app()->instance(RemoteShell::class, new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
            exitCode: 0,
            stdout: "debug log\n".json_encode(['success' => ['data' => ['rows' => []], 'meta' => []]], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 5,
        )));

        $exitCode = Artisan::call('database:schema', [
            'target' => $connection->slug,
            '--json' => true,
        ]);
        $payload = strictDatabaseSchemaCommandPayload();

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('database_query.invalid_remote_json');
    });
});

final class DatabaseSchemaCommandRemoteShell implements RemoteShell
{
    public string $script = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function __construct(private readonly RemoteShellResult $result) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->script = $script;
        $this->options = $options;

        return $this->result;
    }
}
