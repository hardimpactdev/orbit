<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Node;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\WebSockets\WebSocketRuntimeAppConfigSyncer;
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

it('syncs enabled binding credentials to each active websocket node runtime config', function (): void {
    createTestGatewayNode([
        'name' => 'gateway',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.44:9477/v1/commands' => websocket_runtime_app_config_agent_response(),
    ]);

    $websocketNode = Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'host' => 'app-dev-1.example.com',
            'managed' => true,
            'wireguard_address' => '10.6.0.44',
        ]);

    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'ws-disabled',
            'status' => 'inactive',
            'wireguard_address' => '10.6.0.45',
        ]);

    $app = App::factory()->create(['name' => 'docs']);

    AppWebSocketBinding::factory()->create([
        'app_id' => $app->id,
        'enabled' => true,
        'reverb_app_id' => 'docs',
        'reverb_app_key' => 'app-key',
        'reverb_app_secret' => 'app-secret',
        'allowed_origins' => [
            'https://docs.test',
            'https://docs.test',
            'https://api.docs.test:8443',
        ],
    ]);

    AppWebSocketBinding::factory()->create([
        'enabled' => false,
        'reverb_app_id' => 'disabled',
        'reverb_app_key' => 'disabled-key',
        'reverb_app_secret' => 'disabled-secret',
    ]);

    app(WebSocketRuntimeAppConfigSyncer::class)->sync();

    Http::assertSent(fn (Request $request): bool => websocketRuntimeAppConfigRequestMatches(
        request: $request,
        url: 'http://10.6.0.44:9477/v1/commands',
        node: $websocketNode,
    ));

    $config = websocketRuntimeAppConfigFromRequest();

    expect($config)
        ->toHaveCount(1)
        ->and($config[0])
        ->toMatchArray([
            'key' => 'app-key',
            'secret' => 'app-secret',
            'app_id' => 'docs',
            'options' => [
                'host' => 'websocket.orbit',
                'port' => 443,
                'scheme' => 'https',
                'useTLS' => true,
            ],
            'allowed_origins' => ['docs.test', 'api.docs.test'],
            'ping_interval' => 60,
            'activity_timeout' => 30,
            'max_message_size' => 10_000,
        ]);
});

it('writes an empty runtime app list when no bindings are enabled', function (): void {
    createTestGatewayNode([
        'name' => 'gateway',
        'host' => '10.6.0.1',
        'orbit_path' => '/home/orbit/orbit',
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.44:9477/v1/commands' => websocket_runtime_app_config_agent_response(),
    ]);

    Node::factory()
        ->withActiveRole('websocket')
        ->create([
            'name' => 'app-dev-1',
            'managed' => true,
            'wireguard_address' => '10.6.0.44',
        ]);

    AppWebSocketBinding::factory()->create([
        'enabled' => false,
        'reverb_app_id' => 'disabled',
    ]);

    app(WebSocketRuntimeAppConfigSyncer::class)->sync();

    expect(websocketRuntimeAppConfigFromRequest())->toBe([]);
});

/**
 * @return list<array<string, mixed>>
 */
function websocketRuntimeAppConfigFromRequest(): array
{
    $requests = Http::recorded(
        fn (Request $request): bool => ($request['operation_id'] ?? null) === 'websocket-runtime.app-config:sync',
    );

    expect($requests)->toHaveCount(1);

    /** @var Request $request */
    [$request] = $requests[0];
    /** @var mixed $input */
    $input = json_decode((string) $request['input'], associative: true, flags: JSON_THROW_ON_ERROR);

    expect($input)
        ->toBeArray()
        ->and($input['container'] ?? null)
        ->toBe('orbit-websocket-app-dev-1');

    $content = $input['content'] ?? null;
    expect($content)->toBeString();

    preg_match('/return (.*);\\n/s', $content, $returnMatches);

    expect($returnMatches[1] ?? null)->toBeString();

    /** @var list<array<string, mixed>> $config */
    $config = eval('return '.$returnMatches[1].';');

    return $config;
}

function websocketRuntimeAppConfigRequestMatches(Request $request, string $url, Node $node): bool
{
    $argv = $request['argv'] ?? null;

    return (
        $request->url() === $url
        && $node->wireguard_address === '10.6.0.44'
        && $request['binary'] === 'orbit'
        && $request['operation_id'] === 'websocket-runtime.app-config:sync'
        && is_array($argv)
        && ($argv[0] ?? null) === 'internal:websocket-runtime'
        && ($argv[1] ?? null) === 'app-config:sync'
        && str_starts_with((string) ($argv[2] ?? ''), '--operation-token=')
        && ($argv[3] ?? null) === '--json'
    );
}

function websocket_runtime_app_config_agent_response(): mixed
{
    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => 'websocket-runtime.app-config:sync',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'path' => '/etc/orbit/websocket/apps.php',
                            'bytes' => 20,
                            'restarted' => true,
                        ],
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
