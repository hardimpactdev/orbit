<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessEventType;
use App\Enums\ProcessRestartPolicy;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_LIST_CALLER_WG_IP = '10.6.0.88';

function createProcessListCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROCESS_LIST_CALLER_WG_IP,
        'wireguard_address' => PROCESS_LIST_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessListAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessListController', function (): void {
    it('rejects app-prod callers targeting workspace processes despite a legacy read grant', function (): void {
        $caller = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-caller',
                'host' => PROCESS_LIST_CALLER_WG_IP,
                'wireguard_address' => PROCESS_LIST_CALLER_WG_IP,
            ]);
        $appNode = createTestAppHostNode(['name' => 'app-dev-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $instance = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);
        Process::factory()
            ->forOwner($app, $appNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'vite',
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs.development&workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'workspace.unsupported_for_production')
            ->assertJsonPath('error.meta.node', 'app-prod-caller')
            ->assertJsonPath('error.meta.role', 'app-prod');
    });

    it('filters process events by the selected app instance', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $developmentNode = createTestAppHostNode(['name' => 'app-development']);
        $productionNode = createTestAppHostNode(['name' => 'app-production']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $developmentNode->id]);
        $development = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $developmentNode->id),
        ]);
        $production = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $productionNode->id),
        ]);
        $process = Process::factory()
            ->forOwner($app, $developmentNode)
            ->create([
                'app_instance_id' => $development->id,
                'name' => 'vite',
            ]);
        $developmentEvent = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $process->id,
            'app_id' => $app->id,
            'app_instance_id' => $development->id,
            'node_id' => $developmentNode->id,
            'recorded_at' => now()->subMinute(),
        ]);
        ProcessEvent::factory()->create([
            'event' => ProcessEventType::Crashed,
            'process_id' => $process->id,
            'app_id' => $app->id,
            'app_instance_id' => $production->id,
            'node_id' => $productionNode->id,
            'recorded_at' => now(),
        ]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs.development',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context.instance', 'development')
            ->assertJsonPath('success.data.processes.0.status', 'running')
            ->assertJsonPath('success.data.processes.0.last_event.id', $developmentEvent->id)
            ->assertJsonPath('success.data.processes.0.last_event.type', 'started');
    });

    it('lists app processes in process order with runtime units', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);

        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'restart_policy' => ProcessRestartPolicy::Always,
                'crash_notification' => ProcessCrashNotification::None,
                'sort_order' => 20,
            ]);
        Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'vite',
                'command' => 'npm run dev',
                'restart_policy' => ProcessRestartPolicy::Never,
                'crash_notification' => ProcessCrashNotification::None,
                'sort_order' => 10,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context', [
                'node' => 'app-1',
                'project' => 'docs',
                'instance' => 'development',
                'workspace' => null,
            ])
            ->assertJsonPath('success.data.processes.0.name', 'vite')
            ->assertJsonPath('success.data.processes.0.instance', 'development')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit_docs_development_main_vite')
            ->assertJsonPath('success.data.processes.0.status', 'unknown')
            ->assertJsonPath('success.data.processes.0.last_event', null)
            ->assertJsonPath('success.data.processes.1.name', 'queue');
    });

    it('uses workspace context for inherited process runtime units', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()->forOwner($app)->create(['name' => 'vite', 'sort_order' => 1]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs&workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context', [
                'node' => 'app-1',
                'project' => 'docs',
                'instance' => 'development',
                'workspace' => 'feature-docs',
            ])
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit_docs_development_feature-docs_vite');
    });

    it('lists workspace owned process rows for workspace context', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $workspace = Workspace::factory()->create(['name' => 'feature-docs', 'app_id' => $app->id]);
        Process::factory()
            ->forOwner($workspace)
            ->create([
                'name' => 'frankenphp-docs-feature-docs',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => ['container_name' => 'orbit-ws-docs-feature-docs'],
                'sort_order' => 1,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs&workspace=feature-docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context', [
                'node' => 'app-1',
                'project' => 'docs',
                'instance' => 'development',
                'workspace' => 'feature-docs',
            ])
            ->assertJsonPath('success.data.processes.0.name', 'frankenphp-docs-feature-docs')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit-ws-docs-feature-docs');
    });

    it('lists node owned process rows for node context', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $node = createTestAppHostNode(['name' => 'app-1']);
        Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'opencode-server',
                'runtime' => ProcessRuntime::Systemd,
                'tool' => 'opencode-cli',
                'sort_order' => 1,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?node=app-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context', [
                'node' => 'app-1',
                'project' => null,
                'instance' => null,
                'workspace' => null,
            ])
            ->assertJsonPath('success.data.processes.0.name', 'opencode-server')
            ->assertJsonPath('success.data.processes.0.tool', 'opencode-cli')
            ->assertJsonPath('success.data.processes.0.runtime', 'systemd')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'opencode-server');
    });

    it('lets authorized callers list processes for active role-bearing nodes', function (): void {
        $caller = createProcessListCallerNode();
        $gateway = createTestGatewayNode(['name' => 'gateway']);
        grantProcessListAccess($caller, $gateway);
        Process::factory()
            ->forOwner($gateway)
            ->create([
                'name' => 'prometheus',
                'runtime' => ProcessRuntime::DockerSwarm,
                'runtime_config' => ['service_name' => 'orbit-prometheus'],
                'sort_order' => 1,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?node=gateway',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context', [
                'node' => 'gateway',
                'project' => null,
                'instance' => null,
                'workspace' => null,
            ])
            ->assertJsonPath('success.data.processes.0.name', 'prometheus')
            ->assertJsonPath('success.data.processes.0.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit-prometheus');
    });

    it('lists managed service connection metadata for node owned service processes without exposing credential values', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $node = createTestAppHostNode([
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
                'sort_order' => 1,
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?node=database-1',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.processes.0.name', 'mysql8')
            ->assertJsonPath('success.data.processes.0.tool', null)
            ->assertJsonPath('success.data.processes.0.runtime', 'docker-swarm')
            ->assertJsonPath('success.data.processes.0.runtime_unit', 'orbit-mysql8')
            ->assertJsonPath('success.data.processes.0.service.service', 'mysql')
            ->assertJsonPath('success.data.processes.0.service.version_family', '8')
            ->assertJsonPath('success.data.processes.0.service.version', '8.4')
            ->assertJsonPath('success.data.processes.0.service.endpoint.host', '10.6.0.44')
            ->assertJsonPath('success.data.processes.0.service.endpoint.port', 3308)
            ->assertJsonPath('success.data.processes.0.service.credential_fields', ['database', 'password', 'username'])
            ->assertJsonMissingPath('success.data.processes.0.service.credentials');
    });

    it('omits process intent hidden from the caller', function (): void {
        $caller = createProcessListCallerNode();
        $visibleNode = createTestAppHostNode();
        $hiddenNode = createTestAppHostNode();
        grantProcessListAccess($caller, $visibleNode);

        Project::factory()->create(['name' => 'visible', 'node_id' => $visibleNode->id]);
        $hiddenApp = Project::factory()->create(['name' => 'hidden', 'node_id' => $hiddenNode->id]);
        Process::factory()->forOwner($hiddenApp)->create(['name' => 'queue']);

        $response = $this->call(
            'GET',
            '/api/processes?instance=hidden',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance');
    });

    it('defaults by concrete app instance placement instead of legacy app placement', function (): void {
        $caller = createProcessListCallerNode();
        $legacyNode = createTestAppHostNode(['name' => 'legacy-app-node']);
        $instanceNode = createTestAppHostNode(['name' => 'instance-node']);
        grantProcessListAccess($caller, $instanceNode);
        $app = Project::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $instanceNode->id),
        ]);
        Process::factory()
            ->forOwner($app, $instanceNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'queue',
            ]);

        $this
            ->call('GET', '/api/processes', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP])
            ->assertOk()
            ->assertJsonPath('success.data.context.node', 'instance-node')
            ->assertJsonPath('success.data.context.instance', 'production');
    });

    it('does not default when multiple concrete instances are visible across mixed apps', function (): void {
        createProcessListCallerNode(role: 'gateway');
        $singleNode = createTestAppHostNode(['name' => 'single-node']);
        $developmentNode = createTestAppHostNode(['name' => 'development-node']);
        $productionNode = createTestAppHostNode(['name' => 'production-node']);
        $singleApp = Project::factory()->for($singleNode, 'node')->create(['name' => 'single']);
        $multiApp = Project::factory()->for($developmentNode, 'node')->create(['name' => 'multi']);
        AppInstance::factory()->for($singleApp)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $singleNode->id),
        ]);
        AppInstance::factory()->for($multiApp)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $developmentNode->id),
        ]);
        AppInstance::factory()->for($multiApp)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $productionNode->id),
        ]);

        $this
            ->call('GET', '/api/processes', [], [], [], ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP])
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance');
    });

    it('does not authorize a concrete app instance through legacy app placement', function (): void {
        $caller = createProcessListCallerNode();
        $legacyNode = createTestAppHostNode(['name' => 'legacy-app-node']);
        $instanceNode = createTestAppHostNode(['name' => 'instance-node']);
        grantProcessListAccess($caller, $legacyNode);
        $app = Project::factory()->for($legacyNode, 'node')->create(['name' => 'docs']);
        AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $instanceNode->id),
        ]);

        $this
            ->call(
                'GET',
                '/api/processes?instance=docs.production',
                [],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'instance');
    });

    it('returns authorization failure when the caller has no process visibility', function (): void {
        createProcessListCallerNode();
        $appNode = createTestAppHostNode();
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->forOwner($app)->create();

        $response = $this->call(
            'GET',
            '/api/processes?instance=docs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:read');
    });

    it('returns validation errors for missing and unknown contexts', function (string $query, string $field): void {
        createProcessListCallerNode(role: 'gateway');
        createTestAppHostNode();

        $response = $this->call(
            'GET',
            "/api/processes{$query}",
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);
    })->with([
        'missing instance' => ['', 'instance'],
        'unknown instance' => ['?instance=missing', 'instance'],
        'unknown workspace' => ['?workspace=missing', 'workspace'],
    ]);

    it('rejects unauthenticated requests', function (): void {
        $response = $this->getJson('/api/processes?instance=docs');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.message', 'Peer identity unknown.');
    });

    it('lists processes for an app hostname resolved via exact proxy_routes.domain', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $instance = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'app_instance' => [
                    'name' => 'development',
                    'selector' => 'docs.development',
                ],
            ],
        ]);
        Process::factory()
            ->forOwner($app, $appNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'vite',
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?app=test.app.example',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context.project', 'docs')
            ->assertJsonPath('success.data.context.instance', 'development')
            ->assertJsonPath('success.data.processes.0.name', 'vite')
            ->assertJsonPath('success.data.processes.0.status', 'unknown');
    });

    it('lists processes for a workspace hostname via app selector', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $instance = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'feature-docs.app.example',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        Process::factory()
            ->forOwner($app, $appNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'vite',
            ]);

        $response = $this->call(
            'GET',
            '/api/processes?app=feature-docs.app.example',
            [],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.context.workspace', 'feature-docs')
            ->assertJsonPath('success.data.processes.0.name', 'vite');
    });

    it('rejects combining app with instance selectors', function (): void {
        createProcessListCallerNode(role: 'gateway');

        $this
            ->call(
                'GET',
                '/api/processes?app=test.app.example&instance=docs',
                [],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'context');
    });

    it('rejects app selectors that include a scheme, path, or port', function (): void {
        createProcessListCallerNode(role: 'gateway');

        $this
            ->call(
                'GET',
                '/api/processes?app=https://test.app.example',
                [],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'app')
            ->assertJsonPath('error.meta.reason', 'hostname_only');
    });

    it('admits browser CORS preflight for a registered Origin without peer-IP headers', function (): void {
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $response = $this->call(
            'OPTIONS',
            '/api/processes',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://test.app.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            ],
        );

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://test.app.example')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST')
            ->assertHeader(
                'Access-Control-Allow-Headers',
                'Accept, Content-Type, Last-Event-ID, X-Orbit-Client, X-Correlation-Id',
            )
            ->assertHeader('Vary', 'Origin, Access-Control-Request-Method, Access-Control-Request-Headers');

        expect($response->headers->get('Access-Control-Allow-Headers') ?? '')
            ->not->toContain('X-Orbit-WireGuard-Ip')
            ->not->toContain('X-Orbit-E2E-WireGuard-Ip');
    });

    it('rejects browser CORS preflight that requests peer-IP identity headers', function (): void {
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $this
            ->call(
                'OPTIONS',
                '/api/processes',
                [],
                [],
                [],
                [
                    'HTTP_ORIGIN' => 'https://test.app.example',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                    'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-Orbit-WireGuard-Ip',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.field', 'access-control-request-headers');
    });

    it('rejects browser CORS preflight for an unregistered origin', function (): void {
        $this
            ->call(
                'OPTIONS',
                '/api/processes',
                [],
                [],
                [],
                [
                    'HTTP_ORIGIN' => 'https://evil.example',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.field', 'origin');
    });

    it('rejects browser CORS preflight for a registered hostname with a non-default origin port', function (): void {
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $this
            ->call(
                'OPTIONS',
                '/api/processes',
                [],
                [],
                [],
                [
                    'HTTP_ORIGIN' => 'https://test.app.example:8443',
                    'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.field', 'origin')
            ->assertJsonPath('error.meta.value', 'https://test.app.example:8443');
    });

    it('rejects browser Origin with a non-default port even when the hostname matches app', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'app_instance' => [
                    'name' => 'development',
                    'selector' => 'docs.development',
                ],
            ],
        ]);

        $this
            ->call(
                'GET',
                '/api/processes?app=test.app.example',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP,
                    'HTTP_ORIGIN' => 'https://test.app.example:8443',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.field', 'origin')
            ->assertJsonPath('error.meta.value', 'https://test.app.example:8443');
    });

    it('authorizes browser process list from peer source IP while CORS only matches Origin to app', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $instance = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'app_instance' => [
                    'name' => 'development',
                    'selector' => 'docs.development',
                ],
            ],
        ]);
        Process::factory()
            ->forOwner($app, $appNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'vite',
            ]);

        // Same auth model as CLI: peer source IP only. No bearer, no peer-IP header.
        // Origin is CORS admission against app, not identity.
        $server = [
            'REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP,
            'HTTP_ORIGIN' => 'https://test.app.example',
            'HTTP_X_ORBIT_CLIENT' => 'laravel-toolbar',
        ];

        expect($server)
            ->not->toHaveKey('HTTP_X_ORBIT_WIREGUARD_IP')
            ->not->toHaveKey('HTTP_X_ORBIT_E2E_WIREGUARD_IP')
            ->not->toHaveKey('HTTP_AUTHORIZATION');

        $response = $this->call(
            'GET',
            '/api/processes?app=test.app.example',
            [],
            [],
            [],
            $server,
        );

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://test.app.example')
            ->assertHeader('Vary', 'Origin')
            ->assertJsonPath('success.data.processes.0.name', 'vite');
    });

    it('rejects browser CORS Origin that mismatches the requested app target', function (): void {
        $caller = createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessListAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'other.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $this
            ->call(
                'GET',
                '/api/processes?app=test.app.example',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP,
                    'HTTP_ORIGIN' => 'https://other.app.example',
                ],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.field', 'origin');
    });

    it('keeps CORS headers on grant failures after Origin admission without treating Origin as identity', function (): void {
        createProcessListCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $response = $this->call(
            'GET',
            '/api/processes?app=test.app.example',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => PROCESS_LIST_CALLER_WG_IP,
                'HTTP_ORIGIN' => 'https://test.app.example',
            ],
        );

        $response
            ->assertForbidden()
            ->assertHeader('Access-Control-Allow-Origin', 'https://test.app.example')
            ->assertHeader('Vary', 'Origin')
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});
