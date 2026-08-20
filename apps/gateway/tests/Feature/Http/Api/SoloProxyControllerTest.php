<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleName;
use App\Models\GatewayExtension;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Solo\HttpSoloUpstreamClient;
use App\Services\Solo\SoloUpstreamClient;
use App\Services\Solo\SoloUpstreamResponse;
use App\Services\Solo\SoloUpstreamTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeSoloUpstreamClient;

use function Pest\Laravel\call;
use function Pest\Laravel\json;

uses(RefreshDatabase::class);

const SOLO_PROXY_CALLER_WG_IP = '10.6.0.86';

function create_solo_proxy_caller_node(bool $withSolo = false): Node
{
    $node = Node::factory()->create([
        'name' => 'solo-proxy-control',
        'host' => SOLO_PROXY_CALLER_WG_IP,
        'wireguard_address' => SOLO_PROXY_CALLER_WG_IP,
        'platform' => 'ubuntu',
        'status' => 'active',
        'managed' => $withSolo,
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Expected Solo proxy caller node.');
    }

    if ($withSolo) {
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'solo',
            'expected_state' => 'installed',
            'config' => [
                'api_url' => 'http://127.0.0.1:24678',
                'node_identity' => $node->name,
            ],
        ]);
    }

    return $node;
}

/**
 * @param  list<string>  $permissions
 */
function grant_solo_proxy_gateway_access(Node $consumer, Node $gateway, array $permissions): void
{
    NodeAccess::query()->create([
        'consumer_node_id' => $consumer->id,
        'serving_node_id' => $gateway->id,
        'permissions' => $permissions,
        'custom_permissions' => [],
    ]);
}

function enable_solo_gateway_extension(): void
{
    GatewayExtension::query()->create([
        'slug' => 'solo',
        'enabled' => true,
        'enabled_at' => now(),
    ]);
}

function create_solo_target_node(array $toolConfig = [], string $name = 'gateway-1'): Node
{
    $wireguardAddress = $name === 'a-gateway' ? '10.6.0.4' : '10.6.0.2';
    $node = createTestGatewayNode([
        'name' => $name,
        'host' => $wireguardAddress,
        'wireguard_address' => $wireguardAddress,
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'solo',
        'expected_state' => 'installed',
        'config' => array_merge([
            'api_url' => 'http://127.0.0.1:4678',
            'node_identity' => $name,
        ], $toolConfig),
    ]);

    return $node;
}

function create_solo_operator_node(array $toolConfig = [], string $name = 'NMBP'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'host' => '10.6.0.3',
        'wireguard_address' => '10.6.0.3',
        'platform' => 'macos',
        'status' => 'active',
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Expected Solo operator node.');
    }

    NodeRoleAssignment::factory()->for($node)->create([
        'role' => NodeRoleName::Agent->value,
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'solo',
        'expected_state' => 'installed',
        'config' => array_merge([
            'api_url' => 'http://127.0.0.1:24678',
            'node_identity' => $name,
        ], $toolConfig),
    ]);

    return $node;
}

function solo_proxy_request(string $method, string $uri): TestResponse
{
    return call(
        $method,
        $uri,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => SOLO_PROXY_CALLER_WG_IP,
        ],
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function solo_proxy_json_request(string $method, string $uri, array $payload): TestResponse
{
    return json(
        $method,
        $uri,
        $payload,
        [
            'REMOTE_ADDR' => SOLO_PROXY_CALLER_WG_IP,
        ],
    );
}

function bind_solo_proxy_upstream(FakeSoloUpstreamClient $client): FakeSoloUpstreamClient
{
    app()->instance(SoloUpstreamClient::class, $client);

    return $client;
}

/**
 * @mago-expect lint:halstead
 */
describe('Solo proxy API', function (): void {
    it('returns extension_disabled while the gateway Solo extension is disabled', function (): void {
        create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, Node::query()->where('name', 'gateway-1')->firstOrFail(), ['solo:*']);
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension_disabled')
            ->assertJsonPath('error.meta.extension', 'solo')
            ->assertJsonPath('error.meta.scope', 'gateway');
    });

    it('requires the solo wildcard permission on the gateway', function (): void {
        create_solo_target_node();
        create_solo_proxy_caller_node();
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'solo:*')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');
    });

    it('proxies tools to the configured node-local Solo API and records activity', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/discovery' => SoloUpstreamResponse::success(
                data: ['tools' => [['name' => 'codex']]],
                meta: ['source' => 'solo'],
            ),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertOk()
            ->assertJsonPath('success.data.tools.0.name', 'codex')
            ->assertJsonPath('success.meta.source', 'solo');

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['path'])
            ->toBe('/discovery')
            ->and($upstream->calls[0]['target']->node->is($gateway))
            ->toBeTrue()
            ->and($upstream->calls[0]['target']->url)
            ->toBe('http://127.0.0.1:4678')
            ->and($upstream->calls[0]['target']->identity)
            ->toBe('gateway-1');

        $entry = solo_proxy_activity_entry();
        $properties = solo_proxy_activity_properties($entry);

        expect(solo_proxy_activity_event($entry))
            ->toBe('solo.tools.listed')
            ->and($properties['type'] ?? null)
            ->toBe('read')
            ->and($properties['operation'] ?? null)
            ->toBe('tools')
            ->and($properties['target_node'] ?? null)
            ->toBe('gateway-1');
    });

    it('proxies read-only Solo operations to the requested target node', function (): void {
        create_solo_target_node();
        $target = create_solo_operator_node(name: 'NMBP');
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $target, ['solo:*']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/projects' => SoloUpstreamResponse::success(data: [
                'projects' => [
                    ['name' => 'orbit'],
                ],
            ]),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/projects?node=NMBP')
            ->assertOk()
            ->assertJsonPath('success.data.projects.0.name', 'orbit');

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['target']->node->is($target))
            ->toBeTrue()
            ->and($upstream->calls[0]['target']->url)
            ->toBe('http://127.0.0.1:24678')
            ->and($upstream->calls[0]['target']->identity)
            ->toBe('NMBP');

        $properties = solo_proxy_activity_properties(solo_proxy_activity_entry());

        expect($properties['target_node'] ?? null)->toBe('NMBP');
    });

    it('targets the authenticated caller when Solo scope resolves to self', function (): void {
        create_solo_target_node();
        $caller = create_solo_proxy_caller_node(withSolo: true);
        grant_solo_proxy_gateway_access($caller, $caller, ['solo:*']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/projects' => SoloUpstreamResponse::success(data: ['projects' => []]),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/projects?self=1')
            ->assertOk();

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['target']->node->is($caller))
            ->toBeTrue();
    });

    it('rejects a non-gateway Solo target that is not Agent eligible', function (): void {
        create_solo_target_node();
        $target = Node::factory()->create([
            'name' => 'unmanaged-operator',
            'host' => '10.6.0.44',
            'wireguard_address' => '10.6.0.44',
            'platform' => 'macos',
            'status' => 'active',
            'managed' => false,
        ]);
        NodeTool::factory()->create([
            'node_id' => $target->id,
            'name' => 'solo',
            'expected_state' => 'installed',
            'config' => [
                'api_url' => 'http://127.0.0.1:24678',
                'node_identity' => $target->name,
            ],
        ]);
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $target, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/projects?node=unmanaged-operator')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'solo_target_agent_required');
    });

    it('executes non-gateway Solo upstream requests on the target node loopback', function (): void {
        $target = create_solo_operator_node(name: 'NMBP');
        Http::preventStrayRequests();
        Http::fake([
            'http://10.6.0.3:9477/v1/commands' => Http::response([
                'transport' => 'agent-push',
                'operation_id' => 'solo-upstream-request',
                'binary' => 'orbit',
                'status' => 'succeeded',
                'exit_code' => 0,
                'frames' => [
                    [
                        'type' => 'stdout',
                        'message' => json_encode([
                            'success' => [
                                'data' => [
                                    'status' => 200,
                                    'body_base64' => base64_encode(json_encode([
                                        'ok' => true,
                                        'data' => [
                                            'projects' => [
                                                ['name' => 'orbit'],
                                            ],
                                        ],
                                    ], JSON_THROW_ON_ERROR)),
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ],
                    [
                        'type' => 'exit',
                        'message' => '0',
                    ],
                ],
            ]),
        ]);

        $response = app(HttpSoloUpstreamClient::class)->get(
            new SoloUpstreamTarget($target, 'http://127.0.0.1:24678', 'NMBP', 'secret-token'),
            '/projects',
        );

        expect($response->ok)
            ->toBeTrue()
            ->and($response->data['projects'][0]['name'] ?? null)
            ->toBe('orbit');

        Http::assertSent(function (Request $request): bool {
            $input = json_decode((string) $request['input'], associative: true);

            return (
                $request->url() === 'http://10.6.0.3:9477/v1/commands'
                && $request['binary'] === 'orbit'
                && agentPushRequestOperationIdMatchesToken($request)
                && $request['argv'][0] === 'internal:solo-upstream-request'
                && str_starts_with((string) $request['argv'][1], '--operation-token=')
                && $request['argv'][2] === '--json'
                && is_array($input)
                && $input['method'] === 'GET'
                && $input['url'] === 'http://127.0.0.1:24678/projects'
                && data_get($input, 'headers.Authorization') === 'Bearer secret-token'
                && data_get($input, 'headers.X-Orbit-Node') === 'NMBP'
            );
        });
    });

    it('proxies projects and maps upstream unavailable responses', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/projects' => SoloUpstreamResponse::failure(
                code: 'solo_upstream_unavailable',
                message: 'Solo API is unavailable on gateway-1.',
                meta: ['node' => 'gateway-1'],
                status: 503,
            ),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/projects?node=gateway-1')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'solo_upstream_unavailable')
            ->assertJsonPath('error.meta.node', 'gateway-1');

        expect(solo_proxy_activity_event(solo_proxy_activity_entry()))->toBe('solo.project.list.read');
    });

    it('maps invalid upstream payloads to validation_failed', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/discovery' => SoloUpstreamResponse::success(data: ['unexpected' => true]),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'tools');
    });

    it('fails validation when the target node has no local Solo API configuration', function (): void {
        $gateway = create_solo_target_node(['api_url' => null]);
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'solo_api_not_configured');
    });

    it('rejects non-loopback Solo API URLs before proxying', function (): void {
        $gateway = create_solo_target_node(['api_url' => 'http://10.0.0.5:4678']);
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools?node=gateway-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'solo_api_url_not_loopback');

        expect($upstream->calls)->toHaveCount(0);
    });

    it('proxies configured read-only Solo operations', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/projects/orbit/status' => SoloUpstreamResponse::success(data: [
                'status' => [
                    'project' => 'orbit',
                    'state' => 'active',
                ],
            ]),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/project/status?project=orbit&node=gateway-1')
            ->assertOk()
            ->assertJsonPath('success.data.status.project', 'orbit')
            ->assertJsonPath('success.data.status.state', 'active');

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['path'])
            ->toBe('/projects/orbit/status');

        expect(solo_proxy_activity_event(solo_proxy_activity_entry()))->toBe('solo.project.status.read');
    });

    it('validates required read-only Solo operation inputs', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/project/show?node=gateway-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'project');
    });

    it('returns validation_failed for unknown read-only Solo operations', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_request(method: 'GET', uri: '/api/solo/unknown/read?node=gateway-1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.reason', 'solo_operation_unknown');
    });

    it('requires operation-specific permissions for Solo mutations', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:project:create']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'gateway-1',
            'scratchpad' => 'plan',
            'content' => 'updated',
            'expected_revision' => 7,
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'solo:scratchpad:write')
            ->assertJsonPath('error.meta.serving_node', 'gateway-1');
    });

    it('authorizes Solo mutations against the requested target node', function (): void {
        create_solo_target_node();
        $target = create_solo_operator_node(name: 'NMBP');
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $target, ['solo:project:create']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'NMBP',
            'scratchpad' => 'plan',
            'content' => 'updated',
            'expected_revision' => 7,
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'solo:scratchpad:write')
            ->assertJsonPath('error.meta.serving_node', 'NMBP');
    });

    it('proxies Solo mutations to the configured node-local API and records activity', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:scratchpad:write']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            'PUT /scratchpads/plan' => SoloUpstreamResponse::success(data: [
                'scratchpad' => [
                    'name' => 'plan',
                    'revision' => 8,
                ],
            ]),
        ]));

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'gateway-1',
            'scratchpad' => 'plan',
            'content' => 'updated',
            'expected_revision' => 7,
        ])
            ->assertOk()
            ->assertJsonPath('success.data.scratchpad.revision', 8);

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['method'])
            ->toBe('PUT')
            ->and($upstream->calls[0]['path'])
            ->toBe('/scratchpads/plan')
            ->and($upstream->calls[0]['payload'])
            ->toMatchArray([
                'content' => 'updated',
                'expected_revision' => 7,
            ]);

        $entry = solo_proxy_activity_entry();
        $properties = solo_proxy_activity_properties($entry);

        expect(solo_proxy_activity_event($entry))
            ->toBe('solo.scratchpad.write')
            ->and($properties['type'] ?? null)
            ->toBe('write')
            ->and($properties['operation'] ?? null)
            ->toBe('scratchpad/write')
            ->and($properties['target_node'] ?? null)
            ->toBe('gateway-1');
    });

    it('authorizes, proxies, and records activity against an explicit Solo gateway node', function (): void {
        $gateway = create_solo_target_node(name: 'z-gateway');
        create_solo_target_node(name: 'a-gateway');
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:scratchpad:write']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            'PUT /scratchpads/plan' => SoloUpstreamResponse::success(data: [
                'scratchpad' => [
                    'name' => 'plan',
                    'revision' => 8,
                ],
            ]),
        ]));

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'z-gateway',
            'scratchpad' => 'plan',
            'content' => 'updated',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.scratchpad.revision', 8);

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['target']->node->is($gateway))
            ->toBeTrue();

        $properties = solo_proxy_activity_properties(solo_proxy_activity_entry());

        expect($properties['target_node'] ?? null)->toBe('z-gateway');
    });

    it('validates required Solo mutation inputs before upstream calls', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:scratchpad:write']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'gateway-1',
            'scratchpad' => 'plan',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'content');

        expect($upstream->calls)->toHaveCount(0);
    });

    it('maps Solo mutation upstream errors', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:scratchpad:write']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            'PUT /scratchpads/plan' => SoloUpstreamResponse::failure(
                code: 'solo_revision_conflict',
                message: 'Scratchpad revision changed.',
                meta: ['expected_revision' => 7, 'actual_revision' => 8],
                status: 409,
            ),
        ]));

        solo_proxy_json_request(method: 'PUT', uri: '/api/solo/scratchpad/write', payload: [
            'node' => 'gateway-1',
            'scratchpad' => 'plan',
            'content' => 'updated',
            'expected_revision' => 7,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'solo_revision_conflict')
            ->assertJsonPath('error.meta.actual_revision', 8);
    });

    it('marks documented Solo delete and clear operations as destructive', function (string $operation): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient);

        solo_proxy_json_request(method: 'DELETE', uri: "/api/solo/{$operation}", payload: [
            'node' => 'gateway-1',
        ])->assertUnprocessable();

        $properties = solo_proxy_activity_properties(solo_proxy_activity_entry());

        expect($properties['type'] ?? null)->toBe('destructive');
    })->with([
        'project delete' => 'project/delete',
        'process close' => 'process/close',
        'scratchpad clear' => 'scratchpad/clear',
        'scratchpad delete' => 'scratchpad/delete',
        'todo delete' => 'todo/delete',
        'todo comment delete' => 'todo/comment/delete',
    ]);

    it('proxies project-scoped Todo create and delete operations', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:todo:write', 'solo:todo:delete']);
        enable_solo_gateway_extension();
        $upstream = bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            'POST /projects/4/todos' => SoloUpstreamResponse::success(data: [
                'todo' => ['id' => 42, 'title' => 'Check live Solo'],
            ]),
            'DELETE /projects/4/todos/42' => SoloUpstreamResponse::success(data: [
                'todo' => ['id' => 42, 'deleted' => true],
            ]),
        ]));

        solo_proxy_json_request(method: 'POST', uri: '/api/solo/todo/create', payload: [
            'node' => 'gateway-1',
            'project' => '4',
            'title' => 'Check live Solo',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.todo.id', 42);

        solo_proxy_json_request(method: 'DELETE', uri: '/api/solo/todo/delete', payload: [
            'node' => 'gateway-1',
            'project' => '4',
            'todo' => '42',
        ])
            ->assertOk()
            ->assertJsonPath('success.data.todo.deleted', true);

        expect($upstream->calls)
            ->toHaveCount(2)
            ->and($upstream->calls[0]['path'])
            ->toBe('/projects/4/todos')
            ->and($upstream->calls[0]['payload'])
            ->toMatchArray(['title' => 'Check live Solo'])
            ->and($upstream->calls[1]['path'])
            ->toBe('/projects/4/todos/42');
    });
});

function solo_proxy_activity_entry(): object
{
    $entry = DB::table('activity_log')->first();

    if (! is_object($entry)) {
        throw new RuntimeException('Expected Solo proxy activity entry.');
    }

    return $entry;
}

function solo_proxy_activity_event(object $entry): string
{
    return property_exists($entry, 'event') && is_string($entry->event) ? $entry->event : '';
}

/**
 * @return array<string, mixed>
 */
function solo_proxy_activity_properties(object $entry): array
{
    if (! property_exists($entry, 'properties') || ! is_string($entry->properties)) {
        return [];
    }

    /** @var array<string, mixed> */
    return json_decode($entry->properties, associative: true, flags: JSON_THROW_ON_ERROR);
}
