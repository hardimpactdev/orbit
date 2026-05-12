<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

const UPDATE_ALL_CALLER_WG_IP = '10.6.0.99';

beforeEach(function (): void {
    Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'host' => 'gateway',
        'ssh_user' => 'gateway',
        'orbit_path' => '/home/gateway/orbit',
        'status' => 'active',
        'is_local' => true,
        'wireguard_address' => UPDATE_ALL_CALLER_WG_IP,
    ]);
});

it('updates local checkout and returns updates array for gateway caller', function (): void {
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.target', 'gateway');
    $response->assertJsonPath('success.data.updates.0.node', 'gateway');
    $response->assertJsonPath('success.data.updates.0.role', 'gateway');
    $response->assertJsonPath('success.data.updates.0.status', 'completed');
    $response->assertJsonPath('success.meta.summary.total', 1);
    $response->assertJsonPath('success.meta.summary.completed', 1);
    $response->assertJsonPath('success.meta.summary.failed', 0);
});

it('returns local_update_failed when git pull fails', function (): void {
    Process::fake([
        'git pull --ff-only' => Process::result(
            output: '',
            errorOutput: 'merge conflict',
            exitCode: 1,
        ),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'local_update_failed');
    $response->assertJsonPath('error.data.output', 'merge conflict');
    $response->assertJsonPath('error.meta.failed_step', 'local_checkout');
});

it('includes app nodes in updates and uses RemoteShell', function (): void {
    Node::factory()->create([
        'name' => 'beast',
        'role' => 'app',
        'host' => 'beast',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $shell = new UpdateAllControllerRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.1.target', 'beast');
    $response->assertJsonPath('success.data.updates.1.status', 'completed');

    expect(array_map(fn (Node $node): string => $node->name, $shell->nodes))->toBe([
        'beast',
        'beast',
        'beast',
    ]);
});

it('excludes control nodes from remote updates', function (): void {
    Node::factory()->create([
        'name' => 'mini',
        'role' => 'control',
        'host' => 'mini',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/Users/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    $shell = new UpdateAllControllerRemoteShell;
    app()->instance(RemoteShell::class, $shell);

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.target', 'gateway');

    expect($shell->nodes)->toHaveCount(0);
});

it('reports remote_update_failed when an app node fails', function (): void {
    Node::factory()->create([
        'name' => 'beast',
        'role' => 'app',
        'host' => 'beast',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell(exitCode: 255, stderr: 'Permission denied'));

    $response = $this->call('POST', '/api/update/all', [], [], [], ['REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP]);

    $response->assertOk();
    $response->assertJsonPath('success.data.updates.0.status', 'completed');
    $response->assertJsonPath('success.data.updates.1.status', 'failed');
    $response->assertJsonPath('success.data.updates.1.output', 'Permission denied');
    $response->assertJsonPath('success.meta.summary.total', 2);
    $response->assertJsonPath('success.meta.summary.completed', 1);
    $response->assertJsonPath('success.meta.summary.failed', 1);
});

it('rejects unauthenticated requests', function (): void {
    $this->call('POST', '/api/update/all')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('streams progress events for gateway-owned update targets', function (): void {
    Node::factory()->create([
        'name' => 'beast',
        'role' => 'app',
        'host' => 'beast',
        'ssh_user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
    ]);

    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 0),
    ]);
    Process::preventStrayProcesses();

    app()->instance(RemoteShell::class, new UpdateAllControllerRemoteShell);

    $response = $this->call(
        'POST',
        '/api/update/all',
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => UPDATE_ALL_CALLER_WG_IP,
        ],
    );

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
    $response->assertHeader('X-Accel-Buffering', 'no');
    $content = $response->streamedContent();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('"key":"gateway"')
        ->and($content)->toContain('"label":"Pulling source - gateway"')
        ->and($content)->toContain('"key":"beast"')
        ->and($content)->toContain('"status":"pulling_source"')
        ->and($content)->toContain('"status":"installing_dependencies"')
        ->and($content)->toContain('"status":"running_migrations"')
        ->and($content)->toContain('"status":"done"')
        ->and($content)->toContain('event: complete');
});

final class UpdateAllControllerRemoteShell implements RemoteShell
{
    public array $nodes = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;

        return new RemoteShellResult(
            exitCode: $this->exitCode,
            stdout: '',
            stderr: $this->stderr,
            durationMs: 1,
        );
    }
}
