<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orbit\Sdk\Laravel\GatewayApiException;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

const AGENT_IDE_MESSAGE_CALLER_WG_IP = '10.6.0.98';

final class FakeApiAgentIdeMessageAdapter implements AgentIdeMessageAdapter
{
    public array $deliveries = [];

    public ?GatewayApiException $deliveryException = null;

    public function activeSession(array $target, string $adapter): ?array
    {
        return [
            'id' => 'sess_456',
            'status' => 'active',
        ];
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        if ($this->deliveryException instanceof GatewayApiException) {
            throw $this->deliveryException;
        }

        $this->deliveries[] = compact('target', 'adapter', 'session', 'message');

        return [
            'status' => 'sent',
            'session' => $session,
        ];
    }

    public function workspaces(array $target, string $adapter): array
    {
        return [];
    }
}

function createAgentIdeMessageCallerNode(array $overrides = [], ?string $role = null): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => AGENT_IDE_MESSAGE_CALLER_WG_IP,
        'wireguard_address' => AGENT_IDE_MESSAGE_CALLER_WG_IP,
    ], $overrides));

    if ($role !== null) {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    return $node;
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

function createAgentIdeMessageInstance(Project $project, Node $node, string $name = 'development'): AppInstance
{
    return AppInstance::factory()->for($project)->create([
        'name' => $name,
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            path: $project->path,
            document_root: $project->document_root,
            domain: $project->domain,
        ),
        'agent_ide_config' => ['adapter' => 'opencode'],
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

it('sends an instance-target message for an authorized control caller', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $project = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    createAgentIdeMessageInstance($project, $appNode);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'instance' => 'docs.development',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'opencode')
        ->assertJsonPath('success.data.agent_ide.source', 'instance')
        ->assertJsonPath('success.data.agent_ide.target.project', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.instance', 'development')
        ->assertJsonPath('success.data.agent_ide.target.workspace', null)
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-1')
        ->assertJsonPath('success.data.agent_ide.session.id', 'sess_456')
        ->assertJsonPath('success.data.agent_ide.delivery.message_bytes', 13)
        ->assertJsonPath('success.data.agent_ide.delivery.input', 'argument');

    expect($adapter->deliveries)->toHaveCount(1)->and($adapter->deliveries[0]['message'])->toBe('Ship the docs');
});

it('logs message delivery activity without storing message bodies', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $app = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    createAgentIdeMessageInstance($app, $appNode);

    app()->instance(AgentIdeMessageAdapter::class, new FakeApiAgentIdeMessageAdapter);

    postAgentIdeMessageJson([
        'message' => 'Ship the docs with sensitive context',
        'instance' => 'docs.development',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP])->assertOk();

    $entry = Activity::query()->first();

    expect($entry)->not->toBeNull();
    expect($entry->event)->toBe('api:POST /agent-ide/message');
    expect($entry->subject_type)->toBe('App\\Models\\App');
    expect($entry->subject_id)->toBe($app->id);
    expect($entry->description)->toBe('Agent IDE message sent to docs.development through opencode');
    expect($entry->properties->get('type'))->toBe('write');
    expect($entry->properties->get('target_project'))->toBe('docs');
    expect($entry->properties->get('target_instance'))->toBe('development');
    expect($entry->properties->get('target_workspace'))->toBeNull();
    expect($entry->properties->get('adapter'))->toBe('opencode');
    expect($entry->properties->get('delivery_status'))->toBe('sent');
    expect($entry->properties->toArray())->not->toHaveKey('message');
    expect(json_encode($entry->properties->toArray(), JSON_THROW_ON_ERROR))->not->toContain('sensitive context');
});

it('sends an instance-target message for an authorized app caller', function (): void {
    $caller = createAgentIdeMessageCallerNode(role: 'app-dev');
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-2',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $project = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    createAgentIdeMessageInstance($project, $appNode);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'instance' => 'docs.development',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'opencode')
        ->assertJsonPath('success.data.agent_ide.target.project', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.instance', 'development')
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-2')
        ->assertJsonPath('success.data.agent_ide.session.id', 'sess_456');

    expect($adapter->deliveries)->toHaveCount(1)->and($adapter->deliveries[0]['message'])->toBe('Ship the docs');
});

it('sends a workspace-target message for an authorized caller', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
            'agent_ide_config' => ['adapter' => 'polyscope'],
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $app = Project::factory()->create([
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

    $response
        ->assertOk()
        ->assertJsonPath('success.data.agent_ide.adapter', 'polyscope')
        ->assertJsonPath('success.data.agent_ide.source', 'workspace')
        ->assertJsonPath('success.data.agent_ide.target.project', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.instance', 'development')
        ->assertJsonPath('success.data.agent_ide.target.workspace', 'feature-docs')
        ->assertJsonPath('success.data.agent_ide.target.node', 'app-1');

    expect($adapter->deliveries)
        ->toHaveCount(1)
        ->and($adapter->deliveries[0]['target']['workspace'])
        ->toBe('feature-docs');
});

it('resolves a workspace-target message from a forwarded path', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $app = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $app->id,
        'path' => '/srv/docs/.worktrees/feature-docs',
        'agent_ide' => 'polyscope',
    ]);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'path' => '/srv/docs/.worktrees/feature-docs/nested',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response
        ->assertOk()
        ->assertJsonPath('success.data.agent_ide.source', 'workspace')
        ->assertJsonPath('success.data.agent_ide.target.project', 'docs')
        ->assertJsonPath('success.data.agent_ide.target.instance', 'development')
        ->assertJsonPath('success.data.agent_ide.target.workspace', 'feature-docs');

    expect($adapter->deliveries)
        ->toHaveCount(1)
        ->and($adapter->deliveries[0]['target']['workspace'])
        ->toBe('feature-docs');
});

it('rejects app production callers and production workspace targets before agent IDE delivery', function (): void {
    $caller = createAgentIdeMessageCallerNode(role: 'app-prod');
    $developmentNode = Node::factory()->appDev()->create(['name' => 'app-dev-1']);
    grantAgentIdeMessageAccess($caller, $developmentNode);
    $developmentApp = Project::factory()->for($developmentNode, 'node')->create([
        'name' => 'docs',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    Workspace::factory()->for($developmentApp)->create([
        'name' => 'feature-docs',
        'path' => '/srv/docs/.worktrees/feature-docs',
        'agent_ide' => 'polyscope',
    ]);
    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    foreach ([
        ['workspace' => 'feature-docs'],
        ['path' => '/srv/docs/.worktrees/feature-docs/nested'],
    ] as $target) {
        postAgentIdeMessageJson(
            ['message' => 'Ship the docs', ...$target],
            ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP],
        )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production');
    }

    $caller->roleAssignments()->delete();
    NodeRoleAssignment::factory()->create([
        'node_id' => $caller->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);
    $productionNode = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $productionApp = Project::factory()->for($productionNode, 'node')->create([
        'name' => 'shop',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    Workspace::factory()->for($productionApp)->create([
        'name' => 'legacy-workspace',
        'agent_ide' => 'opencode',
    ]);

    postAgentIdeMessageJson(
        ['message' => 'Ship the shop', 'workspace' => 'legacy-workspace'],
        ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP],
    )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'workspace.unsupported_for_production');

    expect($adapter->deliveries)->toBeEmpty();
});

it('returns adapter delivery diagnostics under error data', function (): void {
    $caller = createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
        ]);
    grantAgentIdeMessageAccess($caller, $appNode);

    $project = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    createAgentIdeMessageInstance($project, $appNode);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    $adapter->deliveryException = new GatewayApiException(
        message: 'Agent IDE adapter opencode could not deliver the message.',
        errorCode: 'adapter_delivery_failed',
        errorMeta: [
            'app' => 'docs',
            'workspace' => null,
            'adapter' => 'opencode',
        ],
        errorData: [
            'adapter_error' => [
                'message' => 'Request timed out',
            ],
        ],
    );
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'instance' => 'docs.development',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'adapter_delivery_failed')
        ->assertJsonPath('error.data.adapter_error.message', 'Request timed out')
        ->assertJsonPath('error.meta.project', 'docs')
        ->assertJsonPath('error.meta.instance', 'development')
        ->assertJsonPath('error.meta.adapter', 'opencode');
});

it('rejects unauthorized callers without delivering', function (): void {
    createAgentIdeMessageCallerNode();
    $appNode = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
        ]);

    $project = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);
    createAgentIdeMessageInstance($project, $appNode);

    $adapter = new FakeApiAgentIdeMessageAdapter;
    app()->instance(AgentIdeMessageAdapter::class, $adapter);

    $response = postAgentIdeMessageJson([
        'message' => 'Ship the docs',
        'instance' => 'docs.development',
    ], ['REMOTE_ADDR' => AGENT_IDE_MESSAGE_CALLER_WG_IP]);

    $response
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.project', 'docs')
        ->assertJsonPath('error.meta.instance', 'development')
        ->assertJsonPath('error.meta.reason', 'missing_permission')
        ->assertJsonPath('error.meta.missing_permission', 'agent-ide:message');

    expect($adapter->deliveries)->toBeEmpty();
});
