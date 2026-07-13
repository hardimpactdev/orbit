<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteSecretFile;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('stages secrets through the agent-push local executor and removes them after use', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.40:9477/v1/commands' => Http::sequence()
            ->push(remote_secret_file_agent_response(
                operationId: 'secret-file-stage',
                data: ['path' => '/tmp/orbit-secret.abcd'],
            ))
            ->push(remote_secret_file_agent_response(
                operationId: 'secret-file-remove',
                data: ['path' => '/tmp/orbit-secret.abcd', 'removed' => true],
            )),
    ]);
    $node = remote_secret_file_node();

    $path = new RemoteSecretFile(remote_secret_file_executor())->stage(
        $node,
        'super-secret-token',
        fn (string $path): string => $path,
    );

    expect($path)->toBe('/tmp/orbit-secret.abcd');

    $requests = remote_secret_file_requests();

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[0]['argv'][0] ?? null)
        ->toBe('internal:secret-file')
        ->and($requests[0]['argv'][1] ?? null)
        ->toBe('stage')
        ->and(agentPushRequestOperationIdMatchesToken($requests[0]))
        ->toBeTrue()
        ->and(json_decode((string) $requests[0]['input'], associative: true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'content_base64' => base64_encode('super-secret-token'),
        ])
        ->and($requests[1]['argv'][0] ?? null)
        ->toBe('internal:secret-file')
        ->and($requests[1]['argv'][1] ?? null)
        ->toBe('remove')
        ->and(agentPushRequestOperationIdMatchesToken($requests[1]))
        ->toBeTrue()
        ->and(json_decode((string) $requests[1]['input'], associative: true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'path' => '/tmp/orbit-secret.abcd',
        ]);
});

it('removes the remote secret file when the callback fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.40:9477/v1/commands' => Http::sequence()
            ->push(remote_secret_file_agent_response(
                operationId: 'secret-file-stage',
                data: ['path' => '/tmp/orbit-secret.failed'],
            ))
            ->push(remote_secret_file_agent_response(
                operationId: 'secret-file-remove',
                data: ['path' => '/tmp/orbit-secret.failed', 'removed' => true],
            )),
    ]);

    expect(fn () => new RemoteSecretFile(remote_secret_file_executor())->stage(
        remote_secret_file_node(),
        'secret',
        function (): never {
            throw new RuntimeException('callback failed');
        },
    ))
        ->toThrow(RuntimeException::class, 'callback failed');

    $requests = remote_secret_file_requests();

    expect($requests)
        ->toHaveCount(2)
        ->and($requests[1]['argv'][1] ?? null)
        ->toBe('remove');
});

function remote_secret_file_node(): Node
{
    return Node::factory()
        ->withActiveRole('app-dev')
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.40',
        ]);
}

function remote_secret_file_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: remote_secret_file_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: remote_secret_file_operation_secret(),
    );
}

function remote_secret_file_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function remote_secret_file_agent_response(string $operationId, array $data): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
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
 * @return list<Request>
 */
function remote_secret_file_requests(): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === 'http://10.6.0.40:9477/v1/commands',
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

final class RemoteSecretFileUnusedTransport implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for remote secret file staging.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Remote secret file tests do not start long-running transports.');
    }
}
