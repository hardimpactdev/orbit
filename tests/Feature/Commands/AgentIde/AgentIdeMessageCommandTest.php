<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

final class FakeAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public array $deliveries = [];

    public ?array $session = [
        'id' => 'sess_123',
        'status' => 'active',
    ];

    public function activeSession(array $target, string $adapter): ?array
    {
        return $this->session;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        $this->deliveries[] = compact('target', 'adapter', 'session', 'message');

        return [
            'status' => 'sent',
            'session' => $session,
        ];
    }
}

it('delivers a JSON message to an explicit app target on the gateway', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload)->toBe([
            'success' => [
                'data' => [
                    'agent_ide' => [
                        'adapter' => 'opencode',
                        'source' => 'app',
                        'target' => [
                            'app' => 'docs',
                            'workspace' => null,
                            'node' => 'app-1',
                        ],
                        'session' => [
                            'id' => 'sess_123',
                            'status' => 'active',
                        ],
                        'delivery' => [
                            'status' => 'sent',
                            'message_bytes' => 13,
                            'input' => 'argument',
                        ],
                    ],
                ],
            ],
        ])
        ->and($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['message'])->toBe('Ship the docs')
        ->and($adapter->deliveries[0]['target'])->toBe([
            'app' => 'docs',
            'workspace' => null,
            'node' => 'app-1',
        ]);
});

it('fails when the target app has no effective adapter', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'none'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'no_effective_adapter',
                'message' => 'No Agent IDE adapter is configured for docs.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => null,
                ],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('fails when the adapter cannot find an active session', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    $adapter->session = null;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'no_active_session',
                'message' => 'No active Agent IDE session found for docs.',
                'meta' => [
                    'app' => 'docs',
                    'workspace' => null,
                    'adapter' => 'opencode',
                ],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});

it('does not mutate app node or process state while messaging', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(App::query()->findOrFail($app->id)->agent_ide_config)->toBe(['adapter' => 'opencode'])
        ->and(Node::query()->findOrFail($node->id)->agent_ide_config)->toBe(['adapter' => 'polyscope'])
        ->and(DB::table('processes')->count())->toBe(0)
        ->and(DB::table('workspaces')->count())->toBe(0);
});

it('fails for non-gateway callers until gateway forwarding is implemented', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $exitCode = Artisan::call('agent-ide:message', [
        'message' => 'Ship the docs',
        '--app' => 'docs',
        '--json' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload)->toMatchArray([
            'error' => [
                'code' => 'gateway_unavailable',
                'message' => 'Gateway connection is required to send Agent IDE messages.',
                'meta' => [],
            ],
        ])
        ->and($adapter->deliveries)->toBeEmpty();
});
