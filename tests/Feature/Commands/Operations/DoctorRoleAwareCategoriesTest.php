<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
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
    return Node::factory()->create([
        'name' => $name,
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'linux',
        'environment' => $role === 'app' ? 'development' : null,
        'is_local' => true,
    ]);
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

        $exitCode = Artisan::call('doctor', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['doctor']['scope']['node'])->toBe('local-gateway');
    });

    it('exposes only the node family for a control target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('control'))->toBe(['node']);
    });

    it('exposes only the node family for a gateway target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('gateway'))->toBe(['node']);
    });

    it('exposes the full app-node category set for an app target role', function (): void {
        $runner = app(DoctorReportRunner::class);

        expect($runner->categoriesForRole('app'))->toBe([
            'node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule',
        ]);
    });

    it('runs only the node family for a local gateway target', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');

        Artisan::call('doctor', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect(roleAwareDoctorScope($payload)['families'])->toBe(['node']);
    });

    it('rejects a family outside the target role category set before probes', function (): void {
        createRoleAwareLocalNode('control', 'local-control');

        $exitCode = Artisan::call('doctor', ['--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('family_not_in_role_scope')
            ->and($payload['error']['meta'])->toMatchArray([
                'family' => 'app',
                'target_role' => 'control',
            ]);
    });

    it('renders categories derived from the target role rather than the full family list', function (): void {
        createRoleAwareLocalNode('control', 'local-control');

        Artisan::call('doctor');
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

    it('uses a single Issue column for node family issue tables', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway')->update(['platform' => null]);

        $exitCode = Artisan::call('doctor');
        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Node')
            ->and($output)->toContain('ISSUE')
            ->and($output)->not->toContain('KEY ')
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
        $appA = Node::factory()->create(['name' => 'app-a', 'role' => 'app', 'status' => 'active']);
        $appB = Node::factory()->create(['name' => 'app-b', 'role' => 'app', 'status' => 'active']);
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
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell("docs-a\t0\t0\t1\t1\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['app'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issueNodes = array_column($payload['error']['data']['doctor']['issues'], 'node');

        expect($issueNodes)->each->toBe('app-a')
            ->and($issueNodes)->not->toContain('app-b');
    });

    it('scopes proxy family probes to the target node only', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appA = Node::factory()->create(['name' => 'app-a', 'role' => 'app', 'status' => 'active']);
        $appB = Node::factory()->create(['name' => 'app-b', 'role' => 'app', 'status' => 'active']);
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

    it('scopes workspace family probes to the target node only', function (): void {
        createRoleAwareLocalNode('gateway', 'local-gateway');
        $appA = Node::factory()->create(['name' => 'app-a', 'role' => 'app', 'status' => 'active']);
        $appB = Node::factory()->create(['name' => 'app-b', 'role' => 'app', 'status' => 'active']);
        $apA = App::factory()->create(['name' => 'docs-a', 'node_id' => $appA->id, 'path' => '/home/orbit/apps/docs-a']);
        $apB = App::factory()->create(['name' => 'docs-b', 'node_id' => $appB->id, 'path' => '/home/orbit/apps/docs-b']);
        Workspace::factory()->create(['app_id' => $apA->id, 'name' => 'feature', 'path' => '/home/orbit/apps/docs/workspaces/feature']);
        Workspace::factory()->create(['app_id' => $apB->id, 'name' => 'feature', 'path' => '/home/orbit/apps/docs/workspaces/feature']);
        app()->instance(RemoteShell::class, new RoleAwareDoctorRemoteShell("feature\t0\t0\t1\t0\t0\n"));

        $exitCode = Artisan::call('doctor', ['--node' => 'app-a', '--family' => ['workspace'], '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1);

        $issueNodes = array_column($payload['error']['data']['doctor']['issues'], 'node');

        expect($issueNodes)->each->toBe('app-a');
    });
});

final class RoleAwareDoctorRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $perRouteStdout = '',
        private readonly string $nodeLevelStdout = '',
        private readonly int $exitCode = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $isNodeLevel = str_contains($script, '/etc/caddy/sites/*.caddy');
        $stdout = $isNodeLevel ? $this->nodeLevelStdout : $this->perRouteStdout;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $stdout, stderr: '', durationMs: 1);
    }
}
