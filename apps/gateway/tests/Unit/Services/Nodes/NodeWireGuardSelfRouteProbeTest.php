<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Nodes\NodeWireGuardSelfRouteProbe;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
});

it('recognizes a Linux local WireGuard self route through loopback', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.4:9477/v1/commands' => Http::response(wireguard_self_route_response(
            exitCode: 0,
            output: "local 10.6.0.4 dev lo src 10.6.0.4 uid 1000\n",
        )),
    ]);
    $node = wireguard_self_route_node([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);

    $result = app(NodeWireGuardSelfRouteProbe::class)->probe($node);

    expect($result['ok'])
        ->toBeTrue()
        ->and($result['command'])
        ->toBe("ip route get '10.6.0.4'");

    Http::assertSent(fn (Request $request): bool => wireguard_self_route_request_matches($request, '10.6.0.4'));
});

it('recognizes an equivalent Linux local WireGuard self route', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.4:9477/v1/commands' => Http::response(wireguard_self_route_response(
            exitCode: 0,
            output: "local 10.6.0.4 dev wg-orbit table local src 10.6.0.4\n",
        )),
    ]);
    $node = wireguard_self_route_node([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);

    $result = app(NodeWireGuardSelfRouteProbe::class)->probe($node);

    expect($result['ok'])->toBeTrue();
});

it('inspects unknown node platforms instead of treating retained Linux topologies as unsupported', function (): void {
    Http::preventStrayRequests();
    ProcessFacade::preventStrayProcesses();
    ProcessFacade::fake([
        '*' => ProcessFacade::result(output: json_encode([
            'success' => [
                'data' => [
                    'exit_code' => 0,
                    'output' => "local 10.6.0.2 dev lo src 10.6.0.2 uid 1000\n",
                ],
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);
    $node = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway',
            'platform' => 'unknown',
            'wireguard_address' => '10.6.0.2',
        ]);

    $result = app(NodeWireGuardSelfRouteProbe::class)->probe($node);

    expect($result)
        ->toMatchArray([
            'ok' => true,
            'supported' => true,
            'platform' => 'unknown',
        ])
        ->and($result['command'])
        ->toBe("ip route get '10.6.0.2'");

    Http::assertNothingSent();
});

it('reports Linux WireGuard self route misses without mutating routes', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.4:9477/v1/commands' => Http::response(wireguard_self_route_response(
            exitCode: 0,
            output: "10.6.0.4 dev wg-orbit src 10.6.0.2 uid 1000\n",
        )),
    ]);
    $node = wireguard_self_route_node([
        'name' => 'app-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
    ]);

    $result = app(NodeWireGuardSelfRouteProbe::class)->probe($node);

    expect($result)
        ->toMatchArray([
            'ok' => false,
            'supported' => true,
            'reason' => 'self_route_missing',
            'message' => 'Linux node does not route its own WireGuard address locally.',
        ])
        ->and($result['command'])
        ->toBe("ip route get '10.6.0.4'")
        ->and($result['command'])
        ->not->toContain(' route add ')->and($result['command'])
        ->not->toContain(' route replace ');
});

it('reports macOS as unsupported without running route commands', function (): void {
    $node = Node::factory()->make([
        'name' => 'operator-1',
        'platform' => 'macos_15-4',
        'wireguard_address' => '10.6.0.9',
    ]);

    $result = app(NodeWireGuardSelfRouteProbe::class)->probe($node);

    expect($result)
        ->toMatchArray([
            'ok' => false,
            'supported' => false,
            'reason' => 'unsupported_platform',
            'message' => NodeWireGuardSelfRouteProbe::UnsupportedMessage,
        ]);

    Http::assertNothingSent();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function wireguard_self_route_node(array $attributes): Node
{
    return Node::factory()
        ->appDev()
        ->managed()
        ->create(array_merge($attributes, [
            'status' => 'active',
            'wireguard_address' => $attributes['wireguard_address'] ?? '10.6.0.4',
        ]));
}

/**
 * @return array<string, mixed>
 */
function wireguard_self_route_response(int $exitCode, string $output): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'wireguard-self-route.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'exit_code' => $exitCode,
                            'output' => $output,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

function wireguard_self_route_request_matches(Request $request, string $address): bool
{
    return (
        $request->url() === "http://{$address}:9477/v1/commands"
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:wireguard-self-route'
        && $request['argv'][1] === $address
    );
}
