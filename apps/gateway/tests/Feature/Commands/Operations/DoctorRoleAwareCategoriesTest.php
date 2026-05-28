<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\SchedulerState;
use App\Models\Workspace;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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

function createRoleAwareLocalNode(string $role, string $name = 'local-node'): Node
{
    config(['orbit.is_gateway' => $role === 'gateway']);

    $node = Node::factory()->create([
        'name' => $name,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'linux',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);

        SchedulerState::factory()->create([
            'node_id' => $node->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);
    }

    if ($role === 'app-dev') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
    }

    return $node;
}

function createRoleAwareAppHostNode(string $name): Node
{
    $node = Node::factory()->create(['name' => $name, 'status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function createRoleAwareProductionAppHostNode(string $name): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

function createRoleAwareIngressNode(string $name): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function roleAwareDoctorScope(array $payload): array
{
    $doctor = $payload['success']['data']['doctor']
        ?? $payload['error']['data']['doctor']
        ?? [];

    return is_array($doctor['scope'] ?? null) ? $doctor['scope'] : [];
}

describe('doctor role-aware categories', function (): void {
    it('defaults the target to the local node when no flags are supplied', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell);

        $exitCode = Artisan::call('doctor', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['node'])->toBe('local-gateway');
    });

    it('exposes only the node family for an operator target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('operator'))->toBe(['node']);
    });

    it('exposes node and schedule families for a gateway target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('gateway'))->toBe(['node', 'schedule']);
    });

    it('exposes the full app-node category set for an app-dev target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('app-dev'))->toBe([
            'node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection',
        ]);
    });

    it('keeps workspace in the app-dev category set only', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('app-dev'))->toBe([
            'node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection',
        ])
            ->and($runner->categoriesForRole('app-prod'))->toBe([
                'node', 'app', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection',
            ]);
    });

    it('exposes ingress-specific categories for ingress target roles', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('ingress'))->toBe(['node', 'proxy', 'firewall_rule', 'tool']);
    });

    it('derives hosted category sets from active role assignments only', function (): void {
        $runner = app(DoctorReportRunner::class);
        $appHost = createRoleAwareAppHostNode('app-host');
        $operatorOnly = Node::factory()->create(['name' => 'operator-only', 'status' => 'active']);

        expect($runner->categoriesForNode($appHost))->toBe([
            'node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection',
        ])
            ->and($runner->categoriesForNode($operatorOnly))->toBe(['node']);
    });

    it('omits workspace from production app host category sets', function (): void {
        $runner = app(DoctorReportRunner::class);
        $productionAppHost = createRoleAwareProductionAppHostNode('app-prod');

        expect($runner->categoriesForNode($productionAppHost))->toBe([
            'node', 'app', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection',
        ]);
    });

    it('derives database-only targets as node and tool categories', function (): void {
        $runner = app(DoctorReportRunner::class);
        $databaseNode = Node::factory()->create(['name' => 'db-1', 'status' => 'active']);

        NodeRoleAssignment::factory()->create([
            'node_id' => $databaseNode->id,
            'role' => 'database',
            'status' => 'active',
            'settings' => [],
        ]);

        expect($runner->categoriesForNode($databaseNode))->toBe(['node', 'tool']);
    });

    it('derives ingress targets as node proxy firewall and tool categories', function (): void {
        $runner = app(DoctorReportRunner::class);
        $ingressNode = createRoleAwareIngressNode('edge-1');

        expect($runner->categoriesForNode($ingressNode))->toBe(['node', 'proxy', 'firewall_rule', 'tool']);
    });

    it('runs node and schedule families for a local gateway target', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell);

        Artisan::call('doctor', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect(roleAwareDoctorScope($payload)['families'])->toBe(['node', 'schedule']);
    });

    it('rejects a family outside the target role category set before probes', function (): void {
        createRoleAwareLocalNode('operator', 'local-operator');
        config(['orbit.is_gateway' => true]);

        $exitCode = Artisan::call('doctor', ['--node' => 'local-operator', '--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('family_not_in_role_scope')
            ->and($payload['error']['meta'])->toMatchArray([
                'family' => 'app',
                'target_role' => 'operator',
            ]);
    });

    it('rejects hosted families for operator targets before probes', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        Node::factory()->create([
            'name' => 'operator-only',
            'status' => 'active',
            'host' => '10.6.0.2',
            'wireguard_address' => '10.6.0.2',
            'platform' => 'linux',
        ]);
        config(['orbit.is_gateway' => true]);

        $exitCode = Artisan::call('doctor', ['--node' => 'operator-only', '--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('family_not_in_role_scope')
            ->and($payload['error']['meta'])->toMatchArray([
                'family' => 'app',
                'target_role' => 'operator',
                'allowed_families' => ['node'],
            ]);
    });

    it('rejects workspace for production app hosts before probes', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        createRoleAwareProductionAppHostNode('app-prod');
        config(['orbit.is_gateway' => true]);

        $exitCode = Artisan::call('doctor', ['--node' => 'app-prod', '--family' => ['workspace'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('family_not_in_role_scope')
            ->and($payload['error']['meta'])->toMatchArray([
                'family' => 'workspace',
                'target_role' => 'app-prod',
                'allowed_families' => ['node', 'app', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule', 'database_connection'],
            ]);
    });

    it('renders categories derived from the target role rather than the full family list', function (): void {
        createRoleAwareLocalNode('operator', 'local-operator');
        config(['orbit.is_gateway' => true]);

        Artisan::call('doctor', ['--node' => 'local-operator']);
        $output = Artisan::output();

        expect($output)->toContain('Node')
            ->and($output)->not->toContain('Nodes')
            ->and($output)->not->toContain('Apps')
            ->and($output)->not->toContain('Workspaces')
            ->and($output)->not->toContain('Processes')
            ->and($output)->not->toContain('Proxy routes')
            ->and($output)->not->toContain('Firewall')
            ->and($output)->not->toContain('Tools')
            ->and($output)->not->toContain('Scheduling');
    });

    it('renders the node family issue table as a dashed list without column headers', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway')->update(['platform' => null]);
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell);

        $exitCode = Artisan::call('doctor');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Node')
            ->and($output)->toMatch('/│\s+- /')
            ->and($output)->not->toContain('KEY ')
            ->and($output)->not->toContain('| ISSUE ')
            ->and($output)->not->toContain('node.record_incomplete');
    });

    it('omits the across N categories phrase from the summary line', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway')->update(['platform' => null]);

        $exitCode = Artisan::call('doctor');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->not->toContain('across 1 category')
            ->and($output)->not->toContain('across 1 categories')
            ->and($output)->not->toContain('across 2 categories');
    });

    it('scopes app family probes to the target node only', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appA = createRoleAwareAppHostNode('app-a');
        $appB = createRoleAwareAppHostNode('app-b');
        App::factory()->create([
            'name' => 'docs-a',
            'node_id' => $appA->id,
            'path' => '/home/orbit/apps/docs-a',
            'document_root' => 'public',
        ]);
        App::factory()->create([
            'name' => 'docs-b',
            'node_id' => $appB->id,
            'path' => '/home/orbit/apps/docs-b',
            'document_root' => 'public',
        ]);
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell("docs-a\t0\t0\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issueNodes = array_column($payload['error']['data']['doctor']['issues'], 'node');

        expect($issueNodes)->each->toBe('app-a')
            ->and($issueNodes)->not->toContain('app-b');
    });

    it('scopes proxy family probes to the target node only', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appA = createRoleAwareAppHostNode('app-a');
        $appB = createRoleAwareAppHostNode('app-b');
        ProxyRoute::factory()->create([
            'node_id' => $appA->id,
            'domain' => 'a.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appB->id,
            'domain' => 'b.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5174'],
        ]);
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell(perRouteStdout: "0\t\t\t\t0\t0\n", nodeLevelStdout: ''));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issueNodes = array_column($payload['error']['data']['doctor']['issues'], 'node');

        expect($issueNodes)->each->toBe('app-a')
            ->and($issueNodes)->not->toContain('app-b');
    });

    it('reports proxy.caddy_container_down when orbit-caddy is stopped on an app host serving proxy routes', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appHost = createRoleAwareAppHostNode('app-a');
        App::factory()->create([
            'name' => 'docs-a',
            'node_id' => $appHost->id,
            'path' => '/home/orbit/apps/docs-a',
            'document_root' => 'public',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appHost->id,
            'domain' => 'docs-a.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new RoleAwareDoctorRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/docs-a.test.crt\t/etc/orbit/certs/docs-a.test.key\t1\t1\n",
            caddyContainerStdout: "available\ttrue\tfalse\n",
        );
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issues = $payload['error']['data']['doctor']['issues'];
        $caddyIssue = collect($issues)->firstWhere('key', 'proxy.caddy_container_down');

        expect($caddyIssue)->not->toBeNull()
            ->and($caddyIssue['node'])->toBe('app-a');
    });

    it('probes orbit-caddy on ingress-only nodes (not just gateway/app hosts)', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $edge = createRoleAwareIngressNode('edge-1');
        ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://10.6.0.2:80'], 'upstream' => 'http://10.6.0.2:80'],
        ]);
        $shell = new RoleAwareDoctorRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/docs.test.crt\t/etc/orbit/certs/docs.test.key\t1\t1\n",
            caddyContainerStdout: "available\ttrue\tfalse\n",
        );
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('doctor', ['--node' => 'edge-1', '--family' => ['proxy'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issues = $payload['error']['data']['doctor']['issues'];
        $caddyIssue = collect($issues)->firstWhere('key', 'proxy.caddy_container_down');

        expect($caddyIssue)->not->toBeNull()
            ->and($caddyIssue['node'])->toBe('edge-1');
    });

    it('restores a stopped orbit-caddy container through doctor --restore (not a skipped action)', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appHost = createRoleAwareAppHostNode('app-a');
        ProxyRoute::factory()->create([
            'node_id' => $appHost->id,
            'domain' => 'docs-a.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new RoleAwareDoctorRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/docs-a.test.crt\t/etc/orbit/certs/docs-a.test.key\t1\t1\n",
            caddyContainerStdout: "available\ttrue\tfalse\n",
        );
        app()->instance(RemoteShell::class, $shell);

        Artisan::call('doctor', [
            '--node' => 'app-a',
            '--family' => ['proxy'],
            '--restore' => true,
            '--key' => 'proxy.caddy_container_down',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $doctor = $payload['success']['data']['doctor'] ?? $payload['error']['data']['doctor'];
        $caddyAction = collect($doctor['actions'])->firstWhere('key', 'proxy.caddy_container_down');

        expect($caddyAction)->not->toBeNull()
            ->and($caddyAction['status'])->toBe('completed')
            ->and($caddyAction['status'])->not->toBe('skipped');
    });

    it('does not probe host caddy.service during a proxy doctor run because orbit-caddy is the runtime', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appHost = createRoleAwareAppHostNode('app-a');
        ProxyRoute::factory()->create([
            'node_id' => $appHost->id,
            'domain' => 'docs-a.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new RoleAwareDoctorRemoteShell(
            perRouteStdout: "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/docs-a.test.crt\t/etc/orbit/certs/docs-a.test.key\t1\t1\n",
        );
        app()->instance(RemoteShell::class, $shell);

        Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['proxy'], '--json' => true]);

        foreach ($shell->scripts as $script) {
            expect($script)->not->toContain('systemctl status caddy.service')
                ->and($script)->not->toContain('systemctl is-active caddy')
                ->and($script)->not->toContain('systemctl reload caddy');
        }
    });

    it('scopes workspace family probes to the target node only', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appA = createRoleAwareAppHostNode('app-a');
        $appB = createRoleAwareAppHostNode('app-b');
        $apA = App::factory()->create(['name' => 'docs-a', 'node_id' => $appA->id, 'path' => '/home/orbit/apps/docs-a']);
        $apB = App::factory()->create(['name' => 'docs-b', 'node_id' => $appB->id, 'path' => '/home/orbit/apps/docs-b']);
        Workspace::factory()->create(['app_id' => $apA->id, 'name' => 'feature', 'path' => '/home/orbit/apps/docs-a/.worktrees/feature']);
        Workspace::factory()->create(['app_id' => $apB->id, 'name' => 'feature', 'path' => '/home/orbit/apps/docs-b/.worktrees/feature']);
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell("feature\t0\t1\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['workspace'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issueNodes = array_column($payload['error']['data']['doctor']['issues'], 'node');

        expect($issueNodes)->each->toBe('app-a');
    });
});

final class RoleAwareDoctorRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function __construct(
        private readonly string $perRouteStdout = '',
        private readonly string $nodeLevelStdout = '',
        private readonly int $exitCode = 0,
        private readonly string $caddyContainerStdout = "available\ttrue\ttrue\n",
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, 'docker inspect') && str_contains($script, 'orbit-runtime')) {
            return new RemoteShellResult(exitCode: 0, stdout: "running=true\nrestart_policy=unless-stopped\nenv=ORBIT_IS_GATEWAY=1\nscheduler_running=true\n", stderr: '', durationMs: 1);
        }

        if (
            str_contains($script, 'docker container ls')
            || str_contains($script, "dir='/etc/orbit/apps'")
        ) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(exitCode: 0, stdout: $this->caddyContainerStdout, stderr: '', durationMs: 1);
        }

        $isNodeLevel = str_contains($script, '/etc/caddy/sites/*.caddy');
        $stdout = $isNodeLevel ? $this->nodeLevelStdout : $this->perRouteStdout;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $stdout, stderr: '', durationMs: 1);
    }
}
