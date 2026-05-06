<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const AGENT_IDE_MESSAGE_CALLER_WG_IP = '10.6.0.98';

final class FakeApiAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public array $deliveries = [];

    public function activeSession(array $target, string $adapter): ?array
    {
        return [
            'id' => 'sess_456',
            'status' => 'active',
        ];
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

function createAgentIdeMessageCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => AGENT_IDE_MESSAGE_CALLER_WG_IP,
        'wireguard_address' => AGENT_IDE_MESSAGE_CALLER_WG_IP,
    ], $overrides));
}

function grantAgentIdeMessageAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, string>  $server
 */
function postAgentIdeMessageJson(array $data, array $server = []): TestResponse
{
    return test()->call(
        'POST',
        '/api/agent-ide/message',
        $data,
        [],
        [],
        array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $server),
        json_encode($data, JSON_THROW_ON_ERROR),
    );
}

it('sends an app-target message for an authorized control caller', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'app' => 'docs',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'opencode')
        ->assertJsonPath('success.data.agent_ide.source', 'app')
        ->assertJsonPath('success.data.agent_ide.target.app', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.workspace', null)
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-1')
        ->assertJsonPath('success.data.agent_ide.session.id', 'sess_456')
        ->assertJsonPath('success.data.agent_ide.delivery.message_bytes', 13)
        ->assertJsonPath('success.data.agent_ide.delivery.input', 'argument');

    expect($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['message'])->toBe('Ship the docs');
});

it('sends an app-target message for an authorized app caller', function (): void {
    $caller = createAgentIdeMessageCallerNode(['role' => 'app']);
    $appNode = Node::factory()->create([
        'name' => 'app-2',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'app' => 'docs',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'opencode')
        ->assertJsonPath('success.data.agent_ide.target.app', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-2')
        ->assertJsonPath('success.data.agent_ide.session.id', 'sess_456');

    expect($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['message'])->toBe('Ship the docs');
});

it('sends a workspace-target message for an authorized caller', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'agent_ide_config' => ['adapter' => 'polyscope'],
    ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'agent_ide' => 'polyscope',
    ]);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'workspace' => 'feature-docs',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'polyscope')
        ->assertJsonPath('success.data.agent_ide.source', 'workspace')
        ->assertJsonPath('success.data.agent_ide.target.app', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.workspace', 'feature-docs')
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-1');

    expect($adapter->deliveries)->toHaveCount(1)
        ->and($adapter->deliveries[0]['target']['workspace'])->toBe('feature-docs');
});

it('rejects unauthorized callers without delivering', function (): void {
    createAgentIdeMessageCallerNode();
    $appNode = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'app' => 'docs',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.app', 'docs');

    expect($adapter->deliveries)->toBeEmpty();
});
