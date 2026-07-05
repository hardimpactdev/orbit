<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RuntimeBackend\GatewayRuntimeBackendProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
});

it('reports available when the orbit-gateway container exists and is running', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.61:9477/v1/commands' => gateway_runtime_backend_agent_response([
            'runtime_status' => 'available',
            'container_exists' => true,
            'container_running' => true,
            'exit_code' => 0,
            'output' => "available\ttrue\ttrue",
        ]),
    ]);
    $node = gateway_runtime_backend_node('10.44.0.61');

    $result = new GatewayRuntimeBackendProbe()->check($node);

    expect($result->runtimeStatus)
        ->toBe('available')
        ->and($result->containerExists)
        ->toBeTrue()
        ->and($result->containerRunning)
        ->toBeTrue()
        ->and($result->exitCode)
        ->toBe(0);

    Http::assertSent(fn (Request $request): bool => gateway_runtime_backend_request_matches(
        request: $request,
        url: 'http://10.44.0.61:9477/v1/commands',
    ));
});

it('reports no_docker when Docker CLI is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.62:9477/v1/commands' => gateway_runtime_backend_agent_response([
            'runtime_status' => 'no_docker',
            'container_exists' => false,
            'container_running' => false,
            'exit_code' => 127,
            'output' => 'docker missing',
        ]),
    ]);

    $result = new GatewayRuntimeBackendProbe()->check(gateway_runtime_backend_node('10.44.0.62'));

    expect($result->runtimeStatus)
        ->toBe('no_docker')
        ->and($result->containerExists)
        ->toBeFalse()
        ->and($result->containerRunning)
        ->toBeFalse();
});

it('reports daemon_unavailable when Docker daemon is unreachable', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.63:9477/v1/commands' => gateway_runtime_backend_agent_response([
            'runtime_status' => 'daemon_unavailable',
            'container_exists' => false,
            'container_running' => false,
            'exit_code' => 1,
            'output' => 'daemon unavailable',
        ]),
    ]);

    $result = new GatewayRuntimeBackendProbe()->check(gateway_runtime_backend_node('10.44.0.63'));

    expect($result->runtimeStatus)
        ->toBe('daemon_unavailable')
        ->and($result->containerExists)
        ->toBeFalse()
        ->and($result->containerRunning)
        ->toBeFalse();
});

it('reports available with exists=false when the container is missing', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.64:9477/v1/commands' => gateway_runtime_backend_agent_response([
            'runtime_status' => 'available',
            'container_exists' => false,
            'container_running' => false,
            'exit_code' => 1,
            'output' => "available\tfalse\tfalse",
        ]),
    ]);

    $result = new GatewayRuntimeBackendProbe()->check(gateway_runtime_backend_node('10.44.0.64'));

    expect($result->runtimeStatus)
        ->toBe('available')
        ->and($result->containerExists)
        ->toBeFalse()
        ->and($result->containerRunning)
        ->toBeFalse();
});

it('reports available with running=false when the container is stopped', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.65:9477/v1/commands' => gateway_runtime_backend_agent_response([
            'runtime_status' => 'available',
            'container_exists' => true,
            'container_running' => false,
            'exit_code' => 0,
            'output' => "available\ttrue\tfalse",
        ]),
    ]);

    $result = new GatewayRuntimeBackendProbe()->check(gateway_runtime_backend_node('10.44.0.65'));

    expect($result->runtimeStatus)
        ->toBe('available')
        ->and($result->containerExists)
        ->toBeTrue()
        ->and($result->containerRunning)
        ->toBeFalse();
});

it('produces distinct drift entries per failure mode', function (): void {
    $node = new Node(['name' => 'gateway-1']);
    $probe = new GatewayRuntimeBackendProbe;

    $noDocker = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'no_docker', 'container_exists' => false, 'container_running' => false],
    ]));

    expect($noDocker)
        ->toHaveCount(1)
        ->and($noDocker[0]->key)
        ->toBe('node.docker_runtime_unavailable')
        ->and($noDocker[0]->kind->value)
        ->toBe('divergent');

    $missing = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'available', 'container_exists' => false, 'container_running' => false],
    ]));

    expect($missing)
        ->toHaveCount(1)
        ->and($missing[0]->key)
        ->toBe('node.runtime_container_missing')
        ->and($missing[0]->kind->value)
        ->toBe('missing');

    $stopped = $probe->diff($node, new ProbeSnapshot([
        'orbit-gateway' => ['runtime_status' => 'available', 'container_exists' => true, 'container_running' => false],
    ]));

    expect($stopped)
        ->toHaveCount(1)
        ->and($stopped[0]->key)
        ->toBe('node.runtime_container_stopped')
        ->and($stopped[0]->kind->value)
        ->toBe('divergent');
});

function gateway_runtime_backend_node(string $wireguardAddress): Node
{
    return createTestGatewayNode([
        'name' => 'gateway-1',
        'orbit_agent_capable' => true,
        'wireguard_address' => $wireguardAddress,
    ]);
}

/**
 * @param  array<string, mixed>  $data
 */
function gateway_runtime_backend_agent_response(array $data): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'gateway-runtime-backend.probe',
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

function gateway_runtime_backend_request_matches(Request $request, string $url): bool
{
    /** @var mixed $argv */
    $argv = $request['argv'];

    return (
        is_array($argv)
        && $request->url() === $url
        && $request['binary'] === 'orbit'
        && ($argv[0] ?? null) === 'internal:gateway-runtime-backend:probe'
        && ($argv[1] ?? null) === 'orbit-gateway'
        && is_string($argv[2] ?? null)
        && str_starts_with($argv[2], '--operation-token=')
        && ($argv[3] ?? null) === '--json'
        && $request['operation_id'] === 'gateway-runtime-backend.probe'
    );
}
