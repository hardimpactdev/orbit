<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('applies websocket runtime containers through the agent-push local executor', function (): void {
    websocket_runtime_manager_fake_apply([
        'action' => 'container:apply',
        'changed' => true,
    ]);
    $node = websocket_runtime_manager_node();
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

it('re-emits could-not-verify current-image warnings onto the apply operation run', function (
    array $data,
    array $meta,
): void {
    $warning = 'Current-image verification was skipped because orbit-reverb:current could not be inspected.';
    websocket_runtime_manager_fake_apply($data, $meta);
    $node = websocket_runtime_manager_node();

    new WebSocketRuntimeContainerManager(websocket_runtime_manager_executor())
        ->apply($node, websocket_runtime_manager_container());

    $events = websocket_runtime_manager_apply_warning_events($node);

    expect($events)
        ->toHaveCount(1)
        ->and($events[0]->event_type)
        ->toBe('step')
        ->and($events[0]->payload)
        ->toMatchArray([
            'key' => 'current-image-verification',
            'status' => 'warning',
            'message' => $warning,
        ]);
})->with([
    'meta warnings' => [
        [
            'action' => 'container:apply',
            'outcome' => 'unchanged',
            'changed' => false,
            'image_verification' => 'could_not_verify',
            'warning' => 'Current-image verification was skipped because orbit-reverb:current could not be inspected.',
        ],
        [
            'warnings' => [[
                'code' => 'websocket_runtime.current_image_unverified',
                'message' => 'Current-image verification was skipped because orbit-reverb:current could not be inspected.',
            ]],
        ],
    ],
    'data warning only' => [
        [
            'action' => 'container:apply',
            'outcome' => 'unchanged',
            'changed' => false,
            'image_verification' => 'could_not_verify',
            'warning' => 'Current-image verification was skipped because orbit-reverb:current could not be inspected.',
        ],
        [],
    ],
]);

it('does not emit apply-operation warning steps when current-image verification is decisive', function (
    string $verification,
    string $outcome,
    bool $changed,
): void {
    websocket_runtime_manager_fake_apply([
        'action' => 'container:apply',
        'outcome' => $outcome,
        'changed' => $changed,
        'image_verification' => $verification,
    ]);
    $node = websocket_runtime_manager_node();

    new WebSocketRuntimeContainerManager(websocket_runtime_manager_executor())
        ->apply($node, websocket_runtime_manager_container());

    expect(websocket_runtime_manager_apply_warning_events($node))->toBeEmpty();
})->with([
    'matches' => ['matches', 'unchanged', false],
    'differs' => ['differs', 'recreated', true],
]);

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $meta
 */
function websocket_runtime_manager_fake_apply(array $data, array $meta = []): void
{
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
                    'message' =>
                        json_encode([
                            'success' => [
                                'data' => $data,
                                'meta' => $meta,
                            ],
                        ], JSON_THROW_ON_ERROR)."\n",
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
}

function websocket_runtime_manager_node(): Node
{
    return Node::factory()
        ->withActiveRole('websocket')
        ->managed()
        ->create([
            'name' => 'realtime-1',
            'wireguard_address' => '10.44.0.80',
        ]);
}

/**
 * @return Collection<int, OperationEvent>
 */
function websocket_runtime_manager_apply_warning_events(Node $node): Collection
{
    $run = OperationRun::query()
        ->where('operation_id', 'websocket-runtime-container-apply')
        ->where('target_node_id', $node->id)
        ->latest('created_at')
        ->first();

    if (! $run instanceof OperationRun) {
        return new Collection;
    }

    return OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->where('event_type', 'step')
        ->get()
        ->filter(
            static fn (OperationEvent $event): bool => (
                ($event->payload['key'] ?? null) === 'current-image-verification'
            ),
        )
        ->values();
}

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
        gatewayLocal: app(\App\Services\RemoteShell\GatewayLocalCommandDispatcher::class),
        applicationKey: websocket_runtime_manager_operation_secret(),
    );
}

function websocket_runtime_manager_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class WebSocketRuntimeContainerManagerUnusedTransport implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException(
            'SSH transport should not be called for websocket runtime container manager actions.',
        );
    }
}
