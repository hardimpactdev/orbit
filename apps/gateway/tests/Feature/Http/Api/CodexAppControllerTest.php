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
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
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
            'http://10.44.0.24:9477/v1/commands' => Http::sequence()
                ->push(codex_app_agent_response('codex-app-config.read', ['contents' => '{}']))
                ->push(codex_app_agent_response('codex-app-config.write', ['bytes' => 42]))
                ->push(codex_app_agent_response('codex-app-config.apply', ['exit_code' => 0])),
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
        $writtenPayload = json_decode(
            (string) ($requests[1]['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $writtenConfig = json_decode(
            (string) ($writtenPayload['contents'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($writtenConfig['remoteConnections'])
            ->toBe([
                [
                    'sshAlias' => 'app-node',
                    'projects' => [
                        [
                            'remotePath' => '/home/orbit/apps/docs',
                            'label' => 'docs',
                        ],
                    ],
                ],
            ])
            ->and($requests)
            ->toHaveCount(3)
            ->and($requests[0]['argv'][0] ?? null)
            ->toBe('internal:codex-app-config')
            ->and(agentPushRequestOperationIdMatchesToken($requests[0]))
            ->toBeTrue()
            ->and(json_decode((string) $requests[0]['input'], associative: true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['action' => 'read'])
            ->and($requests[1]['argv'][0] ?? null)
            ->toBe('internal:codex-app-config')
            ->and(agentPushRequestOperationIdMatchesToken($requests[1]))
            ->toBeTrue()
            ->and($writtenPayload)
            ->toMatchArray(['action' => 'write'])
            ->and($requests[2]['argv'][0] ?? null)
            ->toBe('internal:codex-app-config')
            ->and(agentPushRequestOperationIdMatchesToken($requests[2]))
            ->toBeTrue()
            ->and(json_decode((string) $requests[2]['input'], associative: true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['action' => 'apply']);
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
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
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
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
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
            'http://10.44.0.24:9477/v1/commands' => Http::sequence()
                ->push(codex_app_agent_response('codex-app-config.read', ['contents' => '{not-json'])),
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
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
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
            'http://10.44.0.24:9477/v1/commands' => Http::sequence()
                ->push(codex_app_agent_response('codex-app-config.read', ['contents' => '{}']))
                ->push(codex_app_agent_response('codex-app-config.write', ['bytes' => 42]))
                ->push(codex_app_agent_response(
                    operationId: 'codex-app-config.apply',
                    data: [],
                    exitCode: 1,
                    stderr: 'callback unavailable',
                )),
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

        expect(codex_app_agent_requests())->toHaveCount(3);
    });
});

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function codex_app_agent_response(string $operationId, array $data, int $exitCode = 0, string $stderr = ''): array
{
    $frames = [];

    if ($data !== []) {
        $frames[] = [
            'type' => 'stdout',
            'message' => json_encode([
                'success' => [
                    'data' => $data,
                ],
            ], JSON_THROW_ON_ERROR),
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
