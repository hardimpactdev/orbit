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
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerManager;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies websocket runtime containers through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.80:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'websocket-runtime-container-apply',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => "{\"success\":{\"data\":{\"action\":\"container:apply\",\"changed\":true},\"meta\":[]}}\n",
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
    $node = Node::factory()
        ->withActiveRole('websocket')
        ->managed()
        ->create([
            'name' => 'realtime-1',
            'wireguard_address' => '10.44.0.80',
        ]);
    $container = websocket_runtime_manager_container();

    new WebSocketRuntimeContainerManager(websocket_runtime_manager_executor())
        ->apply($node, $container);

    Http::assertSent(function (Request $request) use ($container): bool {
        $input = json_decode((string) $request['input'], associative: true);
        $spec = is_array($input) ? $input['spec'] ?? null : null;

        return (
            $request->url() === 'http://10.44.0.80:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:websocket-runtime'
            && $request['argv'][1] === 'container:apply'
            && str_starts_with((string) $request['argv'][2], '--operation-token=')
            && $request['argv'][3] === '--json'
            && $request['timeout_seconds'] === 120
            && $request['stream'] === true
            && agentPushRequestOperationIdMatchesToken($request)
            && is_array($spec)
            && $spec['name'] === 'orbit-websocket-app-dev-1'
            && $spec['expected_hash'] === $container->specHash()
        );
    });
});

function websocket_runtime_manager_container(): WebSocketRuntimeContainer
{
    return new WebSocketRuntimeContainer(
        name: 'orbit-websocket-app-dev-1',
        image: 'orbit-reverb:current',
        network: 'orbit-network',
        restartPolicy: 'unless-stopped',
        backendName: '10.6.0.44',
        valkeyNodeId: 123,
        workingDirectory: WebSocketRuntimeContainer::SourceTarget,
        command: 'php artisan reverb:start --host=10.6.0.44 --port=8080',
        environment: [
            'REVERB_SERVER_HOST' => '10.6.0.44',
        ],
        mounts: [
            [
                'source' => '/opt/orbit/websocket/current',
                'target' => WebSocketRuntimeContainer::SourceTarget,
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit-websocket-app-dev-1'],
    );
}

function websocket_runtime_manager_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: websocket_runtime_manager_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        outputRedactor: app(\App\Services\RemoteShell\RemoteExecutorOutputRedactor::class),
        agentPush: app(\App\Services\NodeCommandTransport\NodeAgentPushDispatcher::class),
        applicationKey: websocket_runtime_manager_operation_secret(),
    );
}

function websocket_runtime_manager_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class WebSocketRuntimeContainerManagerUnusedTransport implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException(
            'SSH transport should not be called for websocket runtime container manager actions.',
        );
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('WebSocket runtime container tests do not start long-running transports.');
    }
}
