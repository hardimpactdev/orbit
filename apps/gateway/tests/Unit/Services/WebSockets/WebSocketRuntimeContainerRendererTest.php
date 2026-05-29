<?php

declare(strict_types=1);

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Models\Node;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\WebSockets\WebSocketBackendName;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeContainerRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function websocketRuntimeRenderer(): WebSocketRuntimeContainerRenderer
{
    return new WebSocketRuntimeContainerRenderer(
        new OrbitContainerNames,
        new WebSocketBackendName,
    );
}

function websocketRuntimeNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'ws-1',
        'host' => 'ws-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ], $overrides));
}

it('renders the stable backend name from the websocket node name', function (): void {
    $node = websocketRuntimeNode(['name' => 'ws-1']);

    expect((new WebSocketBackendName)->forNode($node))->toBe('ws-1.websocket.orbit');
});

it('renders Reverb env with a private WireGuard bind and Redis service config', function (): void {
    $node = websocketRuntimeNode();

    $env = websocketRuntimeRenderer()->env($node, new WebSocketRoleSettings(redisNodeId: 12));

    expect($env)
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('BROADCAST_CONNECTION=reverb')
        ->toContain('REVERB_SERVER_HOST=10.6.0.44')
        ->toContain('REVERB_SERVER_PORT=8080')
        ->toContain('REVERB_HOST=websocket.orbit')
        ->toContain('REVERB_PORT=443')
        ->toContain('REVERB_SCHEME=https')
        ->toContain('REVERB_SCALING_ENABLED=true')
        ->toContain('REDIS_HOST=redis.orbit')
        ->toContain('REDIS_PORT=6379')
        ->not->toContain('REVERB_SERVER_HOST=0.0.0.0')
        ->not->toContain('ws-1.example.com');
});

it('renders a deterministic WebSocket runtime container', function (): void {
    $node = websocketRuntimeNode();

    $container = websocketRuntimeRenderer()->render($node, new WebSocketRoleSettings(redisNodeId: 12));

    expect($container)->toBeInstanceOf(WebSocketRuntimeContainer::class)
        ->and($container->name())->toBe('orbit-websocket-ws-1')
        ->and($container->image())->toBe('dunglas/frankenphp:1-php8.5-bookworm')
        ->and($container->network())->toBe('orbit-network')
        ->and($container->restartPolicy())->toBe('unless-stopped')
        ->and($container->backendName())->toBe('ws-1.websocket.orbit')
        ->and($container->redisNodeId())->toBe(12)
        ->and($container->workingDirectory())->toBe('/app')
        ->and($container->command())->toBe('php artisan reverb:start --host=10.6.0.44 --port=8080 --hostname=ws-1.websocket.orbit')
        ->and($container->networkAliases())->toBe([
            'orbit-websocket-ws-1',
            'ws-1.websocket.orbit',
        ])
        ->and($container->mounts())->toContain([
            'source' => '/opt/orbit/websocket/current',
            'target' => '/app',
            'read_only' => false,
        ])
        ->and($container->environment())->toMatchArray([
            'REVERB_SERVER_HOST' => '10.6.0.44',
            'REVERB_HOST' => 'websocket.orbit',
            'REDIS_HOST' => 'redis.orbit',
        ]);
});

it('exposes labels with the spec hash and websocket backend identity', function (): void {
    $container = websocketRuntimeRenderer()->render(
        websocketRuntimeNode(),
        new WebSocketRoleSettings(redisNodeId: 12),
    );

    expect($container->labels())->toMatchArray([
        'orbit.managed' => 'true',
        'orbit.container.kind' => 'websocket-runtime',
        'orbit.websocket.backend' => 'ws-1.websocket.orbit',
    ])
        ->and($container->labels()[WebSocketRuntimeContainer::SpecHashLabel] ?? null)->toBe($container->specHash());
});

it('renders docker run with the private Reverb bind environment and shell command', function (): void {
    $container = websocketRuntimeRenderer()->render(
        websocketRuntimeNode(),
        new WebSocketRoleSettings(redisNodeId: 12),
    );

    $command = (new DockerCommandBuilder)->runDetached($container);

    expect($command)->toContain("--env 'REVERB_SERVER_HOST=10.6.0.44'")
        ->and($command)->toContain("--network-alias 'ws-1.websocket.orbit'")
        ->and($command)->toContain("--entrypoint 'sh'")
        ->and($command)->toContain("'-lc' 'php artisan reverb:start --host=10.6.0.44 --port=8080 --hostname=ws-1.websocket.orbit'")
        ->and($command)->not->toContain('0.0.0.0');
});

it('changes the spec hash when the selected Redis node changes', function (): void {
    $node = websocketRuntimeNode();

    $redisOne = websocketRuntimeRenderer()->render($node, new WebSocketRoleSettings(redisNodeId: 12));
    $redisTwo = websocketRuntimeRenderer()->render($node, new WebSocketRoleSettings(redisNodeId: 13));

    expect($redisOne->specHash())->not->toBe($redisTwo->specHash());
});

it('throws when the websocket node has no WireGuard address', function (): void {
    $node = websocketRuntimeNode(['wireguard_address' => null]);

    expect(fn () => websocketRuntimeRenderer()->env($node, new WebSocketRoleSettings(redisNodeId: 12)))
        ->toThrow(RuntimeException::class, 'The websocket role requires a WireGuard address before runtime config can be rendered.');
});
