<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Exceptions\RemoteShellFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Models\Workspace;
use App\Services\Ca\OrbitCaService;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Platform\PlatformDetector;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

const DOCTOR_RUN_CALLER_WG_IP = '10.6.0.94';

/**
 * @return array<string, mixed>
 */
function doctor_run_runtime_inventory_agent_response(): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'process-runtime-containers.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'stdout' => "orbit-container-scan:absent\n",
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => '0',
            ],
        ],
    ];
}

function createDoctorRunCallerNode(array $overrides = [], string $role = 'gateway'): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => DOCTOR_RUN_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_CALLER_WG_IP,
        'platform' => 'ubuntu',
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

/**
 * @return array<string, string>
 */
function doctor_run_explicit_fallback_server(): array
{
    return [
        'REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP,
    ];
}

describe('DoctorRunController', function (): void {
    it('runs verify mode and returns a doctor report', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['node'],
                'mode' => 'verify',
                'self' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('defaults omitted API scope to the caller node instead of fleet', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['node'],
                'mode' => 'verify',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.node', 'caller')
            ->assertJsonPath('success.data.doctor.scope.self', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['node'])
            ->assertJsonMissingPath('success.data.doctor.scope.targets')
            ->assertJsonMissingPath('success.data.doctor.nodes');
    });

    it('keeps roleless caller nodes limited to the node family by default', function (): void {
        $caller = Node::factory()
            ->operator()
            ->create([
                'name' => 'operator-1',
                'host' => DOCTOR_RUN_CALLER_WG_IP,
                'wireguard_address' => DOCTOR_RUN_CALLER_WG_IP,
                'status' => 'active',
                'platform' => 'ubuntu',
            ]);
        NodeAccess::query()->updateOrCreate(
            [
                'consumer_node_id' => $caller->id,
                'serving_node_id' => $caller->id,
            ],
            [
                'permissions' => ['doctor:verify'],
                'custom_permissions' => ['doctor:verify'],
            ],
        );

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.node', 'operator-1')
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('rejects node=all as validation before resolving a target', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['node'],
                'mode' => 'verify',
                'node' => 'all',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.value', 'all');
    });

    it('rejects all=true on doctor fix requests because fleet resolution is verify-only', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'families' => ['node'],
                'mode' => 'restore',
                'all' => true,
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'all');
    });

    it('accepts the proxy family scope when targeting an app node', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', nodeLevelStdout: ''));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['proxy'],
                'mode' => 'verify',
                'node' => 'app-1',
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['proxy']);
    });

    it('filters API verify results by exact issue key', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux']);
        Node::factory()->create([
            'name' => 'incomplete-app',
            'status' => 'active',
            'platform' => null,
            'wireguard_address' => null,
        ]);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['node'],
                'node' => 'incomplete-app',
                'key' => 'node.record_incomplete',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.key', 'node.record_incomplete')
            ->assertJsonPath('success.data.doctor.issues.0.key', 'node.record_incomplete');
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->postJson('/api/doctor/run', ['families' => ['node']]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('rejects verify mode without authority on the resolved target before probing', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux'], role: 'app-dev');
        createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $shell = new DoctorRunRemoteShell(perRouteStdout: '');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['proxy'],
                'mode' => 'verify',
                'node' => 'app-1',
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'doctor:verify')
            ->assertJsonPath('error.meta.serving_node', 'app-1')
            ->assertJsonPath('error.meta.mode', 'verify');

        expect($shell->runs)->toBe(0);
    });

    it('rejects fleet verify when authority is missing on any target before probing', function (): void {
        $caller = createDoctorRunCallerNode(['platform' => 'linux'], role: 'app-dev');
        $authorizedTarget = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        createTestAppHostNode(['name' => 'app-2', 'status' => 'active']);
        NodeAccess::query()->updateOrCreate(
            [
                'consumer_node_id' => $caller->id,
                'serving_node_id' => $authorizedTarget->id,
            ],
            [
                'permissions' => ['doctor:verify'],
                'custom_permissions' => ['doctor:verify'],
            ],
        );
        $shell = new DoctorRunRemoteShell(perRouteStdout: '');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'families' => ['proxy'],
                'mode' => 'verify',
                'all' => true,
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'doctor:verify')
            ->assertJsonPath('error.meta.serving_node', 'app-2')
            ->assertJsonPath('error.meta.mode', 'verify');

        expect($shell->runs)->toBe(0);
    });

    it('restores firewall drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        app()->instance(
            RemoteShell::class,
            new DoctorRunRemoteShell(
                "Status: active\n\n     To                         Action      From\n     --                         ------      ----\n",
            ),
        );

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'restore',
                'families' => ['firewall_rule'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('restores proxy drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        $restoredHash = new ProxyRouteRenderer()->sourceHash($route);
        app()->instance(OrbitCaService::class, new DoctorRunFakeCa);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(
            perRouteStdout: '',
            nodeLevelStdout: '',
            routeProbeStdouts: [
                "0\t\t\t\t0\t0\n",
                "1\t{$restoredHash}\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
            ],
        ));

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'restore',
                'families' => ['proxy'],
                'node' => 'app-1',
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('does not report proxy convergence when drift remains after restore', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'ingress-1', 'status' => 'active']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'hauzer.app',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://10.6.0.2:80'],
                'upstream' => 'http://10.6.0.2:80',
            ],
        ]);
        $expectedHash = new ProxyRouteRenderer()->sourceHash($route);
        app()->instance(OrbitCaService::class, new DoctorRunFakeCa);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(
            perRouteStdout: '',
            nodeLevelStdout: '',
            routeProbeStdouts: [
                "0\t\t\t\t0\t0\n",
                "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/hauzer.app.crt\t/etc/orbit/certs/hauzer.app.key\t1\t1\n",
            ],
        ));

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'restore',
                'families' => ['proxy'],
                'node' => 'ingress-1',
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.summary.fixed', 0)
            ->assertJsonPath('success.data.doctor.summary.failed', 1)
            ->assertJsonPath('success.data.doctor.issues.0.key', 'proxy.route_mismatch')
            ->assertJsonPath('success.data.doctor.issues.0.detail.expected_hash', $expectedHash)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'failed')
            ->assertJsonPath('success.data.doctor.actions.0.details.node', 'ingress-1')
            ->assertJsonPath(
                'success.data.doctor.actions.0.details.operation',
                'verify proxy.route_mismatch',
            );
    });

    it('dry-runs API restore without applying fixers', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'],
                'upstream' => 'http://127.0.0.1:5173',
            ],
        ]);
        app()->instance(
            RemoteShell::class,
            new DoctorRunRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''),
        );

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'restore',
                'families' => ['proxy'],
                'node' => 'app-1',
                'dry_run' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.dry_run', true)
            ->assertJsonPath('success.data.doctor.summary.fixed', 0)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'planned');
    });

    it('accepts the tool family scope and returns tool drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $appNode->id, 'name' => 'composer']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', exitCode: 1));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['tool'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.issues.0.key', 'tool.capability_missing');
    });

    it('returns Colima remediation when a macos Docker provider is unreachable', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode([
            'name' => 'mac-app-1',
            'platform' => 'macos_14',
            'status' => 'active',
        ]);
        NodeTool::factory()->create(['node_id' => $appNode->id, 'name' => 'docker']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(
            perRouteStdout: "/opt/homebrew/bin/docker\tDocker version 27.0.0\tunknown\t\t\t\t\t\t\t\t0\tCannot connect to the Docker daemon\n",
        ));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['tool'],
                'node' => 'mac-app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.issues.0.key', 'tool.docker_provider_unreachable')
            ->assertJsonPath('success.data.doctor.issues.0.restorable', false)
            ->assertJsonPath('success.data.doctor.issues.0.detail.recommended_provider', 'colima')
            ->assertJsonPath('success.data.doctor.issues.0.detail.remediation_commands.0', 'brew install docker colima')
            ->assertJsonPath(
                'success.data.doctor.issues.0.detail.remediation_commands.1',
                'colima start --runtime docker',
            );
    });

    it('accepts the app family scope and returns app drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("docs\t0\t0\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n"));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['app'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['app'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'app.path_missing');
    });

    it('accepts the workspace family scope and returns workspace drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("feature\t0\t1\t0\t0\t1\t1\t0\t0\t0\t\n"));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['workspace'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['workspace'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'workspace.path_missing');
    });

    it('accepts the process family scope and returns process drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(perRouteStdout: '', exitCode: 1));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['process'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.families', ['process'])
            ->assertJsonPath('success.data.doctor.issues.0.key', 'process.runtime_backend_unavailable');
    });

    it('accepts the process family scope when metrics is co-located with gateway', function (): void {
        app()->instance(
            \App\Services\RemoteShell\RunsInternalCommands::class,
            app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
        );
        createDoctorRunCallerNode();
        ProcessFacade::preventStrayProcesses();
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "orbit-container-scan:absent\n"),
        ]);
        $gateway = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway',
                'status' => 'active',
                'platform' => 'debian_12',
                'wireguard_address' => '10.6.0.2',
            ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $gateway->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['process'],
                'node' => 'gateway',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['process']);
    });

    it('runs process family doctor across active role nodes only with explicit all scope', function (): void {
        app()->instance(
            \App\Services\RemoteShell\RunsInternalCommands::class,
            app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
        );
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        Node::factory()->create([
            'name' => 'operator-1',
            'status' => 'active',
            'platform' => 'ubuntu',
            'wireguard_address' => '10.6.0.3',
        ]);
        ProcessFacade::preventStrayProcesses();
        ProcessFacade::fake([
            '*' => ProcessFacade::result(output: "orbit-container-scan:absent\n"),
        ]);
        Http::preventStrayRequests();
        Http::fake([
            "http://{$appNode->wireguard_address}:9477/v1/commands" => Http::response(
                doctor_run_runtime_inventory_agent_response(),
            ),
        ]);

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['process'],
                'all' => true,
            ],
            [],
            [],
            [
                'REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP,
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.role', 'fleet')
            ->assertJsonPath('success.data.doctor.scope.node', null)
            ->assertJsonPath('success.data.doctor.scope.targets', ['app-1', 'caller'])
            ->assertJsonPath('success.data.doctor.nodes.0.node', 'app-1')
            ->assertJsonPath('success.data.doctor.nodes.1.node', 'caller');
    });

    it('returns fleet doctor JSON when a node proxy probe raises RemoteShellFailed', function (): void {
        createDoctorRunCallerNode();
        createTestAppHostNode(['name' => 'app-dev-1', 'status' => 'active']);
        createTestAppHostNode(['name' => 'app-prod-1', 'status' => 'active'], 'app-prod');

        app()->instance(RemoteShell::class, new DoctorRunFleetRemoteShell(failingNodeName: 'app-prod-1'));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['proxy'],
                'all' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.scope.role', 'fleet');

        $doctor = $response->json('success.data.doctor');

        $failedIssue = collect($doctor['issues'] ?? [])->firstWhere('key', 'proxy.node_probe_failed');

        expect($failedIssue)
            ->not
            ->toBeNull()
            ->and($failedIssue['kind'] ?? null)
            ->toBe('unverifiable')
            ->and($failedIssue['restorable'] ?? null)
            ->toBeFalse()
            ->and($failedIssue['adoptable'] ?? null)
            ->toBeFalse()
            ->and(collect($doctor['nodes'] ?? [])->firstWhere('node', 'app-dev-1')['healthy'] ?? null)
            ->toBeTrue()
            ->and(collect($doctor['nodes'] ?? [])->firstWhere('node', 'app-prod-1')['healthy'] ?? null)
            ->toBeFalse();
    });

    it('restores tool drift through the doctor fix endpoint', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'caddy',
            'expected_state' => 'installed',
            'expected_version' => '2.9',
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell(
            "/usr/bin/caddy\t2.8.4\tstopped\n",
            perRouteStdouts: [
                // First call: probe returns installed but mismatched version (2.8.4 vs expected 2.9)
                "/usr/bin/caddy\t2.8.4\tstopped\n",
                // Second call: update script runs and succeeds
                '',
                // Third call: re-probe after fix confirms correct version
                "/usr/bin/caddy\t2.9.0\tstopped\n",
            ],
        ));
        bind_tool_script_dispatcher_to_remote_shell();

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'restore',
                'families' => ['tool'],
                'node' => 'app-1',
            ],
            [],
            [],
            doctor_run_explicit_fallback_server(),
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'restore')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('accepts the schedule family scope and returns schedule health', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $appNode->id]);
        Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $appNode->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("running\n"));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['schedule'],
                'node' => 'app-1',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['schedule']);
    });

    it('requires doctor write authority for fix mode requests', function (): void {
        createDoctorRunCallerNode(role: 'app-dev');
        $shell = new DoctorRunRemoteShell('');
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'adopt',
                'families' => ['firewall_rule'],
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'doctor:adopt')
            ->assertJsonPath('error.meta.mode', 'adopt');

        expect($shell->runs)->toBe(0);
    });

    it('allows app-node fix mode requests with explicit doctor authority', function (): void {
        $caller = createDoctorRunCallerNode(['platform' => 'linux'], role: 'app-dev');
        NodeAccess::query()->updateOrCreate(
            [
                'consumer_node_id' => $caller->id,
                'serving_node_id' => $caller->id,
            ],
            [
                'permissions' => ['doctor:adopt'],
                'custom_permissions' => ['doctor:adopt'],
            ],
        );

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'adopt',
                'families' => ['node'],
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'adopt')
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('reflects app scope in database_connection verify JSON and limits issues to the scoped app', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'beast', 'status' => 'active']);

        $dngdmtPath = storage_path('framework/testing/doctor-run-db-scope-verify-dngdmt');
        $otherPath = storage_path('framework/testing/doctor-run-db-scope-verify-other');
        File::ensureDirectoryExists($dngdmtPath);
        File::ensureDirectoryExists($otherPath);

        $dngdmt = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'dngdmt',
            'path' => $dngdmtPath,
        ]);
        $other = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'other-app',
            'path' => $otherPath,
        ]);

        $fixturePassword = substr(hash('sha256', 'doctor-run db scope verify app credentials'), 0, 16);

        foreach ([$dngdmt, $other] as $app) {
            $connection = DatabaseConnection::factory()->create([
                'slug' => $app->name,
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => $app->name,
                'username' => 'orbit',
                'credentials' => ['password' => $fixturePassword],
            ]);
            DatabaseConnectionTarget::factory()
                ->forAppInstance(doctorRunDatabaseAppInstance($app))
                ->create([
                    'database_connection_id' => $connection->id,
                    'env_prefix' => 'DB',
                ]);
        }

        app()->instance(RemoteShell::class, new DoctorRunDatabaseScopeRemoteShell([
            $dngdmtPath.'/.env' => "DB_CONNECTION=mysql\n",
            $otherPath.'/.env' => "DB_CONNECTION=mysql\n",
        ]));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['database_connection'],
                'node' => 'beast',
                'app' => 'dngdmt',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.app', 'dngdmt')
            ->assertJsonPath('success.data.doctor.scope.workspace', null);

        $issueApps = collect($response->json('success.data.doctor.issues'))
            ->pluck('detail.app')
            ->filter()
            ->unique()
            ->values()
            ->all();

        expect($issueApps)->toBe(['dngdmt']);
    });

    it('reflects workspace scope in database_connection verify JSON and limits issues to the scoped workspace', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'beast', 'status' => 'active']);

        $featurePath = storage_path('framework/testing/doctor-run-db-scope-verify-feature');
        $hotfixPath = storage_path('framework/testing/doctor-run-db-scope-verify-hotfix');
        File::ensureDirectoryExists($featurePath);
        File::ensureDirectoryExists($hotfixPath);

        $app = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'docs',
            'path' => storage_path('framework/testing/doctor-run-db-scope-verify-docs'),
        ]);
        $feature = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => $featurePath,
        ]);
        $hotfix = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'hotfix',
            'path' => $hotfixPath,
        ]);

        $fixturePassword = substr(hash('sha256', 'doctor-run db scope verify workspace credentials'), 0, 16);

        foreach ([$feature, $hotfix] as $workspace) {
            $connection = DatabaseConnection::factory()->create([
                'slug' => $workspace->name.'-docs',
                'driver' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => 'docs',
                'username' => 'orbit',
                'credentials' => ['password' => $fixturePassword],
            ]);
            DatabaseConnectionTarget::factory()
                ->forWorkspace($workspace)
                ->create([
                    'database_connection_id' => $connection->id,
                    'env_prefix' => 'DB',
                ]);
        }

        app()->instance(RemoteShell::class, new DoctorRunDatabaseScopeRemoteShell([
            $featurePath.'/.env' => "DB_CONNECTION=mysql\n",
            $hotfixPath.'/.env' => "DB_CONNECTION=mysql\n",
        ]));

        $response = $this->call(
            'POST',
            '/api/doctor/run',
            [
                'mode' => 'verify',
                'families' => ['database_connection'],
                'node' => 'beast',
                'workspace' => 'feature',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.workspace', 'feature')
            ->assertJsonPath('success.data.doctor.scope.app', null);

        $issueWorkspaces = collect($response->json('success.data.doctor.issues'))
            ->pluck('detail.workspace')
            ->filter()
            ->unique()
            ->values()
            ->all();

        expect($issueWorkspaces)->toBe(['feature']);
    });

    it(
        'reflects combined app and workspace scope in database_connection verify JSON and disambiguates duplicate workspace names',
        function (): void {
            createDoctorRunCallerNode();
            $appNode = createTestAppHostNode(['name' => 'beast', 'status' => 'active']);

            $docsFeaturePath = storage_path('framework/testing/doctor-run-db-scope-verify-docs-feature');
            $billingFeaturePath = storage_path('framework/testing/doctor-run-db-scope-verify-billing-feature');
            File::ensureDirectoryExists($docsFeaturePath);
            File::ensureDirectoryExists($billingFeaturePath);

            $docs = App::factory()->create([
                'node_id' => $appNode->id,
                'name' => 'docs',
                'path' => storage_path('framework/testing/doctor-run-db-scope-verify-docs-root'),
            ]);
            $billing = App::factory()->create([
                'node_id' => $appNode->id,
                'name' => 'billing',
                'path' => storage_path('framework/testing/doctor-run-db-scope-verify-billing-root'),
            ]);

            $docsFeature = Workspace::factory()->create([
                'app_id' => $docs->id,
                'name' => 'feature',
                'path' => $docsFeaturePath,
            ]);
            $billingFeature = Workspace::factory()->create([
                'app_id' => $billing->id,
                'name' => 'feature',
                'path' => $billingFeaturePath,
            ]);

            $fixturePassword = substr(hash('sha256', 'doctor-run db scope verify combined credentials'), 0, 16);

            foreach ([$docsFeature, $billingFeature] as $workspace) {
                $connection = DatabaseConnection::factory()->create([
                    'slug' => $workspace->name.'-'.$workspace->app?->name,
                    'driver' => 'pgsql',
                    'host' => 'db.internal',
                    'port' => 5432,
                    'database' => $workspace->app?->name,
                    'username' => 'orbit',
                    'credentials' => ['password' => $fixturePassword],
                ]);
                DatabaseConnectionTarget::factory()
                    ->forWorkspace($workspace)
                    ->create([
                        'database_connection_id' => $connection->id,
                        'env_prefix' => 'DB',
                    ]);
            }

            app()->instance(RemoteShell::class, new DoctorRunDatabaseScopeRemoteShell([
                $docsFeaturePath.'/.env' => "DB_CONNECTION=mysql\n",
                $billingFeaturePath.'/.env' => "DB_CONNECTION=mysql\n",
            ]));

            $response = $this->call(
                'POST',
                '/api/doctor/run',
                [
                    'mode' => 'verify',
                    'families' => ['database_connection'],
                    'node' => 'beast',
                    'app' => 'docs',
                    'workspace' => 'feature',
                ],
                [],
                [],
                ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
            );

            $response
                ->assertOk()
                ->assertJsonPath('success.data.doctor.scope.app', 'docs')
                ->assertJsonPath('success.data.doctor.scope.workspace', 'feature');

            $issueDetails = collect($response->json('success.data.doctor.issues'))
                ->pluck('detail')
                ->filter()
                ->values();

            expect($issueDetails->pluck('workspace')->filter()->unique()->values()->all())
                ->toBe(['feature'])
                ->and($issueDetails->pluck('app')->filter()->unique()->values()->all())
                ->toBe(['docs']);
        },
    );

    it('dry-runs app-scoped database_connection adopt plans only the scoped app target', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'beast', 'status' => 'active']);

        $dngdmtPath = storage_path('framework/testing/doctor-run-db-scope-adopt-dngdmt');
        $otherPath = storage_path('framework/testing/doctor-run-db-scope-adopt-other');
        File::ensureDirectoryExists($dngdmtPath);
        File::ensureDirectoryExists($otherPath);

        $dngdmt = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'dngdmt',
            'path' => $dngdmtPath,
        ]);
        doctorRunDatabaseAppInstance($dngdmt);
        $other = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'other-app',
            'path' => $otherPath,
        ]);
        doctorRunDatabaseAppInstance($other);

        $completeEnv = "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n";

        app()->instance(RemoteShell::class, new DoctorRunDatabaseScopeRemoteShell([
            $dngdmtPath.'/.env' => $completeEnv,
            $otherPath.'/.env' => $completeEnv,
        ]));

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'adopt',
                'families' => ['database_connection'],
                'node' => 'beast',
                'app' => 'dngdmt',
                'dry_run' => true,
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.dry_run', true)
            ->assertJsonPath('success.data.doctor.scope.app', 'dngdmt')
            ->assertJsonPath('success.data.doctor.scope.workspace', null);

        $actions = collect($response->json('success.data.doctor.actions'));

        expect($actions)
            ->toHaveCount(1)
            ->and($actions->pluck('details.app')->filter()->unique()->values()->all())
            ->toBe(['dngdmt'])
            ->and($actions->first())
            ->not->toHaveKey('detail');
    });

    it('adopts database_connection state only for the scoped app target', function (): void {
        createDoctorRunCallerNode();
        $appNode = createTestAppHostNode(['name' => 'beast', 'status' => 'active']);

        $dngdmtPath = storage_path('framework/testing/doctor-run-db-scope-adopt-mutate-dngdmt');
        $otherPath = storage_path('framework/testing/doctor-run-db-scope-adopt-mutate-other');
        File::ensureDirectoryExists($dngdmtPath);
        File::ensureDirectoryExists($otherPath);

        $dngdmt = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'dngdmt',
            'path' => $dngdmtPath,
        ]);
        doctorRunDatabaseAppInstance($dngdmt);
        $other = App::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'other-app',
            'path' => $otherPath,
        ]);
        doctorRunDatabaseAppInstance($other);

        $completeEnv = "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n";

        app()->instance(RemoteShell::class, new DoctorRunDatabaseScopeRemoteShell([
            $dngdmtPath.'/.env' => $completeEnv,
            $otherPath.'/.env' => $completeEnv,
        ]));

        $response = $this->call(
            'POST',
            '/api/doctor/fix',
            [
                'mode' => 'adopt',
                'families' => ['database_connection'],
                'node' => 'beast',
                'app' => 'dngdmt',
            ],
            [],
            [],
            ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.doctor.scope.app', 'dngdmt');

        expect(DatabaseConnection::query()->count())
            ->toBe(1)
            ->and(DatabaseConnection::query()->where('slug', 'dngdmt-development')->exists())
            ->toBeTrue()
            ->and(DatabaseConnection::query()->where('slug', 'other-app-development')->exists())
            ->toBeFalse();
    });
});

function doctorRunDatabaseAppInstance(App $app): AppInstance
{
    $instance = $app->instances()->first();

    if ($instance instanceof AppInstance) {
        return $instance;
    }

    return AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $app->node_id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
}

final readonly class DoctorRunDatabaseScopeRemoteShell implements RemoteShell
{
    /**
     * @param  array<string, string>  $envByPath
     */
    public function __construct(
        private array $envByPath,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'internal:env-file')) {
            $payload = json_decode((string) ($options['input'] ?? ''), associative: true);
            $path = is_array($payload) && is_string($payload['path'] ?? null) ? $payload['path'] : '';
            $env = $this->envByPath[$path] ?? null;

            if (! is_string($env)) {
                return new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
            }

            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'success' => [
                        'data' => [
                            'contents' => $env,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }

        if (preg_match("/test -f '([^']+)' && cat '([^']+)'/", $script, $matches) === 1) {
            $path = $matches[1];
            $env = $this->envByPath[$path] ?? null;

            if (! is_string($env)) {
                return new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);
            }

            return new RemoteShellResult(exitCode: 0, stdout: $env, stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final readonly class DoctorRunFleetRemoteShell implements RemoteShell
{
    public function __construct(
        private string $failingNodeName = 'app-prod-1',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (
            $node->name === $this->failingNodeName
            && str_contains($script, '/etc/caddy/sites/*.caddy')
            && ($options['throw'] ?? false) === true
        ) {
            $result = new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);

            throw new RemoteShellFailed($node, $script, $result);
        }

        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, '/etc/caddy/Caddyfile')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: "1\t".base64_encode(new CaddyGlobalConfig()->fresh())."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class DoctorRunRemoteShell implements RemoteShell
{
    public int $runs = 0;

    /** @var list<string> */
    private array $perRouteStdouts;

    /** @var list<string> */
    private array $routeProbeStdouts;

    /**
     * @param  list<string>  $perRouteStdouts
     * @param  list<string>  $routeProbeStdouts
     */
    public function __construct(
        string $perRouteStdout,
        private readonly string $nodeLevelStdout = '',
        private readonly int $exitCode = 0,
        array $perRouteStdouts = [],
        array $routeProbeStdouts = [],
    ) {
        $this->perRouteStdouts = $perRouteStdouts === [] ? [$perRouteStdout] : $perRouteStdouts;
        $this->routeProbeStdouts = $routeProbeStdouts;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs++;

        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, "dir='/home/orbit/.config/orbit/apps'")) {
            // Emit the probe sentinel so introspectNodeRuntimeConfigs reports
            // the directory as proven-absent instead of treating empty stdout
            // as an unknown sudo/probe failure.
            return new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            // Default: orbit-caddy container is healthy on serving nodes.
            return new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1);
        }

        if (str_contains($script, '/etc/caddy/Caddyfile')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: "1\t".base64_encode(new CaddyGlobalConfig()->fresh())."\n",
                stderr: '',
                durationMs: 1,
            );
        }

        if ($this->routeProbeStdouts !== [] && str_contains($script, '$ORBIT_PROXY_DOMAIN')) {
            return new RemoteShellResult(
                exitCode: $this->exitCode,
                stdout: array_shift($this->routeProbeStdouts) ?? '',
                stderr: '',
                durationMs: 1,
            );
        }

        $isNodeLevel = str_contains($script, '/etc/caddy/sites/*.caddy');
        $stdout = $isNodeLevel
            ? $this->nodeLevelStdout
            : array_shift($this->perRouteStdouts) ?? '';

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $stdout, stderr: '', durationMs: 1);
    }
}

final readonly class DoctorRunFakeCa extends OrbitCaService
{
    /** @return array{cert: string, key: string} */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-doctor-run-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";

        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}
