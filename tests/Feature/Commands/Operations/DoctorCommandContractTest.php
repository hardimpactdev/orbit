<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Doctor\FixDoctorRequest;
use App\Http\Gateway\Requests\Doctor\RunDoctorRequest;
use App\Models\App;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Models\Workspace;
use App\Services\Ca\OrbitCaService;
use App\Services\Platform\PlatformDetector;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createDoctorLocalNode(string $role = 'gateway'): Node
{
    config(['orbit.is_gateway' => $role === 'gateway']);

    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'linux',
        'environment' => $role === 'app' ? 'development' : null,
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);
    }

    if ($role === 'app') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-development',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
    }

    return $node;
}

function createDoctorHostedAppNode(string $name = 'app-1', array $attributes = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => $name,
        'role' => 'app',
        'status' => 'active',
        'environment' => 'development',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'platform' => 'ubuntu_24-04',
    ], $attributes));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-development',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function createDoctorIngressNode(string $name = 'edge-1'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'role' => 'control',
        'status' => 'active',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'platform' => 'ubuntu_24-04',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

function createDoctorRouterNode(string $name = 'gateway-1'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'role' => 'gateway',
        'status' => 'active',
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'platform' => 'ubuntu_24-04',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'router',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

function createDoctorProductionBackendNode(string $name = 'web-1'): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'role' => 'control',
        'status' => 'active',
        'host' => '10.6.0.21',
        'wireguard_address' => '10.6.0.21',
        'platform' => 'ubuntu_24-04',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-production',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

describe('doctor command contract', function (): void {
    it('registers key and dry-run options', function (): void {
        $definition = Artisan::all()['doctor']->getDefinition();

        expect($definition->hasOption('key'))->toBeTrue()
            ->and($definition->hasOption('dry-run'))->toBeTrue();
    });

    it('runs the node family locally for gateway callers', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('verify')
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['node'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('returns drift failure when node probe reports issues', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => null]);

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['healthy'])->toBeFalse()
            ->and($payload['error']['data']['doctor']['issues'][0]['family'])->toBe('node');
    });

    it('filters reported drift by exact issue key', function (): void {
        createDoctorLocalNode('gateway');
        Node::factory()->create([
            'name' => 'legacy-app',
            'role' => 'app',
            'status' => 'active',
            'platform' => null,
            'wireguard_address' => null,
        ]);

        $exitCode = Artisan::call('doctor', [
            '--node' => 'legacy-app',
            '--family' => ['node'],
            '--key' => 'node.record_incomplete',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $issues = $payload['error']['data']['doctor']['issues'];

        expect($exitCode)->toBe(1)
            ->and(array_column($issues, 'key'))->toBe(['node.record_incomplete']);
    });

    it('renders the healthy human doctor report with the result divider and clean banner', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['node']]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('D O C T O R  R E S U L T')
            ->and($output)->toContain('Successfully performed check-up on local-gateway')
            ->and($output)->toContain('Node')
            ->and($output)->toContain('OK')
            ->and($output)->toContain('S U M M A R Y')
            ->and($output)->toContain('┌')
            ->and($output)->toContain('No issues detected');
    });

    it('renders remaining issues in the human doctor report', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => null]);

        $exitCode = Artisan::call('doctor', ['--family' => ['node']]);
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('D O C T O R  R E S U L T')
            ->and($output)->toContain('Successfully performed check-up on local-gateway')
            ->and($output)->toContain('issues')
            ->and($output)->toContain('Node')
            ->and($output)->toContain('┌')
            ->and($output)->toContain('Node record for local-gateway is missing');
    });

    it('renders completed restore actions in the human doctor report without fix mode', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--restore' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('D O C T O R  R E S U L T')
            ->and($output)->toContain('S U M M A R Y')
            ->and($output)->toContain('Proxy routes')
            ->and($output)->toContain('ACTION')
            ->and($output)->toContain('┌')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('proxy.route_missing')
            ->and($output)->toContain('completed');
    });

    it('dry-runs restore mode without applying fixers', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $exitCode = Artisan::call('doctor', [
            '--node' => 'app-1',
            '--family' => ['proxy'],
            '--restore' => true,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $doctor = $payload['success']['data']['doctor'];

        expect($exitCode)->toBe(0)
            ->and($doctor['dry_run'])->toBeTrue()
            ->and($doctor['summary']['issues'])->toBe(1)
            ->and($doctor['summary']['fixed'])->toBe(0)
            ->and($doctor['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'key' => 'proxy.route_missing',
                'mode' => 'restore',
                'status' => 'planned',
            ]);
    });

    it('rejects mutually exclusive resolution flags before probes', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--fix' => true, '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['fields'])->toBe(['fix', 'restore']);
    });

    it('rejects dry-run without a non-interactive resolution mode', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--dry-run' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('--dry-run requires --restore or --adopt.');
    });

    it('rejects unsupported families before probes', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['cloudflare'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('scope_not_found')
            ->and($payload['error']['meta']['family'])->toBe('cloudflare');
    });

    it('keeps security rejected as a family', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['security'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('scope_not_found')
            ->and($payload['error']['meta']['family'])->toBe('security');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        createDoctorLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RunDoctorRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'doctor' => [
                            'healthy' => true,
                            'mode' => 'verify',
                            'scope' => ['families' => ['node'], 'node' => null, 'self' => false, 'app' => null, 'workspace' => null],
                            'summary' => ['issues' => 0, 'fixed' => 0, 'adopted' => 0, 'skipped' => 0, 'conflicts' => 0],
                            'issues' => [],
                            'actions' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('doctor', ['--family' => ['node'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['healthy'])->toBeTrue();
    });

    it('uses the local default node for non-gateway doctor restore requests', function (): void {
        createDoctorLocalNode('control');
        LocalNodeDefault::query()->create(['default_node_name' => 'beast']);

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            FixDoctorRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'doctor' => [
                            'healthy' => true,
                            'mode' => 'restore',
                            'scope' => ['families' => ['workspace'], 'node' => 'beast', 'self' => false, 'app' => null, 'workspace' => null],
                            'summary' => ['issues' => 0, 'fixed' => 1, 'adopted' => 0, 'skipped' => 0, 'conflicts' => 0],
                            'issues' => [],
                            'actions' => [[
                                'family' => 'workspace',
                                'node' => 'beast',
                                'key' => 'workspace.fpm_config_mismatch',
                                'mode' => 'restore',
                                'status' => 'completed',
                                'summary' => 'Re-applied workspace PHP-FPM pool.',
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('doctor', [
            '--family' => ['workspace'],
            '--restore' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['node'])->toBe('beast');

        $mock->assertSent(fn (FixDoctorRequest $request): bool => $request->node === 'beast'
            && $request->self === false
            && $request->families === ['workspace']
            && $request->mode === 'restore');
    });

    it('reports app family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("docs\t0\t0\t1\t1\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and(collect($payload['error']['data']['doctor']['issues'])->firstWhere('key', 'app.path_missing'))->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.path_missing',
                'kind' => 'missing',
            ]);
    });

    it('reports workspace family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
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
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("feature\t0\t0\t1\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['workspace'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'workspace',
                'node' => 'app-1',
                'key' => 'workspace.path_missing',
                'kind' => 'missing',
            ]);
    });

    it('reports process family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $appNode->id,
            'path' => '/home/orbit/apps/docs',
        ]);
        Process::factory()->create([
            'app_id' => $app->id,
            'name' => 'queue',
        ]);
        app()->instance(RemoteShell::class, new DoctorSequenceRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
        ]));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['process'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_backend_unavailable',
                'kind' => 'unverifiable',
            ]);
    });

    it('rejects the proxy family when targeting a gateway node', function (): void {
        createDoctorLocalNode('gateway');

        $exitCode = Artisan::call('doctor', ['--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('family_not_in_role_scope')
            ->and($payload['error']['meta']['family'])->toBe('proxy');
    });

    it('reports proxy family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.route_missing',
                'kind' => 'missing',
            ]);
    });

    it('lets restore mode complete supported proxy actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.route_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('names the affected proxy route in interactive doctor prompts', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(
            perRouteStdout: "1\t".str_repeat('b', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
            nodeLevelStdout: '',
        ));

        $this->artisan('doctor --node=app-1 --family=proxy --fix')
            ->expectsQuestion('Resolve proxy issue proxy.route_mismatch for vite.docs.test on app-1?', 'restore')
            ->assertExitCode(0);
    });

    it('lets restore mode complete supported proxy TLS actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $route->forceFill(['source_hash' => app(ProxyRouteRenderer::class)->sourceHash($route)])->save();
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: "1\t{$route->source_hash}\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t0\t1\n", nodeLevelStdout: ''));
        app()->instance(OrbitCaService::class, new DoctorProxyFakeCa);

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.tls_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets restore mode complete ingress proxy route actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $edge = createDoctorIngressNode('edge-1');
        $router = createDoctorRouterNode('gateway-1');
        $backend = createDoctorProductionBackendNode('web-1');
        $app = App::factory()->create([
            'node_id' => $backend->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $backendHash = str_repeat('b', 64);
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => $backendHash,
                ]],
            ],
        ]);
        $shell = new DoctorProxyRemoteShell(perNodeStdout: [
            'route_edge-1' => "0\t\t\t\t0\t0\n",
            'route_gateway-1' => "1\t".str_repeat('c', 64)."\t\t\t0\t0\n",
            'route_web-1' => "1\t{$backendHash}\t\t\t0\t0\n",
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $exitCode = Artisan::call('doctor', ['--node' => 'edge-1', '--family' => ['proxy'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'edge-1',
                'key' => 'proxy.public_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->nodesForScripts())->toContain('edge-1')
            ->and(implode("\n", $shell->scripts))->toContain('/etc/caddy/sites/docs.test.caddy');
    });

    it('lets restore mode complete private backend proxy route actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $edge = createDoctorIngressNode('edge-1');
        $router = createDoctorRouterNode('gateway-1');
        $backend = createDoctorProductionBackendNode('web-1');
        $app = App::factory()->create([
            'node_id' => $backend->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new DoctorProxyRemoteShell(perNodeStdout: [
            'route_edge-1' => "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/docs.test.crt\t/etc/orbit/certs/docs.test.key\t1\t1\n",
            'route_gateway-1' => "1\t".str_repeat('c', 64)."\t\t\t0\t0\n",
            'route_web-1' => "0\t\t\t\t0\t0\n",
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $exitCode = Artisan::call('doctor', ['--node' => 'edge-1', '--family' => ['proxy'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'web-1',
                'key' => 'proxy.backend_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->nodesForScripts())->toContain('web-1')
            ->and(implode("\n", $shell->scripts))->toContain('/etc/caddy/sites/docs.test.backend.caddy');
    });

    it('reports proxy route_extra drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
            nodeLevelStdout: "extra.test\t".str_repeat('b', 64)."\t/etc/orbit/certs/extra.test.crt\t/etc/orbit/certs/extra.test.key\t1\t1\n",
        ));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'extra.test',
                'kind' => 'extra',
            ]);
    });

    it('lets restore mode remove extra proxy routes through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('a', 64),
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
            nodeLevelStdout: "extra.test\t".str_repeat('b', 64)."\t/etc/orbit/certs/extra.test.crt\t/etc/orbit/certs/extra.test.key\t1\t1\n",
        ));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'extra.test',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets adopt mode create custom proxy intent from observed routes', function (): void {
        ProxyRoute::query()->delete();
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $body = base64_encode("adopted.test {\n    reverse_proxy localhost:8080\n}\n");
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(
            perRouteStdout: '',
            nodeLevelStdout: '',
            perNodeStdout: [
                'node_app-1' => "adopted.test\t".str_repeat('a', 64)."\t{$body}\n",
            ],
        ));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['proxy'], '--adopt' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('adopt')
            ->and($payload['success']['data']['doctor']['summary']['adopted'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'adopted.test',
                'mode' => 'adopt',
                'status' => 'created',
            ]);

        $adopted = ProxyRoute::query()->where('domain', 'adopted.test')->first();

        expect($adopted)->not->toBeNull()
            ->and($adopted->owner_type)->toBe('custom')
            ->and($adopted->kind)->toBe('proxy');
    });

    it('reports tool family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        NodeTool::factory()->create(['node_id' => $appNode->id, 'name' => 'redis']);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: '', exitCode: 1));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'kind' => 'missing',
            ]);
    });

    it('lets restore mode complete supported tool lifecycle actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'caddy',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("/usr/bin/caddy\t2.8.4\tstopped\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.lifecycle_state_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets restore mode complete supported tool version actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'composer',
            'expected_version' => '3.0',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("/usr/local/bin/composer\tComposer version 2.8.0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.version_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets restore mode complete supported tool config actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $content = "port 6379\n";
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'redis',
            'config' => [
                'managed_config' => [
                    'path' => '/etc/redis/redis.conf',
                    'hash' => hash('sha256', $content),
                    'content' => $content,
                ],
            ],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("/usr/bin/redis-server\t7.2.0\trunning\t0\t\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.config_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets restore mode complete supported tool credential actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $secret = 'generated-password';
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'opencode-server',
            'credentials' => [
                'managed_secret' => [
                    'path' => '/home/orbit/.config/opencode-server/password',
                    'hash' => hash('sha256', $secret),
                    'content' => $secret,
                ],
            ],
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("/usr/bin/opencode-server\t\trunning\t\t\t0\t\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.credentials_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets restore mode install missing tools through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        NodeTool::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'redis',
            'expected_state' => 'running',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: '', exitCode: 1));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['tool'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('runs the schedule family locally for gateway callers', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $app = App::factory()->create(['node_id' => $appNode->id]);
        Schedule::factory()->forApp($app)->create();
        SchedulerState::factory()->create([
            'node_id' => $appNode->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("running=true\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=true\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['schedule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['schedule'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('reports schedule family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1');
        $app = App::factory()->create(['node_id' => $appNode->id]);
        Schedule::factory()->forApp($app)->create();
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell(perRouteStdout: '', exitCode: 1));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['schedule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'app-1',
                'key' => 'schedule.target_unreachable',
                'kind' => 'missing',
            ]);
    });

    it('lets restore mode complete supported schedule scheduler actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway');
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("running=true\nrestart_policy=unless-stopped\nscheduler_running=false\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'local-gateway', '--family' => ['schedule'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'schedule',
                'node' => 'local-gateway',
                'key' => 'schedule.scheduler_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('runs the firewall rule family for an app node target', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);
        createDoctorHostedAppNode('app-1', ['platform' => 'ubuntu']);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['firewall_rule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['families'])->toBe(['firewall_rule'])
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0);
    });

    it('reports firewall rule family drift through the global doctor payload', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);
        $appNode = createDoctorHostedAppNode('app-1', ['platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'public-ssh',
            'direction' => 'incoming',
            'action' => 'allow',
            'source' => 'any',
            'destination' => null,
            'port' => '22',
            'protocol' => 'tcp',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['firewall_rule'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('drift_detected')
            ->and($payload['error']['data']['doctor']['issues'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'key' => 'firewall_rule.baseline_conflict',
                'kind' => 'divergent',
            ]);
    });

    it('lets restore mode complete supported firewall actions through family dispatch', function (): void {
        createDoctorLocalNode('gateway')->update(['platform' => 'ubuntu']);
        $appNode = createDoctorHostedAppNode('app-1', ['platform' => 'ubuntu']);
        FirewallRule::factory()->create([
            'node_id' => $appNode->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
        ]);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['firewall_rule'], '--restore' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('restore')
            ->and($payload['success']['data']['doctor']['summary']['fixed'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'key' => 'firewall_rule.rule_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ]);
    });

    it('lets adopt mode create registry records from observed backend rules', function (): void {
        createDoctorLocalNode('gateway');
        $appNode = createDoctorHostedAppNode('app-1', ['platform' => 'ubuntu']);
        app()->instance(RemoteShell::class, new DoctorProxyRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n[ 1] 5173/tcp                   ALLOW IN    10.6.0.0/24\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-1', '--family' => ['firewall_rule'], '--adopt' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['mode'])->toBe('adopt')
            ->and($payload['success']['data']['doctor']['summary']['adopted'])->toBe(1)
            ->and($payload['success']['data']['doctor']['summary']['issues'])->toBe(0)
            ->and($payload['success']['data']['doctor']['actions'][0])->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'app-1',
                'key' => 'incoming:allow:10.6.0.0/24:any:5173:tcp:v4:any',
                'mode' => 'adopt',
                'status' => 'created',
            ]);

        $adopted = FirewallRule::query()->where('node_id', $appNode->id)->first();

        expect($adopted)->not->toBeNull()
            ->and($adopted->name)->toBe('incoming-allow-5173-tcp')
            ->and($adopted->source)->toBe('10.6.0.0/24')
            ->and($adopted->port)->toBe('5173');
    });
});

final class DoctorProxyRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  array<string, string>  $perNodeStdout
     */
    public function __construct(
        private readonly string $perRouteStdout = '',
        private readonly string $nodeLevelStdout = '',
        private readonly array $perNodeStdout = [],
        private readonly int $exitCode = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        $isNodeLevel = str_contains($script, '/etc/caddy/sites/*.caddy');
        $nodeKey = $isNodeLevel ? 'node_'.$node->name : 'route_'.$node->name;
        $stdout = $this->perNodeStdout[$nodeKey] ?? ($isNodeLevel ? $this->nodeLevelStdout : $this->perRouteStdout);

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $stdout, stderr: '', durationMs: 1);
    }

    /**
     * @return list<string>
     */
    public function nodesForScripts(): array
    {
        return array_map(fn (Node $node): string => $node->name, $this->nodes);
    }
}

final class DoctorSequenceRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final readonly class DoctorProxyFakeCa extends OrbitCaService
{
    /** @return array{cert: string, key: string} */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-doctor-proxy-ca';

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
