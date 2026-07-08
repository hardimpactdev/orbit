<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\WorkspaceCreateFailed;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Workspaces\WorkspaceNodeReachability;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('verifies workspace target reachability through agent-push', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.92:9477/v1/commands' => workspace_reachability_agent_response(),
    ]);
    $node = createTestAppHostNode([
        'name' => 'app-1',
        'orbit_agent_capable' => true,
        'wireguard_address' => '10.44.0.92',
    ]);

    new WorkspaceNodeReachability(workspace_reachability_local_executor())
        ->ensureReachable($node);

    Http::assertSent(
        fn (Request $request): bool => workspace_reachability_request_matches(
            request: $request,
            url: 'http://10.44.0.92:9477/v1/commands',
        ),
    );
});

it('throws a transport-neutral workspace failure when reachability fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.93:9477/v1/commands' => workspace_reachability_agent_response(
            exitCode: 1,
            stderr: 'agent timeout',
        ),
    ]);
    $node = createTestAppHostNode([
        'name' => 'app-1',
        'orbit_agent_capable' => true,
        'wireguard_address' => '10.44.0.93',
    ]);

    try {
        new WorkspaceNodeReachability(workspace_reachability_local_executor())
            ->ensureReachable($node);
    } catch (WorkspaceCreateFailed $exception) {
        expect($exception->errorCode)
            ->toBe('workspace.node_unreachable')
            ->and($exception->meta)
            ->toMatchArray([
                'node' => 'app-1',
                'reason' => 'agent timeout',
            ]);

        return;
    }

    expect(false)->toBeTrue('Expected workspace reachability failure.');
});

function workspace_reachability_local_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: new class implements RemoteExecutor {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                throw new RuntimeException('SSH transport should not be called for workspace node reachability.');
            }

            public function start(Node $node, string $script, array $options = []): InvokedProcess
            {
                throw new RuntimeException('Workspace node reachability tests do not start long-running transports.');
            }
        },
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: workspace_reachability_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: workspace_reachability_operation_secret(),
    );
}

function workspace_reachability_agent_response(int $exitCode = 0, string $stderr = ''): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'workspace.node.reachable',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => $stderr === '' ? 'stdout' : 'stderr',
                'message' => $stderr,
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ]);
}

function workspace_reachability_request_matches(Request $request, string $url): bool
{
    /** @var mixed $argv */
    $argv = $request['argv'];

    return (
        $request->url() === $url
        && $request['binary'] === 'orbit'
        && is_array($argv)
        && ($argv[0] ?? null) === 'internal:executor:verify'
        && str_starts_with((string) ($argv[1] ?? ''), '--operation-token=')
        && ($argv[2] ?? null) === '--json'
        && $request['operation_id'] === 'workspace.node.reachable'
    );
}

function workspace_reachability_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}
