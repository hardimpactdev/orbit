<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

const DOCTOR_RUN_CALLER_WG_IP = '10.6.0.94';

function createDoctorRunCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'gateway',
        'host' => DOCTOR_RUN_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_CALLER_WG_IP,
        'platform' => 'ubuntu',
    ], $overrides));
}

describe('DoctorRunController', function (): void {
    it('runs verify mode and returns a doctor report', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux', 'is_local' => true]);

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['node'],
            'mode' => 'verify',
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['node']);
    });

    it('accepts the proxy family scope', function (): void {
        createDoctorRunCallerNode(['platform' => 'linux', 'is_local' => true]);

        $response = $this->call('POST', '/api/doctor/run', [
            'families' => ['proxy'],
            'mode' => 'verify',
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', true)
            ->assertJsonPath('success.data.doctor.scope.families', ['proxy']);
    });

    it('rejects unauthenticated requests', function (): void {
        $response = $this->postJson('/api/doctor/run', ['families' => ['node']]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('passes fix mode through to the doctor runner', function (): void {
        createDoctorRunCallerNode();
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $appNode->id, 'name' => 'local-vite', 'source' => '10.6.0.0/24', 'port' => '5173']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("Status: active\n\n     To                         Action      From\n     --                         ------      ----\n"));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'fix',
            'families' => ['firewall_rule'],
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'fix')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('passes proxy fix mode through to the doctor runner', function (): void {
        createDoctorRunCallerNode();
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell("0\t\t\t\t0\t0\n"));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'fix',
            'families' => ['proxy'],
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.mode', 'fix')
            ->assertJsonPath('success.data.doctor.summary.fixed', 1)
            ->assertJsonPath('success.data.doctor.actions.0.status', 'completed');
    });

    it('accepts the tool family scope and returns tool drift', function (): void {
        createDoctorRunCallerNode();
        $appNode = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $appNode->id, 'name' => 'redis']);
        app()->instance(RemoteShell::class, new DoctorRunRemoteShell('', 1));

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'verify',
            'families' => ['tool'],
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.doctor.healthy', false)
            ->assertJsonPath('success.data.doctor.issues.0.key', 'tool.capability_missing');
    });

    it('denies app-node write mode requests', function (): void {
        createDoctorRunCallerNode(['role' => 'app']);

        $response = $this->call('POST', '/api/doctor/run', [
            'mode' => 'adopt',
            'families' => ['firewall_rule'],
        ], [], [], ['REMOTE_ADDR' => DOCTOR_RUN_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed')
            ->assertJsonPath('error.meta.mode', 'adopt');
    });
});

final class DoctorRunRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $stdout,
        private readonly int $exitCode = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(exitCode: $this->exitCode, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}
