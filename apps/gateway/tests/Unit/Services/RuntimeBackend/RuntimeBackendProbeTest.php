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
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('reports the runtime backend as available when systemd responds through agent-push', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.84:9477/v1/commands' => runtime_backend_probe_agent_response([
            'provider' => 'systemd',
            'available' => true,
            'exit_code' => 0,
            'output' => 'systemd provider ready',
        ]),
    ]);
    $node = runtime_backend_probe_node('ubuntu_24-04');

    $result = new RuntimeBackendProbe(runtime_backend_probe_executor())
        ->check($node);

    expect($result->available)
        ->toBeTrue()
        ->and($result->exitCode)
        ->toBe(0)
        ->and($result->output)
        ->toBe('systemd provider ready');

    Http::assertSent(fn (Request $request): bool => runtime_backend_probe_request_matches(
        request: $request,
        provider: 'systemd',
    ));
});

it('reports macOS runtime readiness through launchd provider availability', function (string $platform): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.84:9477/v1/commands' => runtime_backend_probe_agent_response([
            'provider' => 'launchd',
            'available' => true,
            'exit_code' => 0,
            'output' => 'launchd provider ready',
        ]),
    ]);
    $node = runtime_backend_probe_node($platform);

    $result = new RuntimeBackendProbe(runtime_backend_probe_executor())
        ->check($node);

    expect($result->available)
        ->toBeTrue()
        ->and($result->exitCode)
        ->toBe(0)
        ->and($result->output)
        ->toBe('launchd provider ready');

    Http::assertSent(fn (Request $request): bool => runtime_backend_probe_request_matches(
        request: $request,
        provider: 'launchd',
    ));
})->with(['macos_26-5-1', 'darwin']);

it('reports the runtime backend as unavailable when systemd is missing or unreachable', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.84:9477/v1/commands' => runtime_backend_probe_agent_response([
            'provider' => 'systemd',
            'available' => false,
            'exit_code' => 127,
            'output' => 'missing systemctl',
        ]),
    ]);
    $node = runtime_backend_probe_node('ubuntu_24-04');

    $result = new RuntimeBackendProbe(runtime_backend_probe_executor())
        ->check($node);

    expect($result->available)
        ->toBeFalse()
        ->and($result->exitCode)
        ->toBe(127)
        ->and($result->output)
        ->toBe('missing systemctl');
});

function runtime_backend_probe_node(string $platform): Node
{
    return Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-1',
            'platform' => $platform,
            'wireguard_address' => '10.44.0.84',
        ]);
}

function runtime_backend_probe_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: runtime_backend_probe_gateway_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: runtime_backend_probe_gateway_secret(),
    );
}

/**
 * @param  array<string, mixed>  $data
 */
function runtime_backend_probe_agent_response(array $data): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'runtime-backend.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ]);
}

function runtime_backend_probe_request_matches(Request $request, string $provider): bool
{
    return (
        $request->url() === 'http://10.44.0.84:9477/v1/commands'
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:runtime-backend:probe'
        && $request['argv'][1] === $provider
        && str_starts_with((string) $request['argv'][2], '--operation-token=')
        && $request['argv'][3] === '--json'
        && $request['operation_id'] === 'runtime-backend.probe'
    );
}

function runtime_backend_probe_gateway_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function runtime_backend_probe_unused_transport(): RemoteExecutor
{
    return new class implements RemoteExecutor {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            throw new RuntimeException('SSH transport should not be called for runtime backend probes.');
        }

        public function start(Node $node, string $script, array $options = []): InvokedProcess
        {
            throw new RuntimeException('Runtime backend probe tests do not start long-running transports.');
        }
    };
}
