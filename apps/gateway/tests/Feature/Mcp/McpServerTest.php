<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEdgeProjector;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\FakePublicRouteEdgeProjector;
use Tests\Support\FakeRouteRemovalProjector;
use Tests\Support\Orb101ProjectUpdateFixture;
use Tests\Support\Orb220DeploymentApiFixture;
use Tests\Support\TaskModelRouter;

/** @param array<string, mixed> $params */
function mcp_call(mixed $test, string $method, array $params = [], string $endpoint = '/mcp'): TestResponse
{
    return $test->postJson($endpoint, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/**
 * The JSON-RPC message of a reply, whether it arrived as one JSON document or as a server-sent event stream.
 *
 * @return array<string, mixed>
 */
function mcp_message(TestResponse $response): array
{
    if (! $response->baseResponse instanceof StreamedResponse) {
        return $response->json();
    }

    preg_match_all('/^data: (.+)$/m', $response->streamedContent(), $matches);

    return json_decode((string) end($matches[1]), true);
}

/**
 * The decoded tool result of one call, sent directly to /mcp or through `execute_tools`.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function mcp_tool(mixed $test, string $endpoint, string $tool, array $arguments): array
{
    $call = $endpoint === '/mcp'
        ? ['name' => $tool, 'arguments' => $arguments]
        : ['name' => 'execute_tools', 'arguments' => ['calls' => [['name' => $tool, 'arguments' => $arguments]]]];

    ob_start();

    try {
        $message = mcp_message(mcp_call($test, 'tools/call', $call, $endpoint));
    } finally {
        ob_end_clean();
    }

    $payload = json_decode($message['result']['content'][0]['text'], true);

    if ($endpoint !== '/mcp') {
        expect($payload['ok'] ?? null)->toBeTrue((string) json_encode($payload['error'] ?? null));
        $payload = json_decode($payload['results'][0]['content'][0]['text'], true);
    }

    return $payload;
}

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

describe('POST /mcp', function (): void {
    it('repairs a stale repository identity through project-update with an unchanged URL', function (): void {
        $fixture = Orb101ProjectUpdateFixture::bind($this);
        $repository = 'https://github.com/hardimpactdev/orbit.git';
        DB::table('projects')->where('id', $fixture->project->id)->update([
            'repository_url' => $repository,
            'repository_identity' => 'github.com/nckrtl/orbit',
        ]);

        $result = mcp_tool($this, '/mcp', 'project-update', [
            'project' => $fixture->project->id,
            'repository_url' => $repository,
        ]);

        expect($result['data']['repository_url'])->toBe($repository);
        $this->assertDatabaseHas('projects', [
            'id' => $fixture->project->id,
            'repository_url' => $repository,
            'repository_identity' => 'github.com/hardimpactdev/orbit',
        ]);
        expect($fixture->sources->originMutations)->toBeEmpty();
    });

    it('answers the MCP handshake with the server identity and operating instructions', function (): void {
        $response = mcp_call($this, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]);

        $response->assertOk();

        expect($response->json('result.serverInfo.name'))->toBe('Orbit Gateway')
            ->and($response->json('result.capabilities.tools'))->not->toBeNull()
            ->and($response->json('result.instructions'))->toContain('WireGuard', 'operation-show', 'after_sequence')
            ->and($response->json('result.instructions'))->toContain('Each tool that starts an operation')
            ->and($response->json('result.instructions'))->not->toContain('`events`');
    });

    it('lists one tool for every manifest entry', function (): void {
        TaskModelRouter::ready();
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
        $this->postJson('/api/v1/extensions/documents/enable')->assertOk();
        $this->postJson('/api/v1/extensions/vault/enable')->assertOk();
        $this->postJson('/api/v1/extensions/conn/enable')->assertOk();
        $names = [];
        $cursor = null;

        do {
            $response = mcp_call($this, 'tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $response->assertOk();
            $names = [...$names, ...array_column($response->json('result.tools'), 'name')];
            $cursor = $response->json('result.nextCursor');
        } while (is_string($cursor));

        $manifest = json_decode((string) file_get_contents(resource_path('mcp/tools.json')), true);

        expect($response->json('result.nextCursor'))->toBeNull('The catalogue must fit in one page.')
            ->and($names)->toEqualCanonicalizing(array_column($manifest['tools'], 'name'))
            ->and($names)->toContain('node-list', 'instance-deploy', 'process-restart');
    });

    it('runs a read tool as the calling peer and returns the API document', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);

        $response->assertOk();
        expect($response->json('result.isError'))->toBeFalse();

        $document = json_decode($response->json('result.content.0.text'), true);

        expect(array_column($document['data'], 'name'))->toContain('gateway')
            ->and($document['meta']['request_id'])->toBeString();
    });

    it('places a DELETE path parameter from the tool arguments onto the Route', function (): void {
        app()->instance(RouteRemovalProjector::class, new FakeRouteRemovalProjector);
        $project = Project::query()->create([
            'name' => 'MCP routes',
            'slug' => 'mcp-routes',
            'repository_url' => 'https://example.test/mcp-routes.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $node = Node::query()->create([
            'name' => 'mcp-route-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'mcp.test',
            'public_ssh_host' => '192.0.2.40',
            'wireguard_ip' => '10.44.0.40',
            'user' => 'orbit',
        ]);
        $routeId = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'domain' => 'mcp-destroy.example.test',
            'provenance' => 'explicit',
            'publication' => 'private',
            'status' => 'pending',
        ])->id;

        $response = mcp_call($this, 'tools/call', [
            'name' => 'route-destroy',
            'arguments' => ['route' => $routeId],
        ]);
        $document = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($document['data']['id'])->toBe($routeId)
            ->and(Route::query()->whereKey($routeId)->exists())->toBeFalse();
    });

    it('places path, query, and body inputs where the operation expects them', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'node-show', 'arguments' => ['node' => $this->gateway->id]]);

        $document = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeFalse()
            ->and($document['data']['name'])->toBe('gateway');
    });

    it('returns the API error envelope when validation fails', function (): void {
        $response = mcp_call($this, 'tools/call', ['name' => 'project-create', 'arguments' => ['slug' => 'Not A Slug', 'type' => 'laravel-app']]);

        $error = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($error['status'])->toBe(422)
            ->and($error['error']['code'])->toBeString();
    });

    it('replays operation starts through the generated Idempotency-Key header argument', function (string $tool, array $input): void {
        enable_operation_queue();
        $fixture = Orb220DeploymentApiFixture::create();
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);
        $arguments = ['instance' => $fixture->instance->id, 'Idempotency-Key' => 'mcp.stage.c1', ...$input];

        $first = mcp_tool($this, '/mcp', $tool, $arguments);
        $second = mcp_tool($this, '/mcp', $tool, $arguments);

        expect($second['operation']['id'])->toBe($first['operation']['id']);
        $this->assertDatabaseHas('operations', ['id' => $first['operation']['id'], 'idempotency_key' => 'mcp.stage.c1']);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['deploy' => ['instance-deploy', []], 'rollback' => ['instance-rollback', ['release' => 'initial']]]);

    it('replays a WebSocket app tool through its generated header argument', function (): void {
        $arguments = ['name' => 'mcp-app', 'Idempotency-Key' => 'mcp.websocket.create'];

        $first = mcp_tool($this, '/mcp', 'websocket-app-create', $arguments);
        $second = mcp_tool($this, '/mcp', 'websocket-app-create', $arguments);

        expect($first['operation']['id'])->toBeString()
            ->and($second['operation']['id'])->toBe($first['operation']['id']);
        $this->assertDatabaseHas('websocket_registry_operations', ['id' => $first['operation']['id'], 'idempotency_key' => 'mcp.websocket.create']);
        $this->assertDatabaseCount('websocket_apps', 1);
        $this->assertDatabaseCount('websocket_registry_operations', 1);
    });

    it('starts and replays task provision through the generated tool', function (string $endpoint): void {
        enable_operation_queue();
        app(TaskExtensionState::class)->enable();
        $project = Project::query()->create(['name' => 'Provision', 'slug' => 'provision', 'repository_url' => 'git@github.com:acme/provision.git']);
        $group = Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Provision.',
            'status' => 'todo', 'orchestration' => 'external',
        ]);
        $arguments = ['group' => $group->id, 'Idempotency-Key' => 'mcp.provision.c1'];

        $first = mcp_tool($this, $endpoint, 'tasks-provision', $arguments);
        $group->update(['status' => 'running']);
        $second = mcp_tool($this, $endpoint, 'tasks-provision', $arguments);

        expect($first['operation']['kind'])->toBe('tasks.provision');
        expect($first['poll']['tool'])->toBe('operation-show');
        expect($second['operation']['id'])->toBe($first['operation']['id']);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['/mcp', '/mcp/search']);

    it('starts and replays task check through its generated MCP tool', function (string $endpoint): void {
        enable_operation_queue();
        tick_bind_defaults();
        app(TaskExtensionState::class)->enable();
        $group = tick_group();
        Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'external']);
        $arguments = ['group' => $group->id, 'subtask' => $group->tasks()->firstOrFail()->id, 'phase' => 'handoff', 'Idempotency-Key' => 'mcp.check.c1'];

        $first = mcp_tool($this, $endpoint, 'tasks-check', $arguments);
        $second = mcp_tool($this, $endpoint, 'tasks-check', $arguments);

        expect($first['operation']['kind'])->toBe('tasks.check');
        expect($first['operation']['input'])->toBe(['subtask' => $arguments['subtask'], 'phase' => 'handoff']);
        expect($first['poll']['tool'])->toBe('operation-show');
        expect($second['operation']['id'])->toBe($first['operation']['id']);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('jobs', 1);
    })->with(['/mcp', '/mcp/search']);

    it('starts a deploy at once and lets the agent poll it with operation-show', function (string $endpoint): void {
        enable_operation_queue();
        $fixture = Orb220DeploymentApiFixture::create();
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);

        $started = mcp_tool($this, $endpoint, 'instance-deploy', ['instance' => $fixture->instance->id]);

        expect(array_keys($started))->toBe(['operation', 'poll', 'meta'])
            ->and($started['operation']['status'])->toBe('queued')
            ->and($started['operation']['kind'])->toBe('instance.deploy')
            ->and($started['poll'])->toBe(['tool' => 'operation-show', 'after_seconds' => 2])
            ->and($fixture->deployment->invocations)->toBe(0);

        run_operation_jobs();
        $shown = mcp_tool($this, $endpoint, 'operation-show', ['operation' => $started['operation']['id'], 'after_sequence' => $started['operation']['sequence']]);
        $listed = mcp_tool($this, $endpoint, 'operation-list', ['status' => 'succeeded']);

        expect($shown['data']['status'])->toBe('succeeded')
            ->and($shown['data']['poll_after_seconds'])->toBeNull()
            ->and($shown['data']['result']['selected_release'])->toBe('fresh')
            ->and(array_column($shown['data']['steps'], 'name'))->toContain('source_preparation', 'cleanup')
            ->and(array_column($listed['data'], 'id'))->toBe([$started['operation']['id']]);
    })->with(['/mcp', '/mcp/search']);

    it('starts a rollback at once and lets the agent poll it with operation-show', function (string $endpoint): void {
        enable_operation_queue();
        $fixture = Orb220DeploymentApiFixture::create();
        $fixture->deployment->current = 'fresh';
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->caller->wireguard_ip]);

        $started = mcp_tool($this, $endpoint, 'instance-rollback', ['instance' => $fixture->instance->id, 'release' => 'initial']);

        expect(array_keys($started))->toBe(['operation', 'poll', 'meta'])
            ->and($started['operation']['status'])->toBe('queued')
            ->and($started['operation']['kind'])->toBe('instance.rollback')
            ->and($started['operation']['input'])->toBe(['release' => 'initial'])
            ->and($started['poll'])->toBe(['tool' => 'operation-show', 'after_seconds' => 2])
            ->and($fixture->deployment->invocations)->toBe(0);

        run_operation_jobs();
        $shown = mcp_tool($this, $endpoint, 'operation-show', ['operation' => $started['operation']['id']]);

        expect($shown['data']['status'])->toBe('succeeded')
            ->and($shown['data']['result']['selected_release'])->toBe('initial')
            ->and(array_column($shown['data']['steps'], 'name'))->toBe(['rollback', 'activation', 'php_refresh', 'process_restart']);
    })->with(['/mcp', '/mcp/search']);

    it('refuses a caller that is not an active WireGuard peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        mcp_call($this, 'tools/list')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('enforces directed node access inside the tool call', function (): void {
        $peer = Node::query()->create([
            'name' => 'peer',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);

        $response = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);
        $error = json_decode($response->json('result.content.0.text'), true);

        expect($response->json('result.isError'))->toBeTrue()
            ->and($error['status'])->toBe(403);
    });
});

describe('/mcp tool list changes', function (): void {
    beforeEach(function (): void {
        $initialized = mcp_call($this, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]);

        $initialized->assertOk();
        expect($initialized->json('result.capabilities.tools.listChanged'))->toBeFalse();

        // The tool list changes after the client connected. The client still sends the session id it holds.
        TaskModelRouter::ready();
        $this->postJson('/api/v1/extensions/tasks/enable')->assertOk();
        $this->withHeader('Mcp-Session-Id', '0123456789abcdef.0123456789abcdef0123456789abcdef');
    });

    it('keeps serving a session opened under an older tool list', function (): void {
        $called = mcp_call($this, 'tools/call', ['name' => 'node-list', 'arguments' => (object) []]);

        $called->assertOk();
        expect($called->json('result.isError'))->toBeFalse()
            ->and(Activity::query()->where('command', 'node:list')->exists())->toBeTrue()
            ->and(array_column(mcp_call($this, 'tools/list')->assertOk()->json('result.tools'), 'name'))->toContain('tasks-create');
    });

    it('answers a call to a removed tool with a JSON-RPC error, not an ended session', function (): void {
        $called = mcp_call($this, 'tools/call', ['name' => 'node-list-removed', 'arguments' => (object) []]);

        // HTTP 400 carries the JSON-RPC error. A 404 would tell the client that its session ended.
        $called->assertStatus(400);
        expect($called->json('error.code'))->toBe(-32602)
            ->and($called->json('error.message'))->toContain('Tool [node-list-removed] not found.', 'list the tools again');
    });
});

describe('POST /mcp/search', function (): void {
    it('offers the catalogue through search_tools and execute_tools', function (): void {
        $tools = mcp_message(mcp_call($this, 'tools/list', [], '/mcp/search'));

        expect(array_column($tools['result']['tools'], 'name'))->toEqualCanonicalizing(['search_tools', 'execute_tools']);

        $found = mcp_message(mcp_call($this, 'tools/call', ['name' => 'search_tools', 'arguments' => ['query' => 'node list']], '/mcp/search'));

        expect($found['result']['content'][0]['text'])->toContain('node-list');

        $executed = mcp_message(mcp_call($this, 'tools/call', [
            'name' => 'execute_tools',
            'arguments' => ['calls' => [['name' => 'node-list', 'arguments' => (object) []]]],
        ], '/mcp/search'));

        expect($executed['result']['isError'])->toBeFalse()
            ->and($executed['result']['content'][0]['text'])->toContain('gateway');
    });

    it('bounds a whole execute_tools batch by one command deadline', function (): void {
        $now = 1_000.0;
        $deadline = new CommandDeadline(static function () use (&$now): float {
            return $now;
        });
        $this->app->instance(CommandDeadline::class, $deadline);
        // Each tool call is a nested API request. The first one uses up the batch's 550 seconds of forward work.
        Event::listen(RequestHandled::class, static function () use (&$now): void {
            $now += 551.0;
        });

        $executed = mcp_message(mcp_call($this, 'tools/call', [
            'name' => 'execute_tools',
            'arguments' => ['calls' => [
                ['name' => 'node-list', 'arguments' => (object) []],
                ['name' => 'node-list', 'arguments' => (object) []],
            ]],
        ], '/mcp/search'));
        $results = json_decode($executed['result']['content'][0]['text'], true)['results'];
        $late = json_decode($results[1]['content'][0]['text'], true);

        expect($executed['result']['isError'])->toBeTrue()
            ->and($results[0]['isError'] ?? false)->toBeFalse()
            ->and($results[1]['isError'])->toBeTrue()
            ->and($late['status'])->toBe(504)
            ->and($late['error']['code'])->toBe('command.deadline_exceeded')
            ->and(Activity::query()->where('command', 'node:list')->where('error_code', 'command.deadline_exceeded')->exists())->toBeTrue()
            // The deadline ends with the MCP request.
            ->and($deadline->cap(9_999.0))->toBe(9_999.0);
    });
});

it('creates a public Process hostname through the generated MCP tool and API validation', function (): void {
    app()->instance(PublicRouteEdgeProjector::class, new FakePublicRouteEdgeProjector);
    $cluster = Cluster::query()->create(['name' => 'process-edge', 'state' => 'active']);
    $ingress = Node::query()->create(['name' => 'process-ingress', 'cluster_id' => $cluster->id, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    foreach ([RoleName::Router, RoleName::Ingress] as $role) {
        $ingress->roles()->create(['cluster_id' => $cluster->id, 'role' => $role, 'status' => 'active']);
    }
    $services = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $process = $services->processes()->create(['name' => 'sync', 'runtime' => 'docker', 'runtime_config' => ['ports' => ['10.44.0.3:8095:80/tcp']], 'status' => 'active']);
    $response = mcp_call($this, 'tools/call', ['name' => 'route-create', 'arguments' => ['domain' => 'sync.example.com', 'ingress_node_id' => $ingress->id, 'process_id' => $process->id, 'publication' => 'public']])->assertOk();
    expect($response->json('result.isError'))->toBeFalse();
    $document = json_decode($response->json('result.content.0.text'), true);
    expect($document['data']['cluster_id'])->toBe($cluster->id)
        ->and($document['data']['process_id'])->toBe($process->id)
        ->and($document['data']['replacement_step'])->toBe('ingress-firewall');
});
