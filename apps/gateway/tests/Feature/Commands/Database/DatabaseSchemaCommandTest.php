<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Security\OperationTokenSigner;

uses(RefreshDatabase::class);

function configureDatabaseSchemaGatewayCaller(): void
{
    config([
        'orbit.is_gateway' => true,
        'orbit.operation_token_secret' => 'gateway-secret',
        'orbit.operation_token_ttl_seconds' => 120,
    ]);
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
        $node = Node::factory()->appDev()->create(['name' => 'app-node']);
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
        bindDatabaseSchemaCommandLocalExecutor($shell);

        $exitCode = Artisan::call('database:tables', [
            'target' => $connection->slug,
            '--json' => true,
        ]);
        $payload = strictDatabaseSchemaCommandPayload();
        $remotePayload = json_decode($shell->options['input'], true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rows'])->toBe([['name' => 'users']])
            ->and($payload['success']['meta']['connection'])->toBe('docs-db')
            ->and($shell->script)->toContain('/usr/local/bin/orbit internal:database-query-local')
            ->and($shell->script)->not->toContain('orbit database:query-local')
            ->and($remotePayload['sql'])->toContain('sqlite_master')
            ->and($remotePayload['write'])->toBeFalse()
            ->and($remotePayload['full'])->toBeTrue();
    });

    it('lists tables in human output with the prompts table primitive', function (): void {
        configureDatabaseSchemaGatewayCaller();
        $node = Node::factory()->appDev()->create(['name' => 'app-node']);
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
        bindDatabaseSchemaCommandLocalExecutor(new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
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
        $node = Node::factory()->appDev()->create(['name' => 'app-node']);
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
        bindDatabaseSchemaCommandLocalExecutor(new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
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
        $node = Node::factory()->appDev()->create(['name' => 'app-node']);
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
        bindDatabaseSchemaCommandLocalExecutor(new DatabaseSchemaCommandRemoteShell(new RemoteShellResult(
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

function bindDatabaseSchemaCommandLocalExecutor(DatabaseSchemaCommandRemoteShell $transport): void
{
    app()->instance(RemoteLocalExecutor::class, new RemoteLocalExecutor(
        transport: $transport,
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
    ));
}

final class DatabaseSchemaCommandRemoteShell implements RemoteExecutor
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

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process start is not used in this test.');
    }
}
