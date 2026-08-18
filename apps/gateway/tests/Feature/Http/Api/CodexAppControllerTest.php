<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Models\App;
use App\Models\GatewayExtension;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CODEX_APP_CALLER_WG_IP = '10.44.0.90';

/**
 * @param  list<string>  $permissions
 */
function grantCodexAppAccess(Node $caller, Node $servingNode, array $permissions = ['codex:app']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('CodexAppController', function (): void {
    beforeEach(function (): void {
        GatewayExtension::query()->updateOrCreate(
            ['slug' => 'codex'],
            ['enabled' => true, 'enabled_at' => now()],
        );
    });

    /**
     * @return array<string, string>
     */
    function codex_app_agent_push_server(): array
    {
        return [
            'REMOTE_ADDR' => CODEX_APP_CALLER_WG_IP,
        ];
    }

    it('does not expose the obsolete Codex App list path', function (): void {
        $this->getJson('/api/codex/apps')->assertNotFound();
    });

    it('adds an app project to Codex App config on a macOS non-gateway target through agent-push', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'caller',
                'host' => CODEX_APP_CALLER_WG_IP,
                'wireguard_address' => CODEX_APP_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()
            ->operator()
            ->agent()
            ->managed()
            ->create([
                'name' => 'mini',
                'platform' => 'macos_15-5',
                'wireguard_address' => '10.44.0.24',
                'user' => 'nicky',
            ]);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);
        grantCodexAppAccess($caller, $appNode);
        grantCodexAppAccess($caller, $target);
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.24:9477/v1/commands' => codex_app_agent_response(
                'codex-app-config.mutate',
                ['changed' => true],
            ),
        ]);

        $response = $this->call(
            'POST',
            "/api/codex/apps/{$app->name}",
            [
                'node' => 'mini',
            ],
            [],
            [],
            codex_app_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.codex_project.project', 'docs')
            ->assertJsonPath('success.data.codex_project.node', 'mini')
            ->assertJsonPath('success.data.codex_project.remote_path', '/home/orbit/apps/docs')
            ->assertJsonPath('success.data.codex_project.ssh_alias', 'app-node')
            ->assertJsonPath('success.data.codex_project.added', true);

        $requests = codex_app_agent_requests();
        $mutationPayload = json_decode(
            (string) ($requests[0]['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($requests)
            ->toHaveCount(1)
            ->and($requests[0]['argv'][0] ?? null)
            ->toBe('internal:codex-app-config')
            ->and(agentPushRequestOperationIdMatchesToken($requests[0]))
            ->toBeTrue()
            ->and($mutationPayload)
            ->toBe([
                'action' => 'mutate',
                'mutation' => 'add',
                'project' => [
                    'label' => 'docs',
                    'ssh_alias' => 'app-node',
                    'remote_path' => '/home/orbit/apps/docs',
                ],
            ]);
    });

    it('removes an app project through one target-side mutation', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'caller',
                'host' => CODEX_APP_CALLER_WG_IP,
                'wireguard_address' => CODEX_APP_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()
            ->operator()
            ->agent()
            ->managed()
            ->create([
                'name' => 'mini',
                'platform' => 'macos_15-5',
                'wireguard_address' => '10.44.0.24',
                'user' => 'nicky',
            ]);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);
        grantCodexAppAccess($caller, $appNode);
        grantCodexAppAccess($caller, $target);
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.24:9477/v1/commands' => codex_app_agent_response(
                'codex-app-config.mutate',
                [
                    'changed' => true,
                    'removed' => true,
                ],
            ),
        ]);

        $response = $this->call(
            'DELETE',
            "/api/codex/apps/{$app->name}",
            [
                'node' => 'mini',
            ],
            [],
            [],
            codex_app_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.codex_project.removed', true);

        $requests = codex_app_agent_requests();

        expect($requests)
            ->toHaveCount(1)
            ->and(agentPushRequestOperationIdMatchesToken($requests[0]))
            ->toBeTrue()
            ->and(json_decode((string) $requests[0]['input'], associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'action' => 'mutate',
                'mutation' => 'remove',
                'project' => [
                    'label' => 'docs',
                    'ssh_alias' => 'app-node',
                    'remote_path' => '/home/orbit/apps/docs',
                ],
            ]);
    });

    it('rejects non-macOS Codex App targets before agent-push work', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'caller',
                'host' => CODEX_APP_CALLER_WG_IP,
                'wireguard_address' => CODEX_APP_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-node']);
        $target = Node::factory()
            ->operator()
            ->create([
                'name' => 'linux-operator',
                'platform' => 'ubuntu_24-04',
            ]);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app, 'app')->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
            ),
        ]);
        grantCodexAppAccess($caller, $appNode);
        grantCodexAppAccess($caller, $target);
        Http::preventStrayRequests();
        Http::fake();

        $response = $this->call(
            'POST',
            "/api/codex/apps/{$app->name}",
            [
                'node' => 'linux-operator',
            ],
            [],
            [],
            codex_app_agent_push_server(),
        );

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.unsupported_on_node');

        expect(codex_app_agent_requests())->toBe([]);
    });

    it('rejects malformed Codex App config before writing', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'caller',
                'host' => CODEX_APP_CALLER_WG_IP,
                'wireguard_address' => CODEX_APP_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()
            ->operator()
            ->agent()
            ->managed()
            ->create([
                'name' => 'mini',
                'platform' => 'macos_15-5',
                'wireguard_address' => '10.44.0.24',
                'user' => 'nicky',
            ]);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);
        grantCodexAppAccess($caller, $appNode);
        grantCodexAppAccess($caller, $target);
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.24:9477/v1/commands' => codex_app_agent_response(
                'codex-app-config.mutate',
                [],
                [
                    'exit_code' => 1,
                    'error' => [
                        'code' => 'codex_app.config_read_failed',
                        'message' => 'Codex App config is not valid JSON.',
                        'meta' => [
                            'path' => '/Users/nicky/.codex/codex-app/config.json',
                            'json_error' => 'Syntax error',
                        ],
                    ],
                ],
            ),
        ]);

        $response = $this->call(
            'POST',
            "/api/codex/apps/{$app->name}",
            [
                'node' => 'mini',
            ],
            [],
            [],
            codex_app_agent_push_server(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'app_codex.config_read_failed')
            ->assertJsonPath('error.meta.path', '~/.codex/codex-app/config.json');

        expect(codex_app_agent_requests())->toHaveCount(1);
    });

    it('returns a warning when the Codex App apply callback fails after writing config', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'caller',
                'host' => CODEX_APP_CALLER_WG_IP,
                'wireguard_address' => CODEX_APP_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-node', 'wireguard_address' => '10.44.0.20']);
        $target = Node::factory()
            ->operator()
            ->agent()
            ->managed()
            ->create([
                'name' => 'mini',
                'platform' => 'macos_15-5',
                'wireguard_address' => '10.44.0.24',
                'user' => 'nicky',
            ]);
        $app = App::factory()->create(['name' => 'docs']);
        Instance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $appNode->id,
                node: $appNode->name,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);
        grantCodexAppAccess($caller, $appNode);
        grantCodexAppAccess($caller, $target);
        Http::preventStrayRequests();
        Http::fake([
            'http://10.44.0.24:9477/v1/commands' => codex_app_agent_response(
                'codex-app-config.mutate',
                ['changed' => true],
                [
                    'meta' => [
                        'warnings' => [[
                            'code' => 'codex_app.apply_failed',
                            'message' => 'Codex App config apply callback failed.',
                            'meta' => [
                                'exit_code' => 1,
                                'stderr' => 'callback unavailable',
                            ],
                        ]],
                    ],
                ],
            ),
        ]);

        $response = $this->call(
            'POST',
            "/api/codex/apps/{$app->name}",
            [
                'node' => 'mini',
            ],
            [],
            [],
            codex_app_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.codex_project.added', true)
            ->assertJsonPath('success.meta.warnings.0.code', 'app_codex.apply_failed')
            ->assertJsonPath('success.meta.warnings.0.meta.node', 'mini');

        expect(codex_app_agent_requests())->toHaveCount(1);
    });
});

/**
 * @param  array<string, mixed>  $data
 * @param  array{exit_code?: int, stderr?: string, meta?: array<string, mixed>, error?: array<string, mixed>}  $options
 * @return array<string, mixed>
 */
function codex_app_agent_response(
    string $operationId,
    array $data,
    array $options = [],
): array {
    $frames = [];
    $exitCode = $options['exit_code'] ?? 0;
    $stderr = $options['stderr'] ?? '';
    $meta = $options['meta'] ?? [];
    $error = $options['error'] ?? null;

    if ($data !== [] || $meta !== [] || $error !== null) {
        $envelope = $error !== null
            ? ['error' => $error]
            : [
                'success' => [
                    'data' => $data,
                    'meta' => $meta === [] ? (object) [] : $meta,
                ],
            ];

        $frames[] = [
            'type' => 'stdout',
            'message' => json_encode($envelope, JSON_THROW_ON_ERROR),
        ];
    }

    if ($stderr !== '') {
        $frames[] = [
            'type' => 'stderr',
            'message' => $stderr,
        ];
    }

    $frames[] = [
        'type' => 'exit',
        'message' => (string) $exitCode,
    ];

    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => $frames,
    ];
}

/**
 * @return list<Request>
 */
function codex_app_agent_requests(): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === 'http://10.44.0.24:9477/v1/commands',
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}
