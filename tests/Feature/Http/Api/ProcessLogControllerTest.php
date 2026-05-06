<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_LOG_CALLER_WG_IP = '10.6.0.94';

function createProcessLogCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => PROCESS_LOG_CALLER_WG_IP,
        'wireguard_address' => PROCESS_LOG_CALLER_WG_IP,
    ], $overrides));
}

function grantProcessLogAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessLogController', function (): void {
    it('returns bounded logs for authorized control callers', function (): void {
        $caller = createProcessLogCallerNode();
        $appNode = Node::factory()->create(['role' => 'app']);
        grantProcessLogAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessLogApiRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "Vite ready\n", stderr: '', durationMs: 1),
        ]));

        $response = $this->call('GET', '/api/processes/vite/log', [
            'app' => 'docs',
            'lines' => 5,
        ], [], [], ['REMOTE_ADDR' => PROCESS_LOG_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.logs.runtime_unit', 'orbit_docs_main_vite')
            ->assertJsonPath('success.data.logs.lines.0.message', 'Vite ready')
            ->assertJsonPath('success.meta.line_count', 1);
    });

    it('requires authorization before log reads', function (): void {
        createProcessLogCallerNode();
        $appNode = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        $remoteShell = new ProcessLogApiRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('GET', '/api/processes/vite/log', [
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_LOG_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect($remoteShell->scripts)->toBe([]);
    });

    it('returns log read failures as gateway errors', function (): void {
        createProcessLogCallerNode(['role' => 'gateway']);
        $appNode = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessLogApiRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing', durationMs: 1),
        ]));

        $response = $this->call('GET', '/api/processes/vite/log', [
            'app' => 'docs',
        ], [], [], ['REMOTE_ADDR' => PROCESS_LOG_CALLER_WG_IP]);

        $response->assertStatus(502)
            ->assertJsonPath('error.code', 'process.log_read_failed');
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
