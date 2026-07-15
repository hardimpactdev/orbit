<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const PROCESS_LOG_CALLER_WG_IP = '10.6.0.94';

function createProcessLogCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_LOG_CALLER_WG_IP,
        'managed' => true,
        'wireguard_address' => PROCESS_LOG_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function create_process_log_agent_node(array $overrides = [], string $role = 'app-dev'): Node
{
    return createTestAppHostNode(array_merge([
        'managed' => true,
        'wireguard_address' => '10.6.0.44',
    ], $overrides), $role);
}

function grantProcessLogAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:logs'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function fake_process_log_agent(string $output, int $exitCode = 0, string $stderr = ''): void
{
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'process.logs.read',
            'binary' => 'orbit',
            'status' => $exitCode === 0 ? 'succeeded' : 'failed',
            'exit_code' => $exitCode,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'output' => $output,
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'stderr',
                    'message' => $stderr,
                ],
            ],
        ]),
    ]);
}

/**
 * @return array<string, string>
 */
function process_log_agent_push_server(): array
{
    return [
        'REMOTE_ADDR' => PROCESS_LOG_CALLER_WG_IP,
    ];
}

/**
 * @mago-expect lint:halstead
 */
describe('ProcessLogController', function (): void {
    it('returns bounded logs for authorized control callers', function (): void {
        $caller = createProcessLogCallerNode();
        $appNode = create_process_log_agent_node();
        grantProcessLogAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        fake_process_log_agent("Vite ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/vite/log',
            [
                'app' => 'docs',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.runtime_unit', 'orbit_docs_development_main_vite')
            ->assertJsonPath('success.data.logs.lines.0.message', 'Vite ready')
            ->assertJsonPath('success.meta.line_count', 1);

        Http::assertSent(
            fn (Request $request): bool => (
                $request['argv'][0] === 'internal:process-logs'
                && json_decode((string) $request['input'], associative: true) === [
                    'backend' => 'systemd',
                    'runtime_unit' => 'orbit_docs_development_main_vite',
                    'lines' => 5,
                    'follow' => false,
                ]
            ),
        );
    });

    it('keeps GET log reads bounded when a stale follow query is supplied', function (): void {
        $caller = createProcessLogCallerNode();
        $appNode = create_process_log_agent_node();
        grantProcessLogAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        fake_process_log_agent("Vite ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/vite/log',
            [
                'app' => 'docs',
                'lines' => 5,
                'follow' => true,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.lines.0.message', 'Vite ready');

        Http::assertSent(
            fn (Request $request): bool => (
                $request->url() === 'http://10.6.0.44:9477/v1/commands'
                && json_decode((string) $request['input'], associative: true)['follow'] === false
            ),
        );
    });

    it('returns bounded logs for a workspace owned process', function (): void {
        $appNode = createProcessLogCallerNode(role: 'app-dev');
        grantProcessLogAccess($appNode, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()
            ->forOwner($workspace)
            ->create([
                'name' => 'frankenphp-docs-feature-docs',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => ['container_name' => 'orbit-ws-docs-feature-docs'],
            ]);
        fake_process_log_agent("FrankenPHP ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/frankenphp-docs-feature-docs/log',
            [
                'workspace' => 'feature-docs',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.node', $appNode->name)
            ->assertJsonPath('success.data.logs.app', 'docs')
            ->assertJsonPath('success.data.logs.workspace', 'feature-docs')
            ->assertJsonPath('success.data.logs.runtime_unit', 'orbit-ws-docs-feature-docs')
            ->assertJsonPath('success.data.logs.lines.0.message', 'FrankenPHP ready');
    });

    it('returns bounded logs for a node owned process', function (): void {
        createProcessLogCallerNode(role: 'gateway');
        $node = create_process_log_agent_node(['name' => 'app-1']);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'opencode-server',
                'runtime' => ProcessRuntime::Systemd,
                'tool' => 'opencode-cli',
            ]);
        fake_process_log_agent("OpenCode ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/opencode-server/log',
            [
                'node' => 'app-1',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.node', 'app-1')
            ->assertJsonPath('success.data.logs.app', null)
            ->assertJsonPath('success.data.logs.workspace', null)
            ->assertJsonPath('success.data.logs.runtime_unit', 'opencode-server')
            ->assertJsonPath('success.data.logs.lines.0.message', 'OpenCode ready');
    });

    it('returns managed service metadata when reading node owned service process logs', function (): void {
        createProcessLogCallerNode(role: 'gateway');
        $node = create_process_log_agent_node([
            'name' => 'database-1',
            'wireguard_address' => '10.6.0.44',
        ]);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'mysql8',
                'command' => 'mysqld',
                'runtime' => ProcessRuntime::DockerSwarm,
                'runtime_config' => [
                    'service' => 'mysql',
                    'version_family' => '8',
                    'version' => '8.4',
                    'service_name' => 'orbit-mysql8',
                    'endpoint' => [
                        'name' => 'mysql8',
                        'kind' => 'tcp',
                        'host' => '10.6.0.44',
                        'port' => 3308,
                    ],
                    'credentials' => [
                        'database' => 'orbit',
                        'password' => 'orbit',
                        'username' => 'orbit',
                    ],
                ],
            ]);
        $remoteShell = new ProcessLogApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);
        fake_process_log_agent("MySQL ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/mysql8/log',
            [
                'node' => 'database-1',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.node', 'database-1')
            ->assertJsonPath('success.data.logs.app', null)
            ->assertJsonPath('success.data.logs.workspace', null)
            ->assertJsonPath('success.data.logs.runtime_unit', 'orbit-mysql8')
            ->assertJsonPath('success.data.logs.service.service', 'mysql')
            ->assertJsonPath('success.data.logs.service.version_family', '8')
            ->assertJsonPath('success.data.logs.service.version', '8.4')
            ->assertJsonPath('success.data.logs.service.endpoint.host', '10.6.0.44')
            ->assertJsonPath('success.data.logs.service.endpoint.port', 3308)
            ->assertJsonPath('success.data.logs.service.credential_fields', ['database', 'password', 'username'])
            ->assertJsonMissingPath('success.data.logs.service.credentials')
            ->assertJsonPath('success.data.logs.lines.0.message', 'MySQL ready');

        expect($remoteShell->scripts)->toBe([]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request['argv'][0] === 'internal:process-logs'
                && json_decode((string) $request['input'], associative: true) === [
                    'backend' => 'docker-swarm',
                    'runtime_unit' => 'orbit-mysql8',
                    'lines' => 5,
                    'follow' => false,
                ]
            ),
        );
    });

    it('rejects unsupported persisted runtimes before log side effects', function (): void {
        createProcessLogCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $process = Process::factory()->forOwner($app)->create(['name' => 'vite']);
        DB::table('processes')->where('id', $process->id)->update(['runtime' => 'docker-swarm']);
        $remoteShell = new ProcessLogApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'GET',
            '/api/processes/vite/log',
            [
                'app' => 'docs',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'process.unsupported_runtime')
            ->assertJsonPath('error.meta.process', 'vite')
            ->assertJsonPath('error.meta.runtime', 'docker-swarm')
            ->assertJsonPath('error.meta.reason', 'docker_swarm_requires_node_owned_process');

        expect($remoteShell->scripts)->toBeEmpty();
    });

    it('requires authorization before log reads', function (): void {
        createProcessLogCallerNode();
        $appNode = createTestAppHostNode();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        $remoteShell = new ProcessLogApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call(
            'GET',
            '/api/processes/vite/log',
            [
                'app' => 'docs',
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:logs');

        expect($remoteShell->scripts)->toBe([]);
    });

    it('returns log read failures as gateway errors', function (): void {
        createProcessLogCallerNode(role: 'gateway');
        $appNode = create_process_log_agent_node();
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite']);
        fake_process_log_agent('', exitCode: 1, stderr: 'missing');

        $response = $this->call(
            'GET',
            '/api/processes/vite/log',
            [
                'app' => 'docs',
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'process.log_read_failed');
    });

    it('passes explicit launchd stdout and stderr paths to the local log reader', function (): void {
        $caller = createProcessLogCallerNode();
        $appNode = create_process_log_agent_node([
            'platform' => 'macos_14',
            'user' => 'nckrtl',
        ]);
        grantProcessLogAccess($caller, $appNode);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/Users/nckrtl/apps/docs',
        ]);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'feedback',
                'runtime' => ProcessRuntime::Launchd,
            ]);
        fake_process_log_agent("Feedback ready\n");

        $response = $this->call(
            'GET',
            '/api/processes/feedback/log',
            [
                'app' => 'docs',
                'lines' => 5,
            ],
            [],
            [],
            process_log_agent_push_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.logs.runtime_unit', 'orbit_docs_development_main_feedback')
            ->assertJsonPath('success.data.logs.lines.0.message', 'Feedback ready');

        Http::assertSent(
            fn (Request $request): bool => (
                $request['argv'][0] === 'internal:process-logs'
                && json_decode((string) $request['input'], associative: true) === [
                    'backend' => 'launchd',
                    'runtime_unit' => 'orbit_docs_development_main_feedback',
                    'lines' => 5,
                    'follow' => false,
                    'stdout_path' => '/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_development_main_feedback.out.log',
                    'stderr_path' => '/Users/nckrtl/Library/Logs/Orbit/processes/orbit_docs_development_main_feedback.err.log',
                ]
            ),
        );
    });
});

final class ProcessLogApiRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
