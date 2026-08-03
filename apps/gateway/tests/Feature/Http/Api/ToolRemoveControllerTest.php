<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Tools\HermesTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../../Support/LegacyOpenClawCleanupHarness.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_tool_script_dispatcher_to_remote_shell();
});

const TOOL_REMOVE_API_CALLER_WG_IP = '10.6.0.97';

function tool_remove_api_server_headers(array $overrides = []): array
{
    return [
        'REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP,
        ...$overrides,
    ];
}

function createToolRemoveApiCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'tool-remove-api-caller',
        'host' => TOOL_REMOVE_API_CALLER_WG_IP,
        'wireguard_address' => TOOL_REMOVE_API_CALLER_WG_IP,
    ], $overrides));
}

function grantToolRemoveApiAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ToolRemoveController', function (): void {
    it('records json implicit destructive consent source for a direct API removal', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/laravel-installer',
            [
                'node' => 'app-remove-api-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'laravel-installer')
            ->assertJsonPath('success.data.tool.node', 'app-remove-api-1');

        $entry = Activity::query()->first();

        expect(NodeTool::find($tool->id))
            ->toBeNull()
            ->and($shell->scripts)
            ->toHaveCount(1)
            ->and($entry)
            ->not
            ->toBeNull()
            ->and($entry->properties->get('destructive_consent'))
            ->toBeTrue()
            ->and($entry->properties->get('destructive_consent_source'))
            ->toBe('json')
            ->and($entry->properties->get('tool'))
            ->toBe('laravel-installer')
            ->and($entry->properties->get('node'))
            ->toBe('app-remove-api-1');
    });

    it('requires agent-push transport before running remove scripts', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);
        bind_unavailable_tool_script_dispatcher();

        $response = test()->call(
            'DELETE',
            '/api/tools/laravel-installer',
            [
                'node' => 'app-remove-api-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'node.agent_unreachable')
            ->assertJsonPath('error.meta.reason', 'agent_push_unavailable')
            ->assertJsonPath('error.meta.node', 'app-remove-api-1');

        expect(NodeTool::find($tool->id))
            ->not
            ->toBeNull()
            ->and($shell->scripts)
            ->toBeEmpty();
    });

    it('removes stale tool records whose catalog definition no longer exists', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'solo',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/solo',
            [
                'node' => 'app-remove-api-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'solo')
            ->assertJsonPath('success.data.tool.node', 'app-remove-api-1')
            ->assertJsonPath('success.data.tool.stale_record', true);

        expect(NodeTool::find($tool->id))
            ->toBeNull()
            ->and($shell->scripts)
            ->toBeEmpty();
    });

    it('removes the related Orbit process and tool-owned proxy route before finishing Hermes removal', function (): void {
        $caller = createToolRemoveApiCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $node = Node::factory()->create([
            'name' => 'agent-hermes-remove',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $process = Process::factory()
            ->forOwner($node)
            ->create([
                'name' => HermesTool::PROCESS_NAME,
                'command' => 'hermes dashboard --host 0.0.0.0 --port 8080 --no-open',
                'runtime' => ProcessRuntime::Systemd,
                'tool' => 'hermes',
            ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://host.docker.internal:8080',
                ],
            ],
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/hermes',
            [
                'node' => $node->name,
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'hermes')
            ->assertJsonPath('success.data.tool.node', $node->name)
            ->assertJsonPath('success.data.tool.process.name', HermesTool::PROCESS_NAME)
            ->assertJsonPath('success.data.tool.process.action', 'removed')
            ->assertJsonPath('success.data.tool.routes_removed', 1);

        expect(NodeTool::find($tool->id))
            ->toBeNull()
            ->and(Process::find($process->id))
            ->toBeNull()
            ->and(ProxyRoute::find($route->id))
            ->toBeNull()
            ->and($shell->proxyRemoveSiteObservations)
            ->toBe([[
                'domain' => 'hermes.agent',
                'route_present' => true,
            ]])
            ->and($shell->scripts)
            ->not
            ->toBeEmpty()
            ->and(collect($shell->scripts)
                ->contains(
                    static fn (string $script): bool => (
                        str_contains($script, 'orbit remove hermes')
                        || str_contains($script, 'rm -rf "${HOME}/.hermes"')
                        || str_contains($script, "rm -rf \"\${HOME}/.hermes\"")
                    ),
                ))
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    static fn (string $script): bool => str_contains($script, "internal:caddy-config 'remove-site'"),
                ))
            ->toBeTrue();
    });

    it('does not remove a same-name process owned by a different tool', function (): void {
        $caller = createToolRemoveApiCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $node = Node::factory()->create([
            'name' => 'agent-process-tool-mismatch',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $foreign = Process::factory()
            ->forOwner($node)
            ->create([
                'name' => HermesTool::PROCESS_NAME,
                'command' => 'sleep infinity',
                'runtime' => ProcessRuntime::Systemd,
                'tool' => 'mailpit',
            ]);
        app()->instance(RemoteShell::class, new ToolRemoveApiRecordingShell);

        $response = test()->call(
            'DELETE',
            '/api/tools/hermes',
            [
                'node' => $node->name,
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'hermes')
            ->assertJsonMissingPath('success.data.tool.process');

        expect(Process::find($foreign->id))->not->toBeNull();
    });

    it('removes stale unsupported tool-owned proxy routes after backend cleanup', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode([
            'name' => 'nmbp',
            'platform' => 'macos_26-5-1',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.nmbp.test',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://host.docker.internal:8080',
                ],
            ],
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/hermes',
            [
                'node' => 'nmbp',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'hermes')
            ->assertJsonPath('success.data.tool.node', 'nmbp')
            ->assertJsonPath('success.data.tool.stale_record', true)
            ->assertJsonPath('success.data.tool.stale_routes_removed', 1);

        expect(ProxyRoute::find($route->id))
            ->toBeNull()
            ->and($shell->proxyRemoveSiteObservations)
            ->toBe([[
                'domain' => 'hermes.nmbp.test',
                'route_present' => true,
            ]])
            ->and(collect($shell->scripts)
                ->contains(
                    static fn (string $script): bool => str_contains($script, "internal:caddy-config 'remove-site'"),
                ))
            ->toBeTrue();
    });

    it('runs removal-only openclaw legacy cleanup for detached runtime without NodeTool intent', function (): void {
        // Live residual shape: process/tool intent gone, proxy may remain, daemon
        // still listening on 18789. tool:remove openclaw must still run host cleanup.
        $caller = createToolRemoveApiCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $node = Node::factory()->create([
            'name' => 'agent-orphan-proxy',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'openclaw',
                'upstream' => 'http://host.docker.internal:18789',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://host.docker.internal:18789',
                ],
            ],
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        expect(app(\App\Services\Tools\ToolCatalog::class)->supports(tool: 'openclaw'))
            ->toBeFalse()
            ->and(NodeTool::query()->where('node_id', $node->id)->where('name', 'openclaw')->exists())
            ->toBeFalse();

        $response = test()->call(
            'DELETE',
            '/api/tools/openclaw',
            [
                'node' => $node->name,
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.tool.name', 'openclaw')
            ->assertJsonPath('success.data.tool.node', $node->name)
            ->assertJsonPath('success.data.tool.stale_record', true)
            ->assertJsonPath('success.data.tool.legacy_runtime_cleanup', true)
            ->assertJsonPath('success.data.tool.routes_removed', 1)
            ->assertJsonPath('success.data.tool.tool_row_removed', false);

        $legacyScript = collect($shell->scripts)
            ->first(static fn (string $script): bool => str_contains($script, 'orbit legacy-remove openclaw'));

        expect(ProxyRoute::find($route->id))
            ->toBeNull()
            ->and($legacyScript)
            ->not
            ->toBeNull()
            ->and($legacyScript)
            ->toContain('sudo ss -lptn')
            ->toContain('OPENCLAW_PORT')
            ->toContain('openclaw-gateway.service')
            ->toContain('legacy openclaw cleanup incomplete')
            ->and($shell->proxyRemoveSiteObservations)
            ->toBe([[
                'domain' => 'openclaw.agent',
                'route_present' => true,
            ]]);
    });

    it('legacy openclaw removal fails closed when the real cleanup script cannot kill a detached listener', function (): void {
        $caller = createToolRemoveApiCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $node = Node::factory()->create([
            'name' => 'agent-openclaw-process',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
        ]);
        $process = Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'openclaw-gateway',
                'command' => 'openclaw gateway run --port 18789 --bind lan',
                'runtime' => ProcessRuntime::Systemd,
                'tool' => 'openclaw',
            ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'openclaw',
                'upstream' => 'http://host.docker.internal:18789',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://host.docker.internal:18789',
                ],
            ],
        ]);
        $harness = openclaw_cleanup_harness_root();
        openclaw_cleanup_write_stubs($harness, unkillableListener: true);
        $shell = new ToolRemoveApiExecutingLegacyScriptShell(harnessRoot: $harness);
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/openclaw',
            [
                'node' => $node->name,
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        // Process intent may already be removed; host verification failure must
        // not delete proxy/tool rows after the script step.
        $response
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'tool.remote_action_failed');

        expect(Process::find($process->id))
            ->toBeNull()
            ->and(NodeTool::find($tool->id))
            ->not->toBeNull()->and(ProxyRoute::find($route->id))
            ->not->toBeNull()->and($shell->lastLegacyStderr)->toContain('port 18789 still listening');
    });

    it('keeps the tool-owned proxy registry row when backend cleanup fails', function (): void {
        $caller = createToolRemoveApiCallerNode();
        NodeRoleAssignment::factory()->create([
            'node_id' => $caller->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        $node = Node::factory()->create([
            'name' => 'agent-proxy-cleanup-fail',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'tld' => 'agent',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
        ]);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => [
                    'type' => 'upstream',
                    'value' => 'http://host.docker.internal:8080',
                ],
            ],
        ]);
        $shell = new ToolRemoveApiRecordingShell(failRemoveSite: true);
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/hermes',
            [
                'node' => $node->name,
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            tool_remove_api_server_headers(),
        );

        // Tool row and process may already be gone; proxy registry must remain
        // when backend cleanup fails so force-remove/retry still has intent.
        $response->assertServerError();
        expect(ProxyRoute::find($route->id))
            ->not
            ->toBeNull()
            ->and(NodeTool::find($tool->id))
            ->toBeNull()
            ->and($shell->proxyRemoveSiteObservations)
            ->toBe([[
                'domain' => 'hermes.agent',
                'route_present' => true,
            ]]);
    });
    it('records explicit destructive consent source for a streamed human removal', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'laravel-installer',
            'expected_state' => 'installed',
        ]);
        app()->instance(RemoteShell::class, new ToolRemoveApiRecordingShell);

        $response = test()->call(
            'DELETE',
            '/api/tools/laravel-installer',
            [
                'node' => 'app-remove-api-1',
                'destructive_consent' => true,
                'destructive_consent_source' => 'interactive_confirm',
            ],
            [],
            [],
            [
                'HTTP_ACCEPT' => 'text/event-stream',
            ] + tool_remove_api_server_headers(),
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $content = $response->streamedContent();

        expect($content)->toContain('event: complete');

        $entry = Activity::query()->first();

        expect($entry)
            ->not
            ->toBeNull()
            ->and($entry->properties->get('destructive_consent'))
            ->toBeTrue()
            ->and($entry->properties->get('destructive_consent_source'))
            ->toBe('interactive_confirm');
    });

    it('rejects missing destructive consent with validation metadata before side effects', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/composer',
            [
                'node' => 'app-remove-api-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');

        expect(NodeTool::find($tool->id))
            ->not
            ->toBeNull()
            ->and($shell->scripts)
            ->toBeEmpty();
    });

    it('requires an explicit target selector even when exactly one app node is visible', function (): void {
        $caller = createToolRemoveApiCallerNode();
        $node = createTestAppHostNode(['name' => 'app-remove-api-1', 'status' => 'active']);
        grantToolRemoveApiAccess($caller, $node);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_state' => 'installed',
        ]);
        $shell = new ToolRemoveApiRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = test()->call(
            'DELETE',
            '/api/tools/composer',
            [
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.fields', ['target']);

        expect(NodeTool::find($tool->id))
            ->not
            ->toBeNull()
            ->and($shell->scripts)
            ->toBeEmpty();
    });

    it('rejects unauthenticated and unauthorized removals with documented codes', function (): void {
        $visibleNode = createTestAppHostNode(['name' => 'visible-node', 'status' => 'active']);
        $hiddenNode = createTestAppHostNode(['name' => 'hidden-node', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $hiddenNode->id, 'name' => 'composer']);
        $caller = createToolRemoveApiCallerNode();
        grantToolRemoveApiAccess($caller, $visibleNode);

        $unauthenticated = test()->call('DELETE', '/api/tools/composer', [
            'node' => 'hidden-node',
            'destructive_consent' => true,
            'destructive_consent_source' => 'json',
        ]);

        $unauthorized = test()->call(
            'DELETE',
            '/api/tools/composer',
            [
                'node' => 'hidden-node',
                'destructive_consent' => true,
                'destructive_consent_source' => 'json',
            ],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_REMOVE_API_CALLER_WG_IP],
        );

        $unauthenticated->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
        $unauthorized->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});

class ToolRemoveApiRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * Observations captured when ProxyRouteFixer issues remove-site (before row delete).
     *
     * @var list<array{domain: string|null, route_present: bool}>
     */
    public array $proxyRemoveSiteObservations = [];

    public function __construct(
        public bool $failRemoveSite = false,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, "internal:caddy-config 'remove-site'")) {
            $payload = tool_remove_decode_shell_input($options['input'] ?? null);
            $domain = is_string($payload['domain'] ?? null) ? $payload['domain'] : null;
            $this->proxyRemoveSiteObservations[] = [
                'domain' => $domain,
                'route_present' => $domain !== null && ProxyRoute::query()->where('domain', $domain)->exists(),
            ];

            if ($this->failRemoveSite) {
                return new RemoteShellResult(
                    exitCode: 1,
                    stdout: '',
                    stderr: 'caddy remove-site failed',
                    durationMs: 1,
                );
            }

            return tool_remove_shell_success(['domain' => $domain]);
        }

        if (str_contains($script, "internal:caddy-config 'read-global'")) {
            return tool_remove_shell_success(['content' => '']);
        }

        if (str_contains($script, "internal:caddy-config 'write-global'")) {
            return tool_remove_shell_success(['content' => '']);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/**
 * @return array<string, mixed>
 */
function tool_remove_decode_shell_input(mixed $input): array
{
    if (! is_string($input) || $input === '') {
        return [];
    }

    /** @var mixed $payload */
    $payload = json_decode($input, associative: true);

    return is_array($payload) ? $payload : [];
}

/**
 * @param  array<string, mixed>  $data
 */
function tool_remove_shell_success(array $data): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode(['success' => ['data' => $data]], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

/**
 * Executes the generated legacy OpenClaw cleanup script under PATH stubs so the
 * API path exercises real verified-success/failure semantics without host sudo.
 */
final class ToolRemoveApiExecutingLegacyScriptShell extends ToolRemoveApiRecordingShell
{
    public string $lastLegacyStderr = '';

    public function __construct(
        public string $harnessRoot,
    ) {
        parent::__construct();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'orbit legacy-remove openclaw')) {
            $this->scripts[] = $script;
            // Production script hard-codes targets; test rewrite is in-process only.
            $harnessScript = openclaw_cleanup_script_for_harness($script, $this->harnessRoot);
            $result = openclaw_cleanup_run_script($this->harnessRoot, $harnessScript);
            $this->lastLegacyStderr = $result['stderr'];

            return new RemoteShellResult(
                exitCode: $result['exit'],
                stdout: $result['stdout'],
                stderr: $this->lastLegacyStderr,
                durationMs: 1,
            );
        }

        return parent::run($node, $script, $options);
    }
}
