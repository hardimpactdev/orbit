<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\DatabaseConnections\DatabaseConnectionExecutor;
use App\Services\DatabaseConnections\DatabaseQueryRunner;
use App\Services\DatabaseConnections\DatabaseSchemaInspector;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe(DatabaseConnectionExecutor::class, function (): void {
    it('dispatches sqlite queries through the hidden internal local executor command without leaking connection secrets', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.77:9477/v1/commands' => Http::response([
                'transport' => 'agent-push',
                'operation_id' => 'database.query',
                'binary' => 'orbit',
                'status' => 'succeeded',
                'exit_code' => 0,
                'frames' => [
                    [
                        'type' => 'stdout',
                        'message' => json_encode([
                            'success' => [
                                'data' => [
                                    'columns' => ['id'],
                                    'rows' => [['id' => 1]],
                                ],
                                'meta' => ['mode' => 'read'],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'type' => 'exit',
                        'message' => '0',
                    ],
                ],
            ]),
        ]);
        $node = Node::factory()
            ->appDev()
            ->managed()
            ->create([
                'name' => 'app-node',
                'wireguard_address' => '10.44.0.77',
            ]);
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
        $transport = new DatabaseConnectionExecutorRecordingTransport;
        $executor = new DatabaseConnectionExecutor(
            runner: app(DatabaseQueryRunner::class),
            inspector: app(DatabaseSchemaInspector::class),
            localExecutor: databaseConnectionExecutorRemoteLocalExecutor($transport),
        );

        $result = $executor->query($connection, 'select id from users', ['limit' => 5]);

        expect($result['data']['rows'])
            ->toBe([['id' => 1]])
            ->and($transport->calls)
            ->toBeEmpty();

        Http::assertSent(function (Request $request): bool {
            $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

            expect($input['connection']['path'])
                ->toBe('/srv/docs/database/database.sqlite')
                ->and($input['connection'])
                ->not->toHaveKey('credentials')->and($input['connection'])
                ->not->toHaveKey('password')->and($input['sql'])->toBe('select id from users')->and(
                    $input['limit'],
                )->toBe(
                    5,
                )->and($input['write'])->toBeFalse();

            return (
                $request->url() === 'http://10.44.0.77:9477/v1/commands'
                && $request['binary'] === 'orbit'
                && $request['argv'][0] === 'internal:database-query-local'
                && str_starts_with((string) $request['argv'][1], '--operation-token=')
                && $request['argv'][2] === '--json'
                && ! str_contains((string) $request['input'], 'never-print-me')
            );
        });
    });
});

function databaseConnectionExecutorRemoteLocalExecutor(DatabaseConnectionExecutorRecordingTransport $transport): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
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
        applicationKey: 'gateway-secret',
    );
}

final class DatabaseConnectionExecutorRecordingTransport implements RemoteExecutor
{
    /** @var list<array{node: Node, script: string, options: array<string, mixed>}> */
    public array $calls = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => $options,
        ];

        throw new RuntimeException('SSH transport should not be used by default for database queries.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process start is not used in this test.');
    }
}
