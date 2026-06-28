<?php

declare(strict_types=1);

use App\Models\GatewayExtension;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeTool;
use App\Services\Solo\SoloUpstreamClient;
use App\Services\Solo\SoloUpstreamResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeSoloUpstreamClient;

use function Pest\Laravel\call;
use function Pest\Laravel\json;

uses(RefreshDatabase::class);

const SOLO_PROXY_CALLER_WG_IP = '10.6.0.86';

function create_solo_proxy_caller_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'solo-proxy-control',
        'host' => SOLO_PROXY_CALLER_WG_IP,
        'wireguard_address' => SOLO_PROXY_CALLER_WG_IP,
        'platform' => 'ubuntu',
        'status' => 'active',
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Expected Solo proxy caller node.');
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
    $node = createTestGatewayNode([
        'name' => $name,
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
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
            '/tools' => SoloUpstreamResponse::success(
                data: ['tools' => [['name' => 'codex']]],
                meta: ['source' => 'solo'],
            ),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
            ->assertOk()
            ->assertJsonPath('success.data.tools.0.name', 'codex')
            ->assertJsonPath('success.meta.source', 'solo');

        expect($upstream->calls)
            ->toHaveCount(1)
            ->and($upstream->calls[0]['path'])
            ->toBe('/tools')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/projects')
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'solo_upstream_unavailable')
            ->assertJsonPath('error.meta.node', 'gateway-1');

        expect(solo_proxy_activity_event(solo_proxy_activity_entry()))->toBe('solo.projects.listed');
    });

    it('maps invalid upstream payloads to validation_failed', function (): void {
        $gateway = create_solo_target_node();
        $caller = create_solo_proxy_caller_node();
        grant_solo_proxy_gateway_access($caller, $gateway, ['solo:*']);
        enable_solo_gateway_extension();
        bind_solo_proxy_upstream(new FakeSoloUpstreamClient([
            '/tools' => SoloUpstreamResponse::success(data: ['unexpected' => true]),
        ]));

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/tools')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/project/status?project=orbit')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/project/show')
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

        solo_proxy_request(method: 'GET', uri: '/api/solo/unknown/read')
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

    it('authorizes, proxies, and records activity against the resolved Solo gateway node', function (): void {
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
            'scratchpad' => 'plan',
            'content' => 'updated',
            'expected_revision' => 7,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'solo_revision_conflict')
            ->assertJsonPath('error.meta.actual_revision', 8);
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
